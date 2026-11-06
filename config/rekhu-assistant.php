<?php

// Rekhu Assistant, the Laravel connector. Everything here can be set from .env; `php artisan rekhu:assistant-install` writes the key.
return [

    // The app key from the Rekhu account (Projects → your Laravel app → Connection tab). One key per environment.
    'key' => env('REKHU_ASSISTANT_KEY'),

    // The platform. Only change it for a test platform.
    'platform' => env('REKHU_PLATFORM', 'https://api.rekhu.ai'),

    // The model the assistant answers with, by its name in the Rekhu account (empty: the account's default), and how
    // long one answer and one turn may get.
    'model' => env('REKHU_ASSISTANT_MODEL'),
    'max_tokens' => 16000,
    'max_message' => 4000,                  // characters per message from a signed-in user
    'max_steps' => 8,

    // Where the chat lives, and who may use it: the app's own middleware, so only signed-in users talk to it.
    // Blade, Livewire, Inertia: web,auth. An API for a Nuxt, Next or mobile front end: api,auth:sanctum (or your guard),
    // and a path under api/ when your app exempts api/* from CSRF (Sanctum checks CSRF for its stateful domains).
    'path' => env('REKHU_ASSISTANT_PATH', 'rekhu/assistant'),

    // false: the package registers no routes; put RekhuAssistant::routes() in your own route file, inside your own group
    'routes' => (bool) env('REKHU_ASSISTANT_ROUTES', true),
    'middleware' => explode(',', env('REKHU_ASSISTANT_MIDDLEWARE', 'web,auth')),

    // The public chat, for visitors who are not signed in (off unless you turn it on). Visitors only reach actions marked
    // #[Tool(…, guests: true)], pages without login, and the help. All of them together are one customer in your Rekhu
    // account, "Visitors", with its own monthly limit: set one there before you turn this on.
    'guests' => [
        'enabled' => (bool) env('REKHU_ASSISTANT_GUESTS', false),
        'files' => false,                   // visitors may attach files (see files.upload)
        'web' => false,                     // visitors may use the web lookups (see web)
        'knowers' => false,                 // visitors may use Rekhu's knowers (see knowers)
        'middleware' => explode(',', env('REKHU_ASSISTANT_GUEST_MIDDLEWARE', 'web')),
        'tenant' => ['id' => 'visitors', 'name' => 'Visitors'],
        'greeting' => null,                 // null: the greeting below
        'max_message' => 1000,              // characters per message
        'max_steps' => 4,                   // tool rounds per answer
        'per_visitor_per_day' => 20,        // messages
        'per_ip_per_hour' => 60,            // messages from one IP address, whatever the visitor id
        'total_per_day' => 1000,            // messages from all visitors together
    ],

    // Your quick questions for a fresh chat: a text, or one per language (['en' => …, 'ar' => …]). With "needs" (action
    // names) a question is only offered to users who may use one of those actions. The front end decides how they look.
    'quick' => [
        // 'Where is my last order?',
        // ['text' => ['en' => 'Cancel an order', 'nl' => 'Annuleer een bestelling'], 'needs' => ['cancel_order']],
    ],

    // How it introduces itself, and its colour in the widget.
    'name' => env('REKHU_ASSISTANT_NAME', 'Assistant'),
    // A text, or one per language: ['en' => 'Hi! …', 'ar' => 'مرحباً! …']; the widget shows the one for the user's language.
    'greeting' => 'Hi! Ask me anything about this app, or tell me what you want done.',
    'color' => '#4f46e5',

    // Your own rules for the assistant, in plain words: tone, what it never does, when it hands over to a person.
    'instructions' => '',

    // What the assistant may do: every controller method marked #[Tool], plus the routes you add here (by route name or
    // "VERB uri", for routes whose controller you do not want to touch, such as a vendor package's), minus the routes you
    // block (route name or uri patterns): blocked ones are never offered, whatever their attribute says. Each call still
    // runs through the route's own middleware and the user's rights.
    'actions' => [
        'include' => [
            // 'invoices.download' => 'Download an invoice as PDF',
            // 'POST api/orders/{order}/cancel' => ['description' => 'Cancel an order that has not shipped', 'confirm' => true],
        ],
        'exclude' => [
            // 'admin.*', '*.destroy', 'api/internal/*',
        ],
        // The actions php artisan rekhu:assistant:scan proposed, as you decided them (on or off). Yours to edit and to
        // commit; a new scan only adds routes it has not seen, switched off. #[Tool] and "include" win over it.
        'file' => null,                     // null: assistant/actions.php
    ],

    // Actions written for the assistant only (php artisan make:assistant-tool): a summary, a short list, a figure, with
    // no route of their own. Every Tool class in this folder is offered, as its authorize() allows.
    'tools' => [
        'path' => null,                     // null: app/Assistant/Tools
        'namespace' => null,                // null: App\Assistant\Tools
    ],

    // Files an action hands over (an export, a PDF): the user gets a download button with a signed link, valid this long.
    // They are kept in the cache, which all your servers share; null is the default cache store.
    'files' => [
        'minutes' => 15,
        'max_mb' => 20,
        'store' => env('REKHU_ASSISTANT_FILES_STORE'),

        // Files users attach in the chat: print files (PDF, AI, EPS, PSD, TIFF, JPG, PNG, SVG), lists, documents and zips,
        // which are unpacked. Kept on this disk with the conversation and removed with it. The assistant reads what each
        // one is (pages, page size, pixels, dpi, colour), sees small pictures and PDFs itself, and hands a file to an
        // action's file input by its id.
        'upload' => [
            'enabled' => (bool) env('REKHU_ASSISTANT_UPLOADS', true),
            'disk' => env('REKHU_ASSISTANT_UPLOAD_DISK', 'local'),
            'path' => 'rekhu-assistant/attachments',
            'max_mb' => 50,                 // one file
            'max_files' => 10,              // one message
            'types' => null,                // null: the print, picture, list and document types of Attachments::DEFAULT_TYPES
            'zip' => ['max_entries' => 200, 'max_mb' => 300],
            'to_model' => true,             // the model sees pictures (up to model_image_mb) and PDFs (up to model_pdf_mb) itself
            'model_image_mb' => 5,
            'model_pdf_mb' => 10,
        ],
    ],

    // The chat's look, for apps whose customers resell it. rekhu false: nothing in the chat names Rekhu or its knowers
    // (the widget, the cards, the texts); name, logo and colours per tenant with RekhuAssistant::brandUsing().
    'brand' => [
        'rekhu' => (bool) env('REKHU_ASSISTANT_REKHU_BRAND', true),
        'logo' => env('REKHU_ASSISTANT_LOGO'),      // an https address or a data: image
        'colors' => [],                             // accent, text, background, surface, border: '#rrggbb'
    ],

    // Rekhu's knowers as tools: with rekhu/core installed (php artisan rekhu:core --install=seshat), the assistant checks
    // and fixes the print files users attach (Seshat) and knows printing machines (Rekhu Machines), for the user's tenant.
    'knowers' => [
        'enabled' => (bool) env('REKHU_ASSISTANT_KNOWERS', true),
        'proof' => true,                    // Seshat's cards show the page with its trim, bleed and safe zone (one preview call each)
    ],

    // Looking things up on the internet, locked: nothing happens without a list of sites. Only these sites are searched
    // and read; at most per_answer lookups in one answer and per_day per user a day. It only reads: a GET without the
    // user's cookies or tokens, never a form, never an address inside the network, redirects only to allowed sites.
    // What a page says is data for the answer, never an instruction. A scope can have a "web" of its own.
    'web' => [
        'enabled' => (bool) env('REKHU_ASSISTANT_WEB', false),
        'purpose' => '',                    // what it is for, told to the assistant: "public supplier prices and paper specs"
        'sites' => [],                      // 'www.example.com', '*.example.org' (also example.org)
        'search' => false,                  // search on those sites, through Rekhu (charged per search)
        'read' => true,                     // read a page of those sites
        'per_answer' => 3,
        'per_day' => 50,
        'max_kb' => 300,
        'timeout' => 10,
    ],

    // Chats of their own in this app, each with its own instructions and only the actions its patterns name (action,
    // route name or address). The chat request sends scope=<name>; without it, the main chat with every action.
    'scopes' => [
        // 'marketplace' => ['instructions' => 'You help resellers compare suppliers and prices.', 'actions' => ['market.*', 'products'], 'model' => null],
    ],

    // Pages it may open: every named GET route, minus these name patterns, minus routes the user may not open.
    // A front end of its own (Nuxt, Next, a mobile app): its pages, opened at its own address. Each page is
    // ['path' => '/orders/{order}', 'label' => 'Order', 'help' => 'What it shows', 'can' => 'a gate, optional'];
    // RekhuAssistant::pagesUsing() adds pages from code, as the user may open them.
    'pages' => [
        // false: only the front end's pages below; Laravel's own GET routes are not offered (an API with its own front end)
        'routes' => (bool) env('REKHU_ASSISTANT_ROUTE_PAGES', true),
        'frontend' => [
            'url' => env('REKHU_ASSISTANT_FRONTEND_URL'),
            'list' => [],
        ],
        'exclude' => ['rekhu.*', 'login', 'logout', 'register', 'password.*', 'verification.*', 'sanctum.*', 'livewire.*',
            'ignition.*', 'telescope*', 'horizon.*', 'pulse*', 'debugbar.*', 'storage.*', 'up', 'filament.*.auth.*'],
        'limit' => 300,
    ],

    // The chat's own log, beside the app's: every question, every action the assistant ran with its input, outcome and
    // time, and every failure. storage/logs/rekhu-assistant-YYYY-MM-DD.log, kept 14 days; "channel" writes to one of
    // your logging channels instead. What actions returned is left out unless "results" is on: it can hold personal data.
    'log' => [
        'enabled' => (bool) env('REKHU_ASSISTANT_LOG', true),
        'channel' => env('REKHU_ASSISTANT_LOG_CHANNEL'),
        'path' => null,
        'days' => 14,
        'results' => false,
    ],

    // Under each answer, for signed-in users: the actions it ran, their input, outcome and time. null is on in the local
    // environment only; never shown in the public chat.
    'debug' => env('REKHU_ASSISTANT_DEBUG'),

    // Help files the assistant answers from: markdown, one topic per file. Empty means resources/rekhu/help.
    'help_path' => null,

    // How long a conversation is kept for its user, in minutes.
    'conversation_minutes' => 1440,

    // "Report a problem" under each answer: the user picks what went wrong and may say what they expected. The report
    // goes to Reports on the project's Assistant tab in your Rekhu account, with the question, the answer, the
    // conversation up to it and the actions it used (their input and whether they worked). Listen to
    // Rekhu\Assistant\Events\AnswerReported for a copy of your own.
    'reports' => [
        'enabled' => (bool) env('REKHU_ASSISTANT_REPORTS', true),
        // key => label: null is the built-in text; a text, or one per language: ['en' => …, 'ar' => …]
        'reasons' => [
            'wrong_answer' => null,
            'misunderstood' => null,
            'wrong_information' => null,
            'other' => null,
            // 'wrong_numbers' => ['en' => 'The numbers are wrong', 'ar' => 'الأرقام غير صحيحة'],
        ],
        'max_comment' => 1000,              // characters
        'per_user_per_hour' => 20,          // reports from one user or visitor
        'guests' => true,                   // visitors of the public chat may report too
        // what the actions returned is left out: it can hold your customers' personal data. true sends it, cut short.
        'include_results' => false,
    ],
];
