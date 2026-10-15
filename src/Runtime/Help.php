<?php

namespace Rekhu\Assistant\Runtime;

use Illuminate\Support\Str;
use Rekhu\Assistant\RekhuAssistant;

/**
 * The app's help: markdown files in resources/rekhu/help, one topic per file, the first "# " line its title. Searched by
 * the words of the question, best matches first. (The indexed knowledge on Rekhu comes in a later version.)
 */
class Help
{
    /** [['topic', 'title', 'text']] */
    public static function topics(): array
    {
        $path = RekhuAssistant::helpPath();
        if (! is_dir($path)) {
            return [];
        }
        $out = [];
        foreach (glob(rtrim($path, '/').'/*.md') ?: [] as $file) {
            $text = (string) file_get_contents($file);
            $title = preg_match('/^#\s+(.+)$/m', $text, $m) ? trim($m[1]) : Str::of(basename($file, '.md'))->replace(['-', '_'], ' ')->title()->toString();
            $out[] = ['topic' => basename($file, '.md'), 'title' => $title, 'text' => $text];
        }

        return $out;
    }

    public static function search(string $question, int $limit = 3): array
    {
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($question)) ?: [], fn ($w) => mb_strlen($w) > 2);
        $scored = [];
        foreach (self::topics() as $topic) {
            $title = Str::lower($topic['title']);
            $body = Str::lower($topic['text']);
            $score = 0;
            foreach ($words as $word) {
                $score += (str_contains($title, $word) ? 5 : 0) + min(5, substr_count($body, $word));
            }
            if ($score > 0) {
                $scored[] = $topic + ['score' => $score];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($t) => ['topic' => $t['topic'], 'title' => $t['title'], 'text' => Str::limit($t['text'], 6000)], array_slice($scored, 0, $limit));
    }
}
