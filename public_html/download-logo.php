<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$type = $_GET['type'] ?? 'institute';

$allowed = [
    'institute'         => 'institute_logo',
    'academic_section'  => 'section_logo',
    'institution'       => 'institution_logo',
];

if (!isset($allowed[$type])) {
    http_response_code(404);
    exit('Not found.');
}

$settingKey = $allowed[$type];
$path       = (string)app_setting($settingKey, '');

if ($path === '') {
    http_response_code(404);
    exit('Not found.');
}

/* Accept only paths inside storage/uploads. */
$full = realpath($path);
$base = realpath(BW_STORAGE_DIR . '/uploads');
if ($full === false || $base === false || !str_starts_with($full, $base)) {
    http_response_code(404);
    exit('Not found.');
}
if (!is_file($full)) {
    http_response_code(404);
    exit('Not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($full) ?: 'application/octet-stream';

$imageMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
if (!in_array($mime, $imageMimes, true)) {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($full);
exit;