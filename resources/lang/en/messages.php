<?php

// What the users of your app read. Publish with: php artisan vendor:publish --tag=rekhu-assistant-lang
return [
    'unavailable' => 'The assistant is not available right now. Please try again in a moment.',
    'empty' => 'Say something first.',
    'protocol' => 'This app speaks Rekhu Assistant protocol :version.',
    'visitor_id' => 'The public chat needs a visitor id (X-Rekhu-Visitor).',
    'limit_visitor' => 'You have sent the most messages for today. Please come back tomorrow, or sign in.',
    'limit_ip' => 'That was a lot of messages at once. Wait a while and try again.',
    'limit_total' => 'The assistant is very busy today. Please try again tomorrow, or sign in.',
];
