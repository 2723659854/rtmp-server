<?php

namespace MediaServer\Http;

class AuthHelper
{
    static protected $authConfig = null;

    static protected function loadAuthConfig()
    {
        if (self::$authConfig === null) {
            $configPath = dirname(dirname(__DIR__)) . '/auth_config.php';
            if (file_exists($configPath)) {
                self::$authConfig = require $configPath;
            } else {
                self::$authConfig = [
                    'enabled' => false,
                    'publish' => ['require_auth' => false, 'stream_keys' => []],
                    'play' => ['require_auth' => false],
                    'global' => []
                ];
            }
        }
        return self::$authConfig;
    }

    static public function checkPublishAuth($path, $request)
    {
        $config = self::loadAuthConfig();
        if (!$config['enabled']) return true;

        $appName = '';
        $pathParts = explode('/', trim($path, '/'));
        if (count($pathParts) >= 1) {
            $appName = $pathParts[0];
        }

        if (!empty($config['global']['allowed_apps']) && !in_array($appName, $config['global']['allowed_apps'])) {
            logger()->warning("[auth] App not allowed: {$appName} path={$path}");
            return false;
        }

        if (!empty($config['global']['deny_apps']) && in_array($appName, $config['global']['deny_apps'])) {
            logger()->warning("[auth] App denied: {$appName} path={$path}");
            return false;
        }

        $publishConfig = $config['publish'] ?? [];
        if (!$publishConfig['require_auth']) return true;

        $streamKey = $request->get('key') ?? $request->get('streamKey') ?? $request->get('secret') ?? '';
        if (!empty($publishConfig['stream_keys']) && in_array($streamKey, $publishConfig['stream_keys'])) {
            logger()->info("[auth] Publish allowed by stream key: path={$path}");
            return true;
        }

        logger()->warning("[auth] Publish denied: path={$path}");
        return false;
    }
}