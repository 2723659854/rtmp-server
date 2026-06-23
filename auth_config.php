<?php

return [
    'enabled' => true,

    'publish' => [
        'require_auth' => true,
        'stream_keys' => [
            '123456',
            'stream_key_abc',
        ],
    ],

    'play' => [
        'require_auth' => false,
    ],

    'global' => [
        'allowed_apps' => ['live','a'],
        'deny_apps' => [],
    ],
];