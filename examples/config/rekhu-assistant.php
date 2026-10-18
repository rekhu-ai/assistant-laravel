<?php

// An example of config/rekhu-assistant.php with the options most apps change. Publish your own with
// php artisan vendor:publish --tag=rekhu-assistant-config and change what you need; the rest keeps its default.
return [
    'key' => env('REKHU_ASSISTANT_KEY'),

    // An API with its own front end: the chat under api/ (exempt from CSRF), behind your API guard.
    'path' => env('REKHU_ASSISTANT_PATH', 'api/rekhu/assistant'),
    'middleware' => explode(',', env('REKHU_ASSISTANT_MIDDLEWARE', 'api,auth:sanctum')),

    // Rules that belong with the code. How the assistant behaves for your users (tone, language, what it never does)
    // is better kept in the knowledge of the project in your Rekhu account: it changes without a deploy.
    'instructions' => 'Never give legal or tax advice; point to the support page instead.',

    // Routes that become actions without touching their controller, and routes never offered.
    'actions' => [
        'include' => [
            'invoices.download' => [
                'description' => 'The PDF of one invoice, by its id from the invoices list.',
                'label' => ['en' => 'Getting the invoice', 'nl' => 'Ik haal de factuur op'],
            ],
        ],
        'exclude' => ['admin.*', 'horizon.*', '*.force-delete'],
    ],

    // Questions a user can click in a fresh chat; with "needs", only for users who may use those actions.
    'quick' => [
        ['text' => ['en' => 'Where is my last order?', 'nl' => 'Waar is mijn laatste bestelling?'], 'needs' => ['list_orders']],
        ['text' => ['en' => 'Change my delivery address', 'nl' => 'Wijzig mijn afleveradres'], 'needs' => ['change_address']],
        ['en' => 'How do returns work?', 'nl' => 'Hoe werkt retourneren?'],
    ],

    'greeting' => ['en' => 'Hi! Ask me about your orders, invoices and deliveries.', 'nl' => 'Hoi! Vraag me naar je bestellingen, facturen en leveringen.'],

    // The front end's pages: where they open (RekhuAssistant::pagesUsing() lists them), and no Laravel pages of its own.
    'pages' => [
        'routes' => (bool) env('REKHU_ASSISTANT_ROUTE_PAGES', false),
        'frontend' => ['url' => env('REKHU_ASSISTANT_FRONTEND_URL', 'https://app.example.com'), 'list' => []],
    ],
];
