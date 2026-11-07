<?php
declare(strict_types=1);

/**
 * Brainwavers bootstrap.
 * Loaded by every entry point in /public and /public/admin.
 */

if (defined('BRAINWAVERS_BOOTSTRAPPED')) {
    return;
}
define('BRAINWAVERS_BOOTSTRAPPED', true);

/* ---------- Path constants ---------- */
define('BW_ROOT',         dirname(__DIR__));
define('BW_CONFIG_DIR',   BW_ROOT . '/config');
define('BW_INCLUDES_DIR', BW_ROOT . '/includes');
define('BW_STORAGE_DIR',  BW_ROOT . '/storage');
define('BW_PUBLIC_DIR',   BW_ROOT . '/public');
define('BW_VENDOR_DIR',   BW_ROOT . '/vendor');

/* ---------- Load app config ---------- */
$bwAppConfigFile = BW_CONFIG_DIR . '/app.php';
if (!is_file($bwAppConfigFile)) {
    http_response_code(500);
    exit('Application configuration is missing.');
}
/** @var array $BW_APP */
$BW_APP = require $bwAppConfigFile;
$GLOBALS['BW_APP'] = $BW_APP;

/* ---------- Timezone & encoding ---------- */
date_default_timezone_set($BW_APP['timezone'] ?? 'UTC');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

/* ---------- Error handling ---------- */
$env = $BW_APP['environment'] ?? 'production';
if ($env === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (is_dir(BW_STORAGE_DIR . '/logs') || @mkdir(BW_STORAGE_DIR . '/logs', 0755, true)) {
        ini_set('error_log', BW_STORAGE_DIR . '/logs/app.log');
    }
}

set_exception_handler(function (Throwable $e) use ($env) {
    error_log(sprintf(
        '[Unhandled] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if ($env === 'development') {
        echo '<!doctype html><html><body><pre style="padding:20px;background:#fee;color:#900;white-space:pre-wrap;">';
        echo htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8');
        echo '</pre></body></html>';
    } else {
        echo '<!doctype html><html><body style="font-family:sans-serif;padding:40px;text-align:center;color:#333;">';
        echo '<h1 style="color:#1e3a8a;">Something went wrong</h1>';
        echo '<p>An unexpected error occurred. Please try again shortly.</p>';
        echo '</body></html>';
    }
    exit;
});

/* ---------- Load shared helpers (order matters) ---------- */
require_once BW_INCLUDES_DIR . '/helpers.php';
require_once BW_INCLUDES_DIR . '/csrf.php';
require_once BW_INCLUDES_DIR . '/settings.php';
require_once BW_INCLUDES_DIR . '/validation.php';
require_once BW_INCLUDES_DIR . '/auth.php';

/* ---------- Session ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_name($BW_APP['session_name'] ?? 'bwsid');
    $params = [
        'lifetime' => (int)($BW_APP['session_lifetime'] ?? 0),
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($params);
    } else {
        session_set_cookie_params(
            $params['lifetime'],
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    @session_start();
}

/* ---------- Lazy-loaded PDO ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dbConfigFile = BW_CONFIG_DIR . '/database.php';
    if (!is_file($dbConfigFile)) {
        throw new RuntimeException('Database configuration is missing.');
    }
    $cfg = require $dbConfigFile;
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string)($cfg['host'] ?? 'localhost'),
        (int)($cfg['port'] ?? 3306),
        (string)($cfg['name'] ?? ''),
        (string)($cfg['charset'] ?? 'utf8mb4')
    );
    $pdo = new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}