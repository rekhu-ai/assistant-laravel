<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Runtime;

/**
 * What can be read from a file without opening it in a program, for the assistant and for print work: a picture's
 * pixels, resolution (dpi), size at that resolution and colour mode; a PDF's pages, page size and whether it is locked;
 * an EPS or AI file's box; an SVG's size; a spreadsheet's rows. Read from the file's own bytes, with Imagick when the
 * server has it. Only what is certain is given; nothing is guessed.
 */
class Inspector
{
    private const MM_PER_INCH = 25.4;

    private const PT_TO_MM = 25.4 / 72;

    /** @return array<string, mixed> */
    public static function of(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        try {
            return match (true) {
                in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp'], true) => self::picture($path, $extension),
                in_array($extension, ['pdf', 'ai'], true) => self::pdf($path),
                $extension === 'eps' => self::eps($path),
                $extension === 'psd' => self::psd($path),
                $extension === 'svg' => self::svg($path),
                in_array($extension, ['csv', 'txt'], true) => self::text($path),
                default => [],
            };
        } catch (\Throwable) {
            return [];
        }
    }

    private static function picture(string $path, string $extension): array
    {
        $info = [];
        $size = @getimagesize($path);
        if ($size) {
            $info['width_px'] = $size[0];
            $info['height_px'] = $size[1];
            if (isset($size['channels'])) {
                $info['colour'] = $size['channels'] === 4 ? 'CMYK' : ($size['channels'] === 1 ? 'greyscale' : 'RGB');
            }
        }
        $dpi = match ($extension) {
            'jpg', 'jpeg' => self::jpegDpi($path),
            'png' => self::pngDpi($path),
            default => null,
        };
        if (! $dpi && class_exists(\Imagick::class)) {
            try {
                $image = new \Imagick($path);
            } catch (\Throwable) {
                $image = null;
            }
        }
        if (! $dpi && isset($image) && $image) {
            $res = $image->getImageResolution();
            if (($res['x'] ?? 0) > 1) {
                $dpi = (int) round($image->getImageUnits() === \Imagick::RESOLUTION_PIXELSPERCENTIMETER ? $res['x'] * 2.54 : $res['x']);
            }
            $info['width_px'] ??= $image->getImageWidth();
            $info['height_px'] ??= $image->getImageHeight();
            $info['colour'] ??= match ($image->getImageColorspace()) {
                \Imagick::COLORSPACE_CMYK => 'CMYK', \Imagick::COLORSPACE_GRAY => 'greyscale', default => 'RGB',
            };
        }
        if ($dpi && isset($info['width_px'])) {
            $info['dpi'] = $dpi;
            // the size it prints at this resolution: whether it is enough is the product's to say
            $info['print_mm'] = [round($info['width_px'] / $dpi * self::MM_PER_INCH, 1), round($info['height_px'] / $dpi * self::MM_PER_INCH, 1)];
        }

        return $info;
    }

    /** The density of a JPEG's JFIF header, in dots per inch. */
    private static function jpegDpi(string $path): ?int
    {
        $head = (string) file_get_contents($path, false, null, 0, 64);
        $at = strpos($head, "JFIF\0");
        if ($at === false || strlen($head) < $at + 12) {
            return null;
        }
        $units = ord($head[$at + 7]);
        $x = unpack('n', substr($head, $at + 8, 2))[1];
        if ($x < 2 || $units === 0) {
            return null;
        }

        return $units === 2 ? (int) round($x * 2.54) : $x;
    }

    /** The pHYs chunk of a PNG, in dots per inch. */
    private static function pngDpi(string $path): ?int
    {
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        $at = strpos($head, 'pHYs');
        if ($at === false || strlen($head) < $at + 13) {
            return null;
        }
        $x = unpack('N', substr($head, $at + 4, 4))[1];
        $unit = ord($head[$at + 12]);

        return $unit === 1 && $x > 0 ? (int) round($x * 0.0254) : null;
    }

    private static function pdf(string $path): array
    {
        $size = filesize($path) ?: 0;
        // the start and the end of the file: the header, the first pages and the trailer, without reading a whole print file
        $head = (string) file_get_contents($path, false, null, 0, 600000);
        $tail = $size > 600000 ? (string) file_get_contents($path, false, null, max(0, $size - 200000)) : '';
        $info = [];
        if (preg_match('/%PDF-(\d\.\d)/', $head, $m)) {
            $info['pdf_version'] = $m[1];
        }
        // Imagick counts pages exactly when the server can read PDFs (Ghostscript); else the file's own page tree says it
        if (class_exists(\Imagick::class)) {
            try {
                $image = new \Imagick;
                $image->pingImage($path);
                $info['pages'] = $image->getNumberImages();
            } catch (\Throwable) {
            }
        }
        if (! isset($info['pages']) && preg_match_all('/\/Type\s*\/Pages\b[^>]*?\/Count\s+(\d+)/s', $head.$tail, $m)) {
            $info['pages'] = max(array_map('intval', $m[1]));
        }
        foreach (['TrimBox' => 'trim_mm', 'MediaBox' => 'page_mm', 'BleedBox' => 'bleed_mm'] as $box => $key) {
            if (preg_match('/\/'.$box.'\s*\[\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s*\]/', $head, $m)) {
                $info[$key] = [round(((float) $m[3] - (float) $m[1]) * self::PT_TO_MM, 1), round(((float) $m[4] - (float) $m[2]) * self::PT_TO_MM, 1)];
            }
        }
        if (str_contains($head.$tail, '/Encrypt')) {
            $info['locked'] = true;
        }

        return $info;
    }

    private static function eps(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 65536);
        if (preg_match('/%%(?:HiRes)?BoundingBox:\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)/', $head, $m)) {
            return ['page_mm' => [round(((float) $m[3] - (float) $m[1]) * self::PT_TO_MM, 1), round(((float) $m[4] - (float) $m[2]) * self::PT_TO_MM, 1)]];
        }

        return [];
    }

    /** A Photoshop file's header: size and colour mode. */
    private static function psd(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 26);
        if (strlen($head) < 26 || ! str_starts_with($head, '8BPS')) {
            return [];
        }
        $h = unpack('N', substr($head, 14, 4))[1];
        $w = unpack('N', substr($head, 18, 4))[1];
        $mode = unpack('n', substr($head, 24, 2))[1];

        return ['width_px' => $w, 'height_px' => $h, 'colour' => [1 => 'greyscale', 3 => 'RGB', 4 => 'CMYK'][$mode] ?? 'other'];
    }

    private static function svg(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);
        $info = [];
        if (preg_match('/<svg\b[^>]*\bwidth="([\d.]+)(px|mm|cm|in|pt)?"/i', $head, $m)) {
            $info['width'] = $m[1].($m[2] ?? '');
        }
        if (preg_match('/<svg\b[^>]*\bheight="([\d.]+)(px|mm|cm|in|pt)?"/i', $head, $m)) {
            $info['height'] = $m[1].($m[2] ?? '');
        }

        return $info;
    }

    /** A CSV or text list: its lines and, for a CSV, its header. */
    private static function text(string $path): array
    {
        $lines = 0;
        $first = null;
        $handle = fopen($path, 'r');
        while ($handle && ($line = fgets($handle)) !== false && $lines < 200000) {
            $first ??= trim($line);
            $lines++;
        }
        if ($handle) {
            fclose($handle);
        }

        return array_filter(['lines' => $lines, 'first_line' => $first !== null ? mb_substr($first, 0, 300) : null]);
    }
}
