<?php

// Rekhu Assistant, the Laravel connector. Everything here can be set from .env; `php artisan rekhu:assistant-install` writes the key.
return [

    // The app key from the Rekhu account (Projects → your Laravel app → Connection tab). One key per environment.
    'key' => env('REKHU_ASSISTANT_KEY'),

    // The platform. Only change it for a test platform.
    'platform' => env('REKHU_PLATFORM', 'https://api.rekhu.ai'),

    // The model the assistant answers with, and how long one answer and one turn may get.
    'model' => env('REKHU_ASSISTANT_MODEL', 'claude-sonnet-5'),
    'max_tokens' => 4000,
    'max_message' => 4000,                  // characters per message from a signed-in user
    'max_steps' => 8,

    // Where the chat lives, and who may use it: the app's own middleware, so only signed-in users talk to it.
    // Blade, Livewire, Inertia: web,auth. An API for a Nuxt, Next or mobile front end: api,auth:sanctum (or your guard),
    // and a path under api/ when your app exempts api/* from CSRF (Sanctum checks CSRF for its stateful domains).
    'path' => env('REKHU_ASSISTANT_PATH', 'rekhu/assistant'),
    'middleware' => explode(',', env('REKHU_ASSISTANT_MIDDLEWARE', 'web,auth')),

    // The public chat, for visitors who are not signed in (off unless you turn it on). Visitors only reach actions marked
    // #[Tool(…, guests: true)], pages without login, and the help. All of them together are one customer in your Rekhu
    // account, "Visitors", with its own monthly limit: set one there before you turn this on.
    'guests' => [
        'enabled' => (bool) env('REKHU_ASSISTANT_GUESTS', false),
        'middleware' => explode(',', env('REKHU_ASSISTANT_GUEST_MIDDLEWARE', 'web')),
        'tenant' => ['id' => 'visitors', 'name' => 'Visitors'],
        'greeting' => null,                 // null: the greeting below
        'max_message' => 1000,              // characters per message
        'max_steps' => 4,                   // tool rounds per answer
        'per_visitor_per_day' => 20,        // messages
        'per_ip_per_hour' => 60,            // messages from one IP address, whatever the visitor id
        'total_per_day' => 1000,            // messages from all visitors together
    ],

    // How it introduces itself, and its colour in the widget.
    'name' => env('REKHU_ASSISTANT_NAME', 'Assistant'),
    'greeting' => 'Hi! Ask me anything about this app, or tell me what you want done.',
    'color' => '#4f46e5',

    // Your own rules for the assistant, in plain words: tone, what it never does, when it hands over to a person.
    'instructions' => '',

    // Pages it may open: every named GET route, minus these name patterns, minus routes the user may not open.
    // A front end of its own (Nuxt, Next, a mobile app): its pages, opened at its own address. Each page is
    // ['path' => '/orders/{order}', 'label' => 'Order', 'help' => 'What it shows', 'can' => 'a gate, optional'];
    // RekhuAssistant::pagesUsing() adds pages from code, as the user may open them.
    'pages' => [
        'frontend' => [
            'url' => env('REKHU_ASSISTANT_FRONTEND_URL'),
            'list' => [],
        ],
        'exclude' => ['rekhu.*', 'login', 'logout', 'register', 'password.*', 'verification.*', 'sanctum.*', 'livewire.*',
            'ignition.*', 'telescope*', 'horizon.*', 'pulse*', 'debugbar.*', 'storage.*', 'up', 'filament.*.auth.*'],
        'limit' => 300,
    ],

    // Help files the assistant answers from: markdown, one topic per file. Empty means resources/rekhu/help.
    'help_path' => null,

    // How long a conversation is kept for its user, in minutes.
    'conversation_minutes' => 1440,
];
