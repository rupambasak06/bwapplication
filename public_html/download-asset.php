<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$kind = (string)($_GET['kind'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($kind, ['section', 'institution'], true)) {
    http_response_code(404);
    exit('Not found.');
}

$path = '';
try {
    if ($kind === 'section') {
        $stmt = db()->prepare('SELECT image_path FROM academic_sections WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $path = (string)$stmt->fetchColumn();
    } elseif ($kind === 'institution') {
        $stmt = db()->prepare('SELECT logo_path FROM institutions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $path = (string)$stmt->fetchColumn();
    }
} catch (Throwable $e) {
    http_response_code(404);
    exit('Not found.');
}

if ($path === '') {
    http_response_code(404);
    exit('Not found.');
}

$real = realpath($path);
$base = realpath(BW_STORAGE_DIR . '/uploads');

if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
    http_response_code(404);
    exit('Not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($real) ?: 'application/octet-stream';

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;