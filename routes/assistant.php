<?php

use Illuminate\Support\Facades\Route;
use Rekhu\Assistant\Http\ChatController;

$path = trim((string) config('rekhu-assistant.path', 'rekhu/assistant'), '/');

// the widget's script: public, versioned, no session
Route::get($path.'/widget.js', function () {
    return response(file_get_contents(__DIR__.'/../resources/js/widget.js'), 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => app()->environment('local') ? 'no-cache' : 'public, max-age=86400',
    ]);
})->name('rekhu.assistant.widget');

// the chat: the app's own middleware decides who may talk to it
Route::middleware((array) config('rekhu-assistant.middleware', ['web', 'auth']))->group(function () use ($path) {
    Route::post($path.'/chat', ChatController::class)->middleware('throttle:30,1')->name('rekhu.assistant.chat');
    Route::get($path.'/chat', [ChatController::class, 'history'])->name('rekhu.assistant.history');
    Route::delete($path.'/chat',[ChatController::class, 'forget'])->name('rekhu.assistant.forget');
});

// the public chat, for visitors who are not signed in: only when the app turns it on (REKHU_ASSISTANT_GUESTS=true);
// the web middleware keeps the CSRF check, and the controller caps every visitor, IP address and day
if (config('rekhu-assistant.guests.enabled')) {
    Route::middleware((array) config('rekhu-assistant.guests.middleware', ['web']))->group(function () use ($path) {
        Route::post($path.'/visitor/chat', ChatController::class)->middleware('throttle:20,1')->defaults('rekhu_visitor', true)->name('rekhu.assistant.visitor');
        Route::get($path.'/visitor/chat', [ChatController::class, 'history'])->middleware('throttle:60,1')->defaults('rekhu_visitor', true)->name('rekhu.assistant.visitor.history');
        Route::delete($path.'/visitor/chat', [ChatController::class, 'forget'])->middleware('throttle:20,1')->defaults('rekhu_visitor', true)->name('rekhu.assistant.visitor.forget');
    });
}

unset($path);
