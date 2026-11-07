<?php
declare(strict_types=1);

function auth_check(): bool
{
    return !empty($_SESSION['admin_id']);
}

function auth_user(): ?array
{
    if (!auth_check()) return null;
    $stmt = db()->prepare(
        'SELECT id, username, email, full_name, role, is_active FROM admins WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $row = $stmt->fetch();
    if (!$row || !(int)$row['is_active']) {
        auth_logout();
        return null;
    }
    return $row;
}

function auth_require(): void
{
    if (!auth_check()) {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php';
        redirect('/admin/login.php');
    }
    if (auth_user() === null) {
        redirect('/admin/login.php');
    }
}

function auth_require_role(string $role): void
{
    auth_require();
    $u = auth_user();
    if (!$u || $u['role'] !== $role) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function auth_login(string $username, string $password): array
{
    $username = trim($username);
    $ip       = client_ip();

    if (auth_rate_limited($username, $ip)) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Please try again in 15 minutes.'];
    }
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Please enter both username and password.'];
    }

    $stmt = db()->prepare(
        'SELECT id, username, password_hash, is_active, role
         FROM admins
         WHERE (username = ? OR email = ?)
         LIMIT 1'
    );
    $stmt->execute([$username, $username]);
    $row = $stmt->fetch();

    if (!$row || !(int)$row['is_active'] || !password_verify($password, $row['password_hash'])) {
        auth_record_attempt($username, $ip, false);
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['id']]);
    }

    auth_record_attempt($username, $ip, true);

    session_regenerate_id(true);
    $_SESSION['admin_id']       = (int)$row['id'];
    $_SESSION['admin_username'] = $row['username'];
    $_SESSION['admin_role']     = $row['role'];
    $_SESSION['admin_login_at'] = time();

    db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')
        ->execute([(int)$row['id']]);
    log_activity('admin.login', 'admin', (int)$row['id']);

    return ['ok' => true];
}

function auth_logout(): void
{
    if (!empty($_SESSION['admin_id'])) {
        log_activity('admin.logout', 'admin', (int)$_SESSION['admin_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function auth_rate_limited(string $username, string $ip): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0
               AND (username = ? OR ip_address = ?)
               AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$username, $ip]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Throwable $e) {
        return false;
    }
}

function auth_record_attempt(string $username, string $ip, bool $success): void
{
    try {
        db()->prepare(
            'INSERT INTO login_attempts (username, ip_address, success, attempted_at)
             VALUES (?, ?, ?, NOW())'
        )->execute([$username, $ip, $success ? 1 : 0]);
    } catch (Throwable $e) {
        // best effort
    }
}