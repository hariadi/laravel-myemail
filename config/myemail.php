<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Endpoint
    |--------------------------------------------------------------------------
    |
    | Full URL of the MYEmail send endpoint, including the path.
    | Example: https://resend.miniapp.malaysia.gov.my/api/v1/emails
    |
    | Named "endpoint" rather than "url" on purpose: Laravel's MailManager
    | treats a "url" key in a mailer block as a DSN and overwrites the
    | transport with the URL scheme, so that key cannot be used here.
    |
    */

    'endpoint' => env('MYEMAIL_URL'),

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    |
    | Project-scoped API key, sent as a bearer token. Keep it in your secret
    | store; it is shown only once when created in the MYEmail dashboard.
    |
    */

    'token' => env('MYEMAIL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds to wait when connecting and when awaiting a response. Keep these
    | bounded so a slow API cannot hold a queue worker open indefinitely.
    |
    */

    'connect_timeout' => env('MYEMAIL_CONNECT_TIMEOUT', 5),

    'timeout' => env('MYEMAIL_TIMEOUT', 15),

];
