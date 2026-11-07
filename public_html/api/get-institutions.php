<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

if (request_method() !== 'GET') json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);

$sectionId = (int)($_GET['section_id'] ?? 0);
if ($sectionId <= 0) json_response(['ok' => false, 'error' => 'Invalid section.'], 400);

try {
    $stmt = db()->prepare(
        'SELECT 1 FROM academic_sections WHERE id = ? AND is_active = 1 AND is_archived = 0 LIMIT 1'
    );
    $stmt->execute([$sectionId]);
    if (!$stmt->fetchColumn()) json_response(['ok' => false, 'error' => 'Section not available.'], 404);

    $stmt = db()->prepare(
        'SELECT id, name FROM institutions
         WHERE academic_section_id = ? AND is_active = 1 AND is_archived = 0
         ORDER BY display_order, name'
    );
    $stmt->execute([$sectionId]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Server error.'], 500);
}

json_response(['ok' => true, 'institutions' => $rows]);