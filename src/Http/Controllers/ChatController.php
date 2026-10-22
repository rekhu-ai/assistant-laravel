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
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Runtime\Quick;
use Rekhu\Assistant\Runtime\Reports;
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
        $conversation = Conversation::open($request->user(), $request->conversation(), $visitor);

        return EventStream::respond(function (EventStream $stream) use ($request, $conversation, $visitor): void {
            $stream->send(['type' => 'start', 'conversation' => $conversation->id, 'protocol' => Protocol::VERSION]);
            try {
                $answer = (new Turn($request, $conversation, $stream->sender(), $visitor))
                    ->turn($request->message(), $request->confirmation(), $request->locale());
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
        $conversation = $asked !== '' ? Conversation::open($request->user(), $asked, $visitor) : null;

        return response()->json(array_filter([
            'conversation' => $conversation?->id,
            'messages' => $conversation?->transcript() ?? [],
            'greeting' => RekhuAssistant::greeting($visitor !== null),
            // a fresh chat gets the developer's quick questions, as the user may use them
            'quick' => $conversation ? null : (Quick::for($request->user()) ?: null),
            // the reasons a user may give when reporting an answer, in their language
            'report' => Reports::offer($visitor !== null),
        ], fn ($value) => $value !== null));
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

    public function forget(Request $request): Response
    {
        Conversation::open($request->user(), (string) $request->input('conversation'), IdentifyVisitor::of($request))->forget();

        return response()->noContent();
    }
}
