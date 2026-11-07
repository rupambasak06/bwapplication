<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

if (request_method() !== 'GET') json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);

$sectionId     = (int)($_GET['section_id']     ?? 0);
$institutionId = (int)($_GET['institution_id'] ?? 0);
$courseId      = (int)($_GET['course_id']      ?? 0);
if ($sectionId <= 0 || $institutionId <= 0 || $courseId <= 0) {
    json_response(['ok' => false, 'error' => 'Missing parameters.'], 400);
}

try {
    $stmt = db()->prepare(
        'SELECT c.max_extra_subjects
         FROM courses c
         JOIN institutions i ON i.id = c.institution_id
         WHERE c.id = ? AND c.academic_section_id = ? AND c.institution_id = ?
           AND c.is_active = 1 AND c.is_archived = 0
           AND i.is_active = 1 AND i.is_archived = 0
         LIMIT 1'
    );
    $stmt->execute([$courseId, $sectionId, $institutionId]);
    $course = $stmt->fetch();
    if (!$course) json_response(['ok' => false, 'error' => 'Course not available.'], 404);

    $stmt = db()->prepare(
        'SELECT id, name, type FROM subjects
         WHERE course_id = ? AND is_active = 1
         ORDER BY type, display_order, id'
    );
    $stmt->execute([$courseId]);
    $rows = $stmt->fetchAll();

    $compulsory = [];
    $extra      = [];
    foreach ($rows as $r) {
        if ($r['type'] === 'compulsory') $compulsory[] = ['id' => (int)$r['id'], 'name' => $r['name']];
        else                             $extra[]      = ['id' => (int)$r['id'], 'name' => $r['name']];
    }

    json_response([
        'ok'         => true,
        'compulsory' => $compulsory,
        'extra'      => $extra,
        'max_extra'  => (int)$course['max_extra_subjects'],
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Server error.'], 500);
}