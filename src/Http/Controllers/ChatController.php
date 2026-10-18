<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Rekhu\Assistant\Http\EventStream;
use Rekhu\Assistant\Http\Middleware\IdentifyVisitor;
use Rekhu\Assistant\Http\Requests\ChatRequest;
use Rekhu\Assistant\Protocol;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Runtime\Turn;
use Rekhu\Assistant\Support\Failures;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The chat endpoints of protocol 1 (PROTOCOL.md): a message in, the answer out as server-sent events; the
 * conversation so far; and starting over. The same endpoints serve the public chat, where the request carries a
 * visitor instead of a user (see IdentifyVisitor).
 */
final class ChatController
{
    public function send(ChatRequest $request): StreamedResponse
    {
        $visitor = IdentifyVisitor::of($request);
        $conversation = Conversation::open($request->user(), $request->conversation(), $visitor);

        return EventStream::respond(function (EventStream $stream) use ($request, $conversation, $visitor): void {
            $stream->send(['type' => 'start', 'conversation' => $conversation->id, 'protocol' => Protocol::VERSION]);
            try {
                (new Turn($request, $conversation, $stream->sender(), $visitor))
                    ->turn($request->message(), $request->confirmation(), $request->locale());
                $stream->send(['type' => 'done']);
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
        $conversation = $asked !== '' ? Conversation::open($request->user(), $asked, $visitor) : null;

        return response()->json(array_filter([
            'conversation' => $conversation?->id,
            'messages' => $conversation?->transcript() ?? [],
            'greeting' => RekhuAssistant::greeting($visitor !== null),
        ], fn ($value) => $value !== null));
    }

    public function forget(Request $request): Response
    {
        Conversation::open($request->user(), (string) $request->input('conversation'), IdentifyVisitor::of($request))->forget();

        return response()->noContent();
    }
}
