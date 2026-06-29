<?php

return [
    // 是否开启权限验证
    'enabled' => true,

    // 推流权限管理
    'publish' => [
        // 是否开启推流权限验证
        'require_auth' => true,
        // 合法的秘钥列表
        'stream_keys' => [
            '123456',
//            'stream_key_abc',
        ],
    ],

    // 拉流权限管理，已删除此模块
    'play' => [
        'require_auth' => false,
    ],

    // 全局配置
    'global' => [
        // 允许创建的直播app
        'allowed_apps' => [
//            'live',
//            'a'
        ],
        // 不允许创建的app
        'deny_apps' => [],
    ],
];