<?php
declare(strict_types=1);

/** Return (and lazily create) the current session CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Render a hidden CSRF input. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Verify a submitted token against the session. */
function csrf_verify(?string $token = null): bool
{
    $token  = $token ?? ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $stored = $_SESSION['_csrf'] ?? '';
    if (!$stored || !is_string($token) || $token === '') return false;
    return hash_equals($stored, $token);
}

/** Hard-fail when CSRF fails. */
function csrf_require(): void
{
    if (csrf_verify()) return;
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        json_response(['ok' => false, 'error' => 'Security token expired. Please refresh the page.'], 419);
    }
    http_response_code(419);
    exit('Security token expired. Please go back, refresh the page, and try again.');
}