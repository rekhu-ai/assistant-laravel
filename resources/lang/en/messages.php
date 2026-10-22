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
    'working' => 'Working on it…',
    'help' => 'Looking in the help…',
    'report' => 'Report a problem',
    'report_title' => 'What went wrong?',
    'reason_wrong_answer' => 'The answer is wrong',
    'reason_misunderstood' => 'It did not understand what I meant',
    'reason_wrong_information' => 'The information is wrong',
    'reason_other' => 'Something else',
    'report_placeholder' => 'What did you expect instead? (optional)',
    'report_send' => 'Send report',
    'report_cancel' => 'Cancel',
    'report_thanks' => 'Thanks, we will look into it.',
    'report_failed' => 'Could not send the report.',
    'report_not_found' => 'There is no such answer in this conversation.',
    'report_too_many' => 'That was a lot of reports. Try again later.',
];
