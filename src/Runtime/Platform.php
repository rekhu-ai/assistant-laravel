<?php

namespace Rekhu\Assistant\Runtime;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;
use Rekhu\Assistant\RekhuAssistant;
use RuntimeException;

/**
 * One model call through the Rekhu platform (/v1/messages, the Anthropic Messages format), streamed: text is handed on
 * as it arrives, and the whole message comes back at the end. Every call names the developer's customer (x-tenant) and
 * the user (x-user), so Rekhu meters it per customer, and the conversation (x-session) so the account shows it.
 */
class Platform
{
    /** $visitor: the anonymous id of a visitor in the public chat, who has no user. */
    public function __construct(private ?Authenticatable $user, private string $conversation, private ?string $visitor = null) {}

    /** The message as the provider returns it: ['content' => blocks, 'stop_reason' => …]. */
    public function message(array $body, Closure $onText): array
    {
        $key = RekhuAssistant::key();
        if (! $key) {
            throw new RuntimeException('REKHU_ASSISTANT_KEY is missing or not a Rekhu app key. Run php artisan rekhu:assistant-install.');
        }
        $tenant = $this->visitor !== null ? RekhuAssistant::visitors() : RekhuAssistant::tenant($this->user);
        $response = Http::baseUrl(rtrim((string) config('rekhu-assistant.platform'), '/'))
            ->timeout(180)
            ->withOptions(['stream' => true])
            ->withHeaders([
                'x-api-key' => $key['key'],
                'x-agent' => $key['agent'],
                'x-tenant' => $tenant['id'],
                'x-tenant-name' => $tenant['name'],
                'x-user' => $this->visitor !== null ? 'visitor-'.substr(sha1($this->visitor), 0, 16) : RekhuAssistant::userRef($this->user),
                'x-session' => 'assistant-'.$this->conversation,
                'anthropic-version' => '2023-06-01',
                'user-agent' => 'rekhu-assistant-laravel/'.RekhuAssistant::VERSION,
            ])
            ->post('/v1/messages', $body + ['stream' => true]);

        if (! $response->successful()) {
            $error = json_decode((string) $response->body(), true);
            throw new Refused('Rekhu answered '.$response->status().': '.($error['error']['message'] ?? 'no reason given'), $response->status(), (string) ($error['error']['type'] ?? ''));
        }

        return $this->read($response->toPsrResponse()->getBody(), $onText);
    }

    /** Folds the server-sent events of a streamed message into the message itself. */
    private function read($stream, Closure $onText): array
    {
        $blocks = [];
        $partial = [];
        $stop = null;
        $buffer = '';
        while (! $stream->eof()) {
            $buffer .= $stream->read(4096);
            while (($end = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 2);
                foreach (explode("\n", $event) as $line) {
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = json_decode(trim(substr($line, 5)), true);
                    if (! is_array($data)) {
                        continue;
                    }
                    $i = $data['index'] ?? null;
                    switch ($data['type'] ?? '') {
                        case 'content_block_start':
                            $blocks[$i] = $data['content_block'];
                            $partial[$i] = '';
                            break;
                        case 'content_block_delta':
                            $delta = $data['delta'] ?? [];
                            if (($delta['type'] ?? '') === 'text_delta') {
                                $blocks[$i]['text'] = ($blocks[$i]['text'] ?? '').$delta['text'];
                                $onText($delta['text']);
                            } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                                $partial[$i] .= $delta['partial_json'] ?? '';
                            } elseif (($delta['type'] ?? '') === 'thinking_delta') {
                                // the model's thinking goes back unchanged with its signature on the next call, never to the user
                                $blocks[$i]['thinking'] = ($blocks[$i]['thinking'] ?? '').$delta['thinking'];
                            } elseif (($delta['type'] ?? '') === 'signature_delta') {
                                $blocks[$i]['signature'] = ($blocks[$i]['signature'] ?? '').$delta['signature'];
                            }
                            break;
                        case 'content_block_stop':
                            if (($blocks[$i]['type'] ?? '') === 'tool_use') {
                                $input = $partial[$i] !== '' ? json_decode($partial[$i], true) : [];
                                $blocks[$i]['input'] = is_array($input) && $input !== [] ? $input : (object) [];
                            }
                            break;
                        case 'message_delta':
                            $stop = $data['delta']['stop_reason'] ?? $stop;
                            break;
                        case 'error':
                            throw new RuntimeException('the model stopped: '.($data['error']['message'] ?? 'unknown error'));
                    }
                }
            }
        }
        ksort($blocks);

        return ['content' => array_values($blocks), 'stop_reason' => $stop];
    }
}
