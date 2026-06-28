<?php

return [
    'enabled' => false,

    'default' => [
        'enabled' => false,
        'url' => 'ws://127.0.0.1:8501/{path}',
    ],

    'streams' => [
        '/live/stream1' => [
            'enabled' => false,
            'url' => 'ws://remote-server:8501/live/stream1',
        ],
    ],
];
