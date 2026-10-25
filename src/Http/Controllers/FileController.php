<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http\Controllers;

use Rekhu\Assistant\Runtime\Files;
use Symfony\Component\HttpFoundation\Response;

/** A file an action handed over, behind the signed link of the user's download button. */
final class FileController
{
    public function __invoke(string $file): Response
    {
        $kept = Files::find($file);
        abort_unless($kept !== null, 410, 'This download has expired. Ask the assistant again.');

        return response($kept['body'], 200, [
            'Content-Type' => $kept['type'],
            'Content-Disposition' => 'attachment; filename="'.addcslashes(preg_replace('/[\r\n"]+/', '', $kept['name']), '\\').'"; filename*=UTF-8\'\''.rawurlencode($kept['name']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
