<?php

use Illuminate\Support\Facades\Route;
use Rekhu\Assistant\RekhuAssistant;

// Only when the package's own routes are off (REKHU_ASSISTANT_ROUTES=false): the assistant's routes in your own group,
// here /api/v2/assistant/chat named v2.rekhu.assistant.chat. Point the front end's endpoint at it.
Route::prefix('v2')->name('v2.')->group(function () {
    RekhuAssistant::routes([
        'path' => 'assistant',
        'middleware' => ['api', 'auth:sanctum'],
        'guests' => false,
        'widget' => false,   // the front end has @rekhu/assistant-widget from npm
    ]);
});
