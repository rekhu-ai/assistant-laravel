<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Runtime;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Files a user attaches in the chat: print files (PDF, AI, EPS, PSD, TIFF, JPG, PNG, SVG), lists (CSV, Excel), documents,
 * and zips of them, which are unpacked. Each file is kept on the app's disk with the conversation and gets an id
 * (att_…). The assistant reads what each file is (Inspector: pages, page size, pixels, dpi, colour), sees pictures and
 * PDFs itself when they are small enough, and hands a file to an action by its id: the action's route receives it as an
 * uploaded file, exactly as from a form.
 */
class Attachments
{
    /** Types the model sees itself, with the most bytes each. */
    private const SEEN = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    public const DEFAULT_TYPES = ['pdf', 'ai', 'eps', 'psd', 'tif', 'tiff', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'indd', 'idml',
        'zip', 'csv', 'txt', 'xlsx', 'xls', 'ods', 'docx', 'doc', 'odt', 'pptx', 'json', 'xml'];

    public static function enabled(bool $visitor): bool
    {
        return $visitor ? (bool) config('rekhu-assistant.guests.files', false) : (bool) config('rekhu-assistant.files.upload.enabled', true);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map('strtolower', (array) (config('rekhu-assistant.files.upload.types') ?: self::DEFAULT_TYPES));
    }

    public static function maxKilobytes(): int
    {
        return (int) config('rekhu-assistant.files.upload.max_mb', 50) * 1024;
    }

    private static function disk()
    {
        return Storage::disk(config('rekhu-assistant.files.upload.disk') ?: 'local');
    }

    private static function folder(Conversation $conversation): string
    {
        return trim((string) config('rekhu-assistant.files.upload.path', 'rekhu-assistant/attachments'), '/').'/'.$conversation->ownerKey().'/'.$conversation->id;
    }

    /**
     * Keeps the uploaded files with the conversation, a zip unpacked into its files; answers the new attachments.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array<string, mixed>>
     */
    public static function accept(Conversation $conversation, array $files): array
    {
        $added = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $attachment = self::keep($conversation, $file->getRealPath(), $file->getClientOriginalName(), $file->getClientMimeType());
            $added[] = $attachment;
            if ($attachment['extension'] === 'zip') {
                $inside = self::unzip($conversation, $file->getRealPath(), $attachment['id']);
                $conversation->attachments[$attachment['id']]['contains'] = array_column($inside, 'id');
                $conversation->attachments[$attachment['id']]['skipped'] = self::$skipped;
                $added = [...$added, ...$inside];
            }
        }

