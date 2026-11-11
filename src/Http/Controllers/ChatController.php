<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Rekhu\Assistant\Http\EventStream;
use Rekhu\Assistant\Http\Middleware\IdentifyVisitor;
use Rekhu\Assistant\Http\Requests\ChatRequest;
use Rekhu\Assistant\Http\Requests\ReportRequest;
use Rekhu\Assistant\Protocol;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Attachments;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Runtime\Quick;
use Rekhu\Assistant\Runtime\Reports;
use Rekhu\Assistant\Runtime\Scopes;
use Rekhu\Assistant\Runtime\Turn;
use Rekhu\Assistant\Support\Failures;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The chat endpoints of protocol 1 (PROTOCOL.md): a message in, the answer out as server-sent events; the
 * conversation so far; starting over; and a report on an answer. The same endpoints serve the public chat, where the request carries a
 * visitor instead of a user (see IdentifyVisitor).
 */
final class ChatController
{
    public function send(ChatRequest $request): StreamedResponse
    {
        $visitor = IdentifyVisitor::of($request);
        $conversation = Conversation::open($request->user(), $request->conversation(), $visitor, Scopes::from($request));
        // the files are kept before the answer streams: an upload is read from the request, which a stream may outlive
        $files = Attachments::accept($conversation, $request->uploads());

        return EventStream::respond(function (EventStream $stream) use ($request, $conversation, $visitor, $files): void {
            $stream->send(array_filter(['type' => 'start', 'conversation' => $conversation->id, 'protocol' => Protocol::VERSION,
                // what was attached, with what the package read of it, so the chat can show it under the message
                'files' => $files ? array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'about' => Attachments::describe($a)], $files) : null]));
            try {
                $answer = (new Turn($request, $conversation, $stream->sender(), $visitor))
                    ->turn($request->message(), $request->confirmation(), $request->locale(), array_column($files, 'id'));
                // the id of the answer just given, so a live chat can report it without reading the history again
                $stream->send(array_filter(['type' => 'done', 'message' => $answer]));
            } catch (Throwable $e) {
                $conversation->save();
                $stream->send(['type' => 'error', 'message' => Failures::report($e)]);
            }
            // what an action flashed or changed in the session is kept for the user's next page
            if ($request->hasSession()) {
                $request->session()->save();
            }
        });
    }

    /** What the user saw: their own messages and the assistant's answers. */
    public function history(Request $request): JsonResponse
    {
        $visitor = IdentifyVisitor::of($request);
        // without a conversation: only the greeting, for a chat that opens fresh
        $asked = (string) $request->query('conversation');
        $conversation = $asked !== '' ? Conversation::open($request->user(), $asked, $visitor, Scopes::from($request)) : null;

        return response()->json(array_filter([
            'conversation' => $conversation?->id,
            'messages' => $conversation?->transcript() ?? [],
            'greeting' => RekhuAssistant::greeting($visitor !== null),
            // a fresh chat gets the developer's quick questions, as the user may use them
            'quick' => $conversation ? null : (Quick::for($request->user()) ?: null),
            // the reasons a user may give when reporting an answer, in their language
            'report' => Reports::offer($visitor !== null),
            // a signed-in user has a list of earlier conversations; the public chat has none
            'history' => $visitor === null && $request->user() ? true : null,
            // the chat's look for this tenant, when the app gives it one (brandUsing, a logo, colours, white-label)
            'brand' => RekhuAssistant::branded() ? self::brandFor($request, $visitor !== null) : null,
        ], fn ($value) => $value !== null));
    }

    private static function brandFor(Request $request, bool $visitor): array
    {
        $brand = RekhuAssistant::brand($visitor ? RekhuAssistant::visitors() : RekhuAssistant::tenant($request->user()));

        return array_filter(['name' => $brand['name'], 'logo' => $brand['logo'], 'colors' => $brand['colors'] ?: null, 'rekhu' => $brand['rekhu']], fn ($v) => $v !== null);
    }

    /** "Report a problem" on one answer of the user's own conversation; the report is built from what the server kept. */
    public function report(ReportRequest $request): JsonResponse
    {
        $visitor = IdentifyVisitor::of($request);
        $conversation = Conversation::open($request->user(), (string) $request->validated('conversation'), $visitor);
        if (! Reports::allowed($conversation)) {
            Protocol::refuse(429, __('rekhu-assistant::messages.report_too_many'));
        }
        $sent = Reports::submit($request->user(), $visitor, $conversation, (string) $request->validated('message'),
            (string) $request->validated('reason'), $request->validated('comment'), $request->validated('locale'));
        if (! $sent) {
            Protocol::refuse(404, __('rekhu-assistant::messages.report_not_found'));
        }

        return response()->json(['message' => __('rekhu-assistant::messages.report_thanks')], 201);
    }

    /**
     * The signed-in user's conversations for the chat's list, newest first: id, title (their name for it, else their
     * first question), scope, when, how many questions; in pages (?before= the next of the page before) and searched (?q=).
     */
    public function conversations(Request $request): JsonResponse
    {
        $owner = Conversation::owner($request->user(), null);
        $limit = min(100, max(1, (int) $request->query('limit', 30)));
        $history = RekhuAssistant::history();
        if (! $history) {
            return response()->json(['conversations' => RekhuAssistant::store()->list($owner, Scopes::from($request), $limit)]);
        }
        $page = $history->page($owner, Scopes::from($request), $limit, $request->filled('before') ? (string) $request->query('before') : null, $request->filled('q') ? mb_substr((string) $request->query('q'), 0, 100) : null);

        return response()->json(array_filter($page, fn ($v) => $v !== null));
    }

    /** Names one of the user's conversations; an empty title goes back to their first question. */
    public function rename(Request $request, string $conversation): JsonResponse
    {
        $title = $request->validate(['title' => ['present', 'nullable', 'string', 'max:200']])['title'] ?? '';
        $history = RekhuAssistant::history();
        $item = $history?->rename(Conversation::owner($request->user(), null), $conversation, (string) $title);
        if (! $item) {
            Protocol::refuse(404, __('rekhu-assistant::messages.conversation_not_found'));
        }

        return response()->json(['conversation' => $item]);
    }

    /** Deletes one of the user's conversations for good. */
    public function delete(Request $request, string $conversation): Response
    {
        RekhuAssistant::store()->forget(Conversation::owner($request->user(), null), $conversation);

        return response()->noContent();
    }

    public function forget(Request $request): Response
    {
        Conversation::open($request->user(), (string) $request->input('conversation'), IdentifyVisitor::of($request), Scopes::from($request))->forget();

        return response()->noContent();
    }
}
