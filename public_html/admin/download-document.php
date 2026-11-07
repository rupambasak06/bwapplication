<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

auth_require();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid request.'); }

$stmt = db()->prepare(
    'SELECT d.*, a.application_id
     FROM application_documents d
     JOIN applications a ON a.id = d.application_id
     WHERE d.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); exit('Document not found.'); }

$real = realpath($doc['storage_path']);
$base = realpath(BW_STORAGE_DIR . '/uploads');
if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
    http_response_code(404);
    exit('Document file not found.');
}

$mime = (string)$doc['mime_type'];
$allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Unsupported document type.');
}

$safeName = safe_basename($doc['original_filename']);
if ($safeName === '') $safeName = 'document';

log_activity('document.downloaded', 'application', (int)$doc['application_id'], ['document_id' => $id]);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
exit;