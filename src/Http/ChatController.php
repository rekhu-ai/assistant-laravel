<?php

namespace Rekhu\Assistant\Http;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Rekhu\Assistant\Runtime\Turn;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Runtime\Refused;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Protocol 1 of Rekhu Assistant (PROTOCOL.md in @rekhu/assistant-widget):
 * POST {path}/chat { message, conversation?, confirm?, locale? } → server-sent events: start, text, step, card, done or error.
 * GET {path}/chat?conversation=… → what the user saw. DELETE {path}/chat { conversation } starts over.
 * The public chat ({path}/visitor/chat, when guests are on) is the same for visitors who are not signed in: they name
 * themselves with an anonymous id (X-Rekhu-Visitor), reach only the guest actions, and are capped per visitor, per IP
 * address and in total.
 */
class ChatController
{
    public const PROTOCOL = 1;

    public function __invoke(Request $request): StreamedResponse
    {
        $this->speaks($request);
        $visitor = $this->visitor($request);
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:'.($visitor === null ? 4000 : (int) config('rekhu-assistant.guests.max_message', 1000))],
            'conversation' => ['nullable', 'string', 'max:64'],
            'confirm' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ]);
        abort_if(blank($data['message'] ?? null) && blank($data['confirm'] ?? null), 422, 'Say something first.');
        if ($visitor !== null) {
            $this->capped($request, $visitor);
        }
        $conversation = Conversation::open($request->user(), $data['conversation'] ?? null, $visitor);

        return response()->stream(function () use ($request, $conversation, $data, $visitor) {
            $send = function (array $event) {
                echo 'event: '.$event['type']."\ndata: ".json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            $send(['type' => 'start', 'conversation' => $conversation->id, 'protocol' => self::PROTOCOL]);
            try {
                (new Turn($request, $conversation, $send, $visitor))->turn((string) ($data['message'] ?? ''), $data['confirm'] ?? null, $data['locale'] ?? null);
                $send(['type' => 'done']);
            } catch (Throwable $e) {
                $conversation->save();
                $send(['type' => 'error', 'message' => self::explain($e)]);
            }
            // whatever an action flashed or changed in the session is kept for the user's next page (an API has no session)
            if ($request->hasSession()) {
                $request->session()->save();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** GET {path}/chat?conversation=… → what the user saw: their messages and the answers, without the tool traffic. */
    public function history(Request $request)
    {
        $this->speaks($request);
        $conversation = Conversation::open($request->user(), (string) $request->query('conversation'), $this->visitor($request));
        $lines = [];
        foreach ($conversation->messages as $message) {
            if ($message['role'] === 'user' && is_string($message['content']) && ! str_starts_with($message['content'], 'I confirmed "')) {
                $lines[] = ['role' => 'user', 'text' => $message['content']];
            }
            if ($message['role'] === 'assistant') {
                $text = trim(implode("\n", array_map(fn ($b) => $b['text'] ?? '', array_filter((array) $message['content'], fn ($b) => ($b['type'] ?? '') === 'text'))));
                if ($text !== '') {
                    $lines[] = ['role' => 'bot', 'text' => $text];
                }
            }
        }

        return response()->json(['conversation' => $conversation->id, 'messages' => array_slice($lines, -30)]);
    }

    public function forget(Request $request)
    {
        $this->speaks($request);
        Conversation::open($request->user(), (string) $request->input('conversation'), $this->visitor($request))->forget();

        return response()->noContent();
    }

    /** A client asking for another version of the protocol gets a clear 400 instead of events it cannot read. */
    private function speaks(Request $request): void
    {
        $asked = $request->header('X-Rekhu-Protocol');
        abort_if($asked !== null && (int) $asked !== self::PROTOCOL, 400, 'This app speaks Rekhu Assistant protocol '.self::PROTOCOL.'.');
    }

    /**
     * On the public chat: the visitor's anonymous id, and from here on the request has no user, so a signed-in user who
     * lands on it is a visitor too and never acts as themselves. Null on the signed-in chat.
     */
    private function visitor(Request $request): ?string
    {
        if (! ($request->route()?->defaults['rekhu_visitor'] ?? false)) {
            return null;
        }
        $id = (string) $request->header('X-Rekhu-Visitor');
        if (! Str::isUuid($id)) {
            self::refuse(422, 'The public chat needs a visitor id (X-Rekhu-Visitor).');
        }
        $request->setUserResolver(fn () => null);

        return strtolower($id);
    }

    /** The visitor's caps: per visitor per day, per IP address per hour, and all visitors together per day. */
    private function capped(Request $request, string $visitor): void
    {
        $guests = (array) config('rekhu-assistant.guests', []);
        $caps = [
            ['rekhu-assistant:visitor:'.$visitor, (int) ($guests['per_visitor_per_day'] ?? 20), 86400, 'You have sent the most messages for today. Please come back tomorrow, or sign in.'],
            ['rekhu-assistant:ip:'.sha1((string) $request->ip()), (int) ($guests['per_ip_per_hour'] ?? 60), 3600, 'That was a lot of messages at once. Wait a while and try again.'],
            ['rekhu-assistant:visitors:'.now()->toDateString(), (int) ($guests['total_per_day'] ?? 1000), 86400, 'The assistant is very busy today. Please try again tomorrow, or sign in.'],
        ];
        foreach ($caps as [$key, $max, $decay, $message]) {
            if ($max > 0 && RateLimiter::tooManyAttempts($key, $max)) {
                self::refuse(429, $message);
            }
        }
        foreach ($caps as [$key, $max, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /** A refusal as JSON whatever the client accepts (the widget asks for an event stream), so it can show the message. */
    private static function refuse(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }

    /**
     * What the user reads when a turn fails, and what the developer finds in the log. Rekhu refusing (the application is
     * paused or at its monthly limit, or the account's credit is used up) is the developer's to fix, so the user only
     * hears that the assistant is not available; the log says why.
     */
    private static function explain(Throwable $e): string
    {
        if ($e instanceof Refused && $e->type === 'app_limit') {
            Log::warning('rekhu assistant: this application is paused or at its monthly limit in your Rekhu account (Products and sites → Rekhu Assistant). ('.$e->getMessage().')');
        } elseif ($e instanceof Refused && ($e->type === 'billing_error' || $e->getCode() === 402)) {
            Log::error('rekhu assistant: the credit of your Rekhu account is used up, so the assistant cannot answer. Top up at https://rekhu.com/account/credit. ('.$e->getMessage().')');
        } else {
            Log::error('rekhu assistant: '.$e->getMessage(), ['exception' => $e]);
        }

        return 'The assistant is not available right now. Please try again in a moment.';
    }
}