        return $added;
    }

    private static function keep(Conversation $conversation, string $source, string $name, ?string $mime, ?string $from = null): array
    {
        $id = 'att_'.Str::lower(Str::random(16));
        $name = self::cleanName($name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = self::folder($conversation).'/'.$id.'-'.$name;
        $stream = fopen($source, 'r');
        self::disk()->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $attachment = array_filter([
            'id' => $id, 'name' => $name, 'extension' => $extension, 'type' => $mime ?: (self::SEEN[$extension] ?? 'application/octet-stream'),
            'size' => filesize($source) ?: 0, 'path' => $path, 'from' => $from, 'info' => Inspector::of($source, $extension),
        ], fn ($v) => $v !== null && $v !== []);
        $conversation->attachments[$id] = $attachment;

        return $attachment;
    }

    /** @var list<string> what the last unzip left out, and why */
    private static array $skipped = [];

    /**
     * The files of a zip, each kept as an attachment of its own. Never outside the conversation's folder (no "../"), no
     * folders, no hidden or Mac resource files, only the allowed types, at most zip.max_entries files and zip.max_mb
     * unpacked; a zip inside a zip is kept as a file, not unpacked again.
     */
    private static function unzip(Conversation $conversation, string $zipPath, string $zipId): array
    {
        self::$skipped = [];
        if (! class_exists(ZipArchive::class)) {
            self::$skipped[] = 'the server cannot unpack zips (the PHP zip extension is missing)';

            return [];
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            self::$skipped[] = 'the zip could not be opened';

            return [];
        }
        $maxEntries = (int) config('rekhu-assistant.files.upload.zip.max_entries', 200);
        $maxBytes = (int) config('rekhu-assistant.files.upload.zip.max_mb', 300) * 1024 * 1024;
        $types = self::types();
        $total = 0;
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entry = (string) ($stat['name'] ?? '');
            $base = basename(str_replace('\\', '/', $entry));
            if ($entry === '' || str_ends_with($entry, '/') || str_contains($entry, '__MACOSX') || str_starts_with($base, '.') || str_contains($entry, '..')) {
                continue;
            }
            $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (! in_array($extension, $types, true)) {
                self::$skipped[] = "$entry (not an allowed type)";

                continue;
            }
            if (count($out) >= $maxEntries) {
                self::$skipped[] = 'more than '.$maxEntries.' files: the rest were left out';
                break;
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > $maxBytes) {
                self::$skipped[] = 'more than '.(int) ($maxBytes / 1048576).' MB unpacked: the rest were left out';
                break;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'rka');
            $in = $zip->getStream($entry);
            if (! $in) {
                continue;
            }
            $outStream = fopen($tmp, 'w');
            $copied = stream_copy_to_stream($in, $outStream, $maxBytes);
            fclose($in);
            fclose($outStream);
            // a size the zip lied about is caught here: what came out counts, not what it said
            if ($copied === false) {
                @unlink($tmp);

                continue;
            }
            $folder = trim(dirname(str_replace('\\', '/', $entry)), './');
            $out[] = self::keep($conversation, $tmp, ($folder !== '' ? str_replace('/', '_', $folder).'_' : '').$base, null, $zipId);
            @unlink($tmp);
        }
        $zip->close();

        return $out;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N} ._()+-]+/u', '_', $name) ?? 'file';

        return Str::limit(trim($name, ' .') ?: 'file', 150, '');
    }

    /** A file attached in this conversation, as an uploaded file for an action's route; null for an unknown id. */
    public static function upload(Conversation $conversation, string $id): ?UploadedFile
    {
        $a = $conversation->attachments[$id] ?? null;
        if (! $a || ! self::disk()->exists($a['path'])) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rku');
        file_put_contents($tmp, self::disk()->readStream($a['path']));

        return new UploadedFile($tmp, $a['name'], $a['type'], null, true);
    }

    /** The files of one message as the model reads them: a line per file with what it is, and the small pictures and PDFs themselves. */
    public static function forModel(Conversation $conversation, array $ids, bool $withContent): array
    {
        $lines = [];
        $blocks = [];
        $seen = 0;
        foreach ($ids as $id) {
            $a = $conversation->attachments[$id] ?? null;
            if (! $a) {
                continue;
            }
            $lines[] = '- '.$a['id'].': '.$a['name'].' ('.self::describe($a).')';
            $type = self::SEEN[$a['extension']] ?? null;
            $limit = ($a['extension'] === 'pdf' ? (int) config('rekhu-assistant.files.upload.model_pdf_mb', 10) : (int) config('rekhu-assistant.files.upload.model_image_mb', 5)) * 1048576;
            if ($withContent && $type && (bool) config('rekhu-assistant.files.upload.to_model', true) && $a['size'] <= $limit && $seen < 8 && ($a['info']['pages'] ?? 1) <= 100 && self::disk()->exists($a['path'])) {
                $data = base64_encode((string) self::disk()->get($a['path']));
                $blocks[] = $a['extension'] === 'pdf'
                    ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data], 'title' => $a['name']]
                    : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $type, 'data' => $data]];
                $seen++;
            }
            if (! empty($a['skipped'])) {
                $lines[] = '  left out of '.$a['name'].': '.implode('; ', array_slice($a['skipped'], 0, 10));
            }
        }
        if (! $lines) {
            return [];
        }

        return [['type' => 'text', 'text' => "Files the user attached (an action takes one by its id):\n".implode("\n", $lines)], ...$blocks];
    }

    /** What a file is, in a few words: type, size, and what the inspector read. */
    public static function describe(array $a): string
    {
        $i = $a['info'] ?? [];
        $parts = [strtoupper($a['extension'] ?: 'file'), self::size((int) $a['size'])];
        if (isset($i['pages'])) {
            $parts[] = $i['pages'].' '.($i['pages'] === 1 ? 'page' : 'pages');
        }
        foreach (['trim_mm' => 'trim', 'page_mm' => 'page', 'bleed_mm' => 'with bleed', 'print_mm' => 'prints at'] as $k => $label) {
            if (isset($i[$k])) {
                $parts[] = $label.' '.$i[$k][0].' × '.$i[$k][1].' mm';
            }
        }
        if (isset($i['width_px'])) {
            $parts[] = $i['width_px'].' × '.$i['height_px'].' px';
        }
        if (isset($i['dpi'])) {
            $parts[] = $i['dpi'].' dpi';
        }
        if (isset($i['colour'])) {
            $parts[] = $i['colour'];
        }
        if (! empty($i['locked'])) {
            $parts[] = 'password-protected';
        }
        if (isset($i['lines'])) {
            $parts[] = $i['lines'].' lines';
        }
        if (! empty($a['contains'])) {
            $parts[] = count($a['contains']).' files unpacked';
        }
        if (! empty($a['from'])) {
            $parts[] = 'from '.$a['from'];
        }

        return implode(', ', $parts);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    /** The conversation's files are removed with it. */
    public static function remove(Conversation $conversation): void
    {
        if ($conversation->attachments) {
            self::disk()->deleteDirectory(self::folder($conversation));
        }
    }
}
