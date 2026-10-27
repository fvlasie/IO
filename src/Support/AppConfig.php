<?php

namespace IO\Support;

use IO\Clients\GibbonClient;
use RuntimeException;

class AppConfig
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function settingsPath(): string
    {
        return self::root() . '/data/settings.json';
    }

    public static function load(): array
    {
        $config = require self::root() . '/config/config.php';
        $saved = self::read();

        foreach (['gibbon', 'unifi', 'admin'] as $section) {
            if (!empty($saved[$section]) && is_array($saved[$section])) {
                $config[$section] = array_replace($config[$section] ?? [], $saved[$section]);
            }
        }

        return $config;
    }

    public static function gibbon(Logger $logger): GibbonClient
    {
        $gibbon = self::load()['gibbon'];

        return new GibbonClient(
            $gibbon['base_url'] ?? '',
            $gibbon['users_endpoint'] ?? '/modules/AccessAPI/users.php',
            $gibbon['api_key'] ?? '',
            $gibbon['api_secret'] ?? '',
            $logger
        );
    }

    /**
     * Merge a partial settings overlay into data/settings.json.
     * Omitted keys are left as they are, so a blank password field does not wipe a saved secret.
     */
    public static function saveOverlay(array $overlay): void
    {
        $current = self::read();

        foreach ($overlay as $section => $values) {
            if (!is_array($values)) {
                continue;
            }
            $current[$section] = array_replace($current[$section] ?? [], $values);
        }

        $path = self::settingsPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the data directory');
        }

        $json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Could not encode settings');
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not write settings');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Could not replace settings');
        }
    }

    private static function read(): array
    {
        $path = self::settingsPath();
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
