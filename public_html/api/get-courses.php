<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

if (request_method() !== 'GET') json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);

$sectionId     = (int)($_GET['section_id']     ?? 0);
$institutionId = (int)($_GET['institution_id'] ?? 0);
if ($sectionId <= 0 || $institutionId <= 0) json_response(['ok' => false, 'error' => 'Missing parameters.'], 400);

try {
    // Validate the exact (section, institution) pair exists and is active
    $stmt = db()->prepare(
        'SELECT 1 FROM institutions
         WHERE id = ? AND academic_section_id = ? AND is_active = 1 AND is_archived = 0
         LIMIT 1'
    );
    $stmt->execute([$institutionId, $sectionId]);
    if (!$stmt->fetchColumn()) json_response(['ok' => false, 'error' => 'Institution not available.'], 404);

    $stmt = db()->prepare(
        'SELECT id, name, duration, price FROM courses
         WHERE academic_section_id = ? AND institution_id = ?
           AND is_active = 1 AND is_archived = 0
         ORDER BY display_order, name'
    );
    $stmt->execute([$sectionId, $institutionId]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Server error.'], 500);
}

json_response(['ok' => true, 'courses' => $rows]);