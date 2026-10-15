<?php

use Illuminate\Support\Facades\Route;
use Rekhu\Assistant\Http\Controllers\ChatController;
use Rekhu\Assistant\Http\Middleware\EnsureProtocol;
use Rekhu\Assistant\Http\Middleware\IdentifyVisitor;
use Rekhu\Assistant\Http\Middleware\LimitVisitors;

$path = trim((string) config('rekhu-assistant.path', 'rekhu/assistant'), '/');

// the widget's script: public, no session
Route::get($path.'/widget.js', fn () => response(file_get_contents(__DIR__.'/../resources/js/widget.js'), 200, [
    'Content-Type' => 'application/javascript; charset=utf-8',
    'Cache-Control' => app()->environment('local') ? 'no-cache' : 'public, max-age=86400',
]))->name('rekhu.assistant.widget');

// the chat: the app's own middleware decides who may talk to it
Route::middleware([EnsureProtocol::class, ...(array) config('rekhu-assistant.middleware', ['web', 'auth'])])
    ->prefix($path.'/chat')
    ->name('rekhu.assistant.')
    ->group(function () {
        Route::post('/', [ChatController::class, 'send'])->middleware('throttle:30,1')->name('chat');
        Route::get('/', [ChatController::class, 'history'])->name('history');
        Route::delete('/', [ChatController::class, 'forget'])->name('forget');
    });

// the public chat, for visitors who are not signed in: only when the app turns it on (REKHU_ASSISTANT_GUESTS=true).
// The web middleware keeps the CSRF check; every visitor, IP address and day is capped.
if (config('rekhu-assistant.guests.enabled')) {
    Route::middleware([EnsureProtocol::class, ...(array) config('rekhu-assistant.guests.middleware', ['web']), IdentifyVisitor::class])
        ->prefix($path.'/visitor/chat')
        ->name('rekhu.assistant.visitor')
        ->group(function () {
            Route::post('/', [ChatController::class, 'send'])->middleware(['throttle:20,1', LimitVisitors::class])->name('');
            Route::get('/', [ChatController::class, 'history'])->middleware('throttle:60,1')->name('.history');
            Route::delete('/', [ChatController::class, 'forget'])->middleware('throttle:20,1')->name('.forget');
        });
}

unset($path);
