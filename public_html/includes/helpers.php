<?php
declare(strict_types=1);

/** HTML escape. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect and exit. */
function redirect(string $path): void
{
    $url = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))
        ? $path
        : absolute_url($path);
    if (!headers_sent()) {
        header('Location: ' . $url, true, 302);
    }
    exit;
}

/** Absolute URL for a path relative to the base. */
function absolute_url(string $path = ''): string
{
    $base = (string)app_config('base_url', '');
    if ($base === '') {
        $base = detect_base_url();
    }
    $base = rtrim($base, '/');
    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }
    return str_starts_with($path, '/') ? $base . $path : $base . '/' . $path;
}

/** Auto-detect base URL. */
function detect_base_url(): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/** Is the current request HTTPS? */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

/** Read a config value. */
function app_config(string $key, $default = null)
{
    $app = $GLOBALS['BW_APP'] ?? [];
    return array_key_exists($key, $app) ? $app[$key] : $default;
}

/** Best-effort client IP for rate limiting. */
function client_ip(): string
{
    foreach ([
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR']  ?? null,
        $_SERVER['REMOTE_ADDR']           ?? null,
    ] as $v) {
        if (!$v) continue;
        $first = trim(explode(',', $v)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return '0.0.0.0';
}

function request_method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
function is_post(): bool          { return request_method() === 'POST'; }

function request_input(string $key, $default = '')
{
    if (isset($_POST[$key])) return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
    if (isset($_GET[$key]))  return is_string($_GET[$key])  ? trim($_GET[$key])  : $_GET[$key];
    return $default;
}

function post(string $key, $default = null)
{
    if (!isset($_POST[$key])) return $default;
    return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    return (int)$v;
}

/* ---------- Flash messages ---------- */
function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}
function flash_get_all(): array
{
    $all = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $all;
}

/* ---------- Misc ---------- */
function random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}
function random_hex(int $bytes = 16): string { return bin2hex(random_bytes($bytes)); }
function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

function format_datetime(?string $value, string $format = 'd M Y, H:i'): string
{
    if (!$value) return '';
    $ts = strtotime($value . ' UTC');
    return $ts ? date($format, $ts) : (string)$value;
}
function format_date(?string $value): string { return format_datetime($value, 'd M Y'); }

function format_money($amount, string $currency = 'INR'): string
{
    $n = (float)$amount;
    if ($currency === 'INR') return '₹' . number_format($n, 2);
    return $currency . ' ' . number_format($n, 2);
}

function safe_basename(string $filename): string
{
    $filename = str_replace(['\\', '/', "\0"], '', $filename);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'file';
    $filename = trim($filename, '._-');
    return $filename !== '' ? $filename : 'file';
}

/* ---------- Audit log ---------- */
function log_activity(string $action, ?string $targetType = null, ?int $targetId = null, array $details = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (admin_id, action, target_type, target_id, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $_SESSION['admin_id'] ?? null,
            $action,
            $targetType,
            $targetId,
            !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('[audit] ' . $e->getMessage());
    }
}

/* ---------- Secret encryption (AES-256-GCM using APP_KEY) ---------- */
function encrypt_secret(string $plain): string
{
    $key = (string)app_config('app_key', '');
    if ($key === '') {
        throw new RuntimeException('APP_KEY is not configured.');
    }
    $keyBytes = hash('sha256', $key, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $keyBytes, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return base64_encode($iv . $tag . $ct);
}

function decrypt_secret(string $encoded): string
{
    $key = (string)app_config('app_key', '');
    if ($key === '' || $encoded === '') return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) return '';
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $keyBytes = hash('sha256', $key, true);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $keyBytes, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

/* ---------- JSON & method helpers ---------- */
function json_response($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_method(string $method): void
{
    if (request_method() === strtoupper($method)) return;
    if (in_array(request_method(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    http_response_code(405);
    exit('Method not allowed.');
}

/* ---------- Old form input ---------- */
function old(string $key, $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}
function old_set(array $data): void   { $_SESSION['_old'] = $data; }
function old_clear(): void            { unset($_SESSION['_old']); }

/* ---------- File helpers ---------- */
function ensure_dir(string $path): string
{
    if (!is_dir($path)) {
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create directory: ' . $path);
        }
    }
    return $path;
}

/* ---------- Status badge helper (used by applications & check-status) ---------- */
function bw_status_badge(string $status): string
{
    $map = [
        'draft'               => 'badge-neutral',
        'submitted'           => 'badge-info',
        'under_review'        => 'badge-warning',
        'documents_required'  => 'badge-warning',
        'approved'            => 'badge-success',
        'rejected'            => 'badge-danger',
        'payment_pending'     => 'badge-warning',
    ];
    $class = $map[$status] ?? 'badge-neutral';
    return '<span class="badge ' . $class . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}