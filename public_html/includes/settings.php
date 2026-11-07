<?php
declare(strict_types=1);

/** In-request settings cache (also populated after writes). */
function settings_cache(): array
{
    if (is_array($GLOBALS['BW_SETTINGS_CACHE'] ?? null)) {
        return $GLOBALS['BW_SETTINGS_CACHE'];
    }
    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $cache = [];
        foreach ($rows as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
        $GLOBALS['BW_SETTINGS_CACHE'] = $cache;
        return $cache;
    } catch (Throwable $e) {
        $GLOBALS['BW_SETTINGS_CACHE'] = [];
        return [];
    }
}

function app_setting(string $key, $default = null)
{
    $cache = settings_cache();
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting(string $key, $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute([$key, $value === null ? '' : (string)$value]);
    settings_cache();
    $GLOBALS['BW_SETTINGS_CACHE'][$key] = (string)$value;
}

function invalidate_settings_cache(): void
{
    $GLOBALS['BW_SETTINGS_CACHE'] = null;
}