<?php

namespace Rekhu\Assistant\Http;

use Illuminate\Support\Facades\Route;
use Rekhu\Assistant\Http\Controllers\ChatController;
use Rekhu\Assistant\Http\Controllers\FileController;
use Rekhu\Assistant\Http\Middleware\EnsureAccess;
use Rekhu\Assistant\Http\Middleware\EnsureProtocol;
use Rekhu\Assistant\Http\Middleware\IdentifyVisitor;
use Rekhu\Assistant\Http\Middleware\LimitVisitors;
use Rekhu\Assistant\Runtime\Reports;

/**
 * The assistant's routes: the widget's script, the chat for signed-in users (with "report a problem" on an answer)
 * and, when turned on, the public chat. The
 * package registers them itself at rekhu-assistant.path; an app that wants them in its own route file (its own domain,
 * prefix, group or names) turns that off and calls RekhuAssistant::routes() there, inside its own group.
 */
class AssistantRoutes
{
    /** @param array{path?: string, middleware?: list<string>, guests?: bool, guest_middleware?: list<string>, widget?: bool} $options */
    public static function register(array $options = []): void
    {
        $path = trim((string) ($options['path'] ?? config('rekhu-assistant.path', 'rekhu/assistant')), '/');
        $prefix = $path === '' ? '' : $path.'/';

        // the widget's script: public, no session
        if ($options['widget'] ?? true) {
            Route::get($prefix.'widget.js', fn () => response(file_get_contents(__DIR__.'/../../resources/js/widget.js'), 200, [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => app()->environment('local') ? 'no-cache' : 'public, max-age=86400',
            ]))->name('rekhu.assistant.widget');
        }

        // a file an action handed over: the signature and its expiry stand in for the login, so it opens from any front end
        Route::get($prefix.'files/{file}', FileController::class)->middleware(['signed', 'throttle:30,1'])->name('rekhu.assistant.file');

        // the chat: the app's own middleware decides who may talk to it, and RekhuAssistant::accessUsing() after it
        Route::middleware([EnsureProtocol::class, ...(array) ($options['middleware'] ?? config('rekhu-assistant.middleware', ['web', 'auth'])), EnsureAccess::class])
            ->prefix($prefix.'chat')
            ->name('rekhu.assistant.')
            ->group(function () {
                Route::post('/', [ChatController::class, 'send'])->middleware('throttle:30,1')->name('chat');
                Route::get('/', [ChatController::class, 'history'])->name('history');
                Route::delete('/', [ChatController::class, 'forget'])->name('forget');
                if (Reports::enabled()) {
                    Route::post('report', [ChatController::class, 'report'])->name('report');
                }
            });

        // the public chat, for visitors who are not signed in: only when the app turns it on. The web middleware keeps
        // the CSRF check; every visitor, IP address and day is capped.
        if ($options['guests'] ?? config('rekhu-assistant.guests.enabled')) {
            Route::middleware([EnsureProtocol::class, ...(array) ($options['guest_middleware'] ?? config('rekhu-assistant.guests.middleware', ['web'])), IdentifyVisitor::class])
                ->prefix($prefix.'visitor/chat')
                ->name('rekhu.assistant.visitor')
                ->group(function () {
                    Route::post('/', [ChatController::class, 'send'])->middleware(['throttle:20,1', LimitVisitors::class])->name('');
                    Route::get('/', [ChatController::class, 'history'])->middleware('throttle:60,1')->name('.history');
                    Route::delete('/', [ChatController::class, 'forget'])->middleware('throttle:20,1')->name('.forget');
                    if (Reports::enabled(true)) {
                        Route::post('report', [ChatController::class, 'report'])->middleware('throttle:20,1')->name('.report');
                    }
                });
        }
    }
}
