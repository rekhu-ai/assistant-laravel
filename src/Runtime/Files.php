<?php

namespace Rekhu\Assistant\Runtime;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files an action hands over (an export, an invoice PDF): the assistant cannot pass a download on, so the package keeps
 * the file for a short while in the app's cache, shared by all its servers, and the user gets a button with a signed
 * link to it. The link works without the app's login, so it also opens from a Nuxt or mobile front end that signs in
 * with a token; it is long, unguessable and expires.
 */
class Files
{
    /** [body, name, type] when the response is a download, else null. */
    public static function from(Response $response): ?array
    {
        $disposition = (string) $response->headers->get('Content-Disposition', '');
        $attachment = str_starts_with(strtolower($disposition), 'attachment');
        if (! $response instanceof BinaryFileResponse && ! $response instanceof StreamedResponse && ! $attachment) {
            return null;
        }
        if ($response instanceof BinaryFileResponse) {
            $file = $response->getFile();
            $body = (string) file_get_contents($file->getPathname());
            // a temporary export (Laravel Excel's) is removed once sent; it is not sent now, so remove it here
            if ((fn () => $this->deleteFileAfterSend)->call($response)) {
                @unlink($file->getPathname());
            }
            $name = self::nameFrom($disposition) ?? $file->getFilename();
        } elseif ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
            $name = self::nameFrom($disposition) ?? 'download';
        } else {
            $body = (string) $response->getContent();
            $name = self::nameFrom($disposition) ?? 'download';
        }
        $type = (string) ($response->headers->get('Content-Type') ?: 'application/octet-stream');

        return [$body, $name, $type];
    }

    /** Keeps the file and returns what the model is told: its name and the link, which the user gets as a button. */
    public static function keep(string $body, string $name, string $type): array
    {
        $max = (int) config('rekhu-assistant.files.max_mb', 20) * 1024 * 1024;
        if (strlen($body) > $max) {
            return ['ok' => false, 'error' => 'the file is larger than the assistant may hand over ('.(int) config('rekhu-assistant.files.max_mb', 20).' MB); ask for less, with a filter'];
        }
        $minutes = (int) config('rekhu-assistant.files.minutes', 15);
        $id = Str::random(40);
        self::store()->put(self::key($id), ['name' => $name, 'type' => $type, 'body' => base64_encode($body)], now()->addMinutes($minutes));
        $url = URL::temporarySignedRoute('rekhu.assistant.file', now()->addMinutes($minutes), ['file' => $id]);

        return ['ok' => true, 'result' => [
            'status' => 'file',
            'name' => $name,
            'size' => strlen($body),
            'url' => $url,
            'expires_in_minutes' => $minutes,
            'note' => 'The user has a download button for this file. Say what is in it; do not repeat the link.',
        ]];
    }

    /** ['name', 'type', 'body'] of a kept file, or null when it expired or never was. */
    public static function find(string $id): ?array
    {
        $file = preg_match('/^[A-Za-z0-9]{40}$/', $id) ? self::store()->get(self::key($id)) : null;
        if (! is_array($file) || ! isset($file['body'])) {
            return null;
        }
        $file['body'] = (string) base64_decode((string) $file['body'], true);

        return $file;
    }

    private static function nameFrom(string $disposition): ?string
    {
        if (preg_match("/filename\\*=(?:UTF-8'')?([^;]+)/i", $disposition, $m)) {
            return rawurldecode(trim($m[1], " \"'"));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private static function store(): Repository
    {
        return Cache::store(config('rekhu-assistant.files.store'));
    }

    private static function key(string $id): string
    {
        return 'rekhu-assistant:file:'.$id;
    }
}
