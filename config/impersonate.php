<?php

declare(strict_types=1);

return [
    'guard' => 'web',

    'session_key' => 'impersonation_context',

    'default_ttl' => null,

    'prevent_privilege_escalation' => true,

    'redirect_after' => [
        'start' => '/',
        'stop' => '/',
    ],
];
