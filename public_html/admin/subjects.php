<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

auth_require();

$currentPage = 'subjects.php';

$courseId = (int)($_GET['course_id'] ?? ($_POST['course_id'] ?? 0));
$mode     = $courseId > 0 ? 'edit' : 'picker';

$page_title = $mode === 'edit' ? 'Configure Subjects' : 'Subjects';

/* -------------------------------------------------------------------
   PICKER MODE data
------------------------------------------------------------------- */
$sections      = [];
$institutions  = [];
$courses       = [];
if ($mode === 'picker') {
    try {
        $sections = db()->query(
            'SELECT id, name FROM academic_sections
             WHERE is_archived = 0 ORDER BY display_order, name'
        )->fetchAll();

        $institutions = db()->query(
            'SELECT id, academic_section_id, name
             FROM institutions WHERE is_archived = 0
             ORDER BY display_order, name'
        )->fetchAll();

        $courses = db()->query(
            'SELECT id, institution_id, academic_section_id, name, duration
             FROM courses WHERE is_archived = 0
             ORDER BY display_order, name'
        )->fetchAll();
    } catch (Throwable $e) { /* ignore */ }
}

/* -------------------------------------------------------------------
   EDIT MODE — load course + hierarchy + subjects
------------------------------------------------------------------- */
$course         = null;
$section        = null;
$institution    = null;
$compulsoryRows = [];
$extraRows      = [];
$errors         = [];
$warnings       = [];

if ($mode === 'edit') {
    $stmt = db()->prepare(
        'SELECT c.*,
                s.name AS section_name,  s.is_active AS section_active, s.is_archived AS section_archived,
                i.name AS institution_name, i.is_active AS institution_active, i.is_archived AS institution_archived
         FROM courses c
         JOIN academic_sections s ON s.id = c.academic_section_id
         JOIN institutions i      ON i.id = c.institution_id
         WHERE c.id = ? LIMIT 1'
    );
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();

    if (!$course) {
        flash_set('error', 'Course not found.');
        redirect('/admin/subjects.php');
    }
}

/* -------------------------------------------------------------------
   POST — save subjects
------------------------------------------------------------------- */
if (is_post() && $mode === 'edit') {
    csrf_require();

    $maxExtra = post_int('max_extra_subjects', 0);

    $cNames = $_POST['compulsory_names'] ?? [];
    $cIds   = $_POST['compulsory_ids']   ?? [];
    $eNames = $_POST['extra_names']      ?? [];
    $eIds   = $_POST['extra_ids']        ?? [];

    if (!is_array($cNames)) $cNames = [];
    if (!is_array($cIds))   $cIds   = [];
    if (!is_array($eNames)) $eNames = [];
    if (!is_array($eIds))   $eIds   = [];

    // Normalize into parallel arrays
    $compulsoryRows = [];
    foreach ($cNames as $i => $name) {
        $compulsoryRows[] = [
            'id'   => (int)($cIds[$i] ?? 0),
            'name' => trim((string)$name),
        ];
    }
    $extraRows = [];
    foreach ($eNames as $i => $name) {
        $extraRows[] = [
            'id'   => (int)($eIds[$i] ?? 0),
            'name' => trim((string)$name),
        ];
    }

    // Drop trailing empties; keep the rest
    $compulsoryRows = array_values(array_filter($compulsoryRows, fn($r) => $r['name'] !== '' || $r['id'] > 0));
    $extraRows      = array_values(array_filter($extraRows,      fn($r) => $r['name'] !== '' || $r['id'] > 0));

    if ($maxExtra < 0 || $maxExtra > 100) {
        $errors['max_extra_subjects'] = 'Maximum extra subjects must be between 0 and 100.';
    }

    // Names: length ≤ 200, no reserved prefix, no duplicates within type
    foreach (['compulsory' => $compulsoryRows, 'extra' => $extraRows] as $type => $rows) {
        $seen = [];
        foreach ($rows as $idx => $r) {
            if ($r['name'] === '') {
                // Empty row that still has an ID → treat as removal (skip silently)
                continue;
            }
            if (mb_strlen($r['name']) > 200) {
                $errors[$type . '_names'] = 'Subject names must be at most 200 characters.';
            }
            if (str_starts_with($r['name'], '__')) {
                $errors[$type . '_names'] = 'Subject names cannot start with two underscores (reserved prefix).';
            }
            $key = mb_strtolower($r['name']);
            if (isset($seen[$key])) {
                $errors[$type . '_names'] = 'Duplicate subject name: "' . $r['name'] . '". Each subject must have a unique name.';
            }
            $seen[$key] = true;
        }
    }

    if (empty($errors)) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            foreach (['compulsory' => $compulsoryRows, 'extra' => $extraRows] as $type => $rows) {
                // 1) Fetch existing subject IDs for this course & type
                $stmt = $pdo->prepare('SELECT id FROM subjects WHERE course_id = ? AND type = ?');
                $stmt->execute([$courseId, $type]);
                $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

                // 2) Rename existing subjects to temporary names (avoids unique-key collisions on reorders/swaps)
                if (!empty($existingIds)) {
                    $pdo->prepare('UPDATE subjects SET name = CONCAT("__tmp_", id) WHERE course_id = ? AND type = ?')
                        ->execute([$courseId, $type]);
                }

                // 3) Delete removed subjects (IDs in existing but not in submitted)
                $keptIds = [];
                foreach ($rows as $r) {
                    if ($r['id'] > 0) $keptIds[] = $r['id'];
                }
                $toDelete = array_diff($existingIds, $keptIds);
                if (!empty($toDelete)) {
                    $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                    $pdo->prepare("DELETE FROM subjects WHERE id IN ($placeholders)")
                        ->execute(array_values($toDelete));
                }

                // 4) Update kept rows and insert new rows (display_order = position)
                $update = $pdo->prepare('UPDATE subjects SET name = ?, display_order = ?, is_active = 1 WHERE id = ?');
                $insert = $pdo->prepare(
                    'INSERT INTO subjects
                        (academic_section_id, institution_id, course_id, name, type, display_order, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
                );

                $position = 0;
                foreach ($rows as $r) {
                    if ($r['name'] === '') continue;   // skip empty rows
                    $position++;
                    if ($r['id'] > 0 && in_array($r['id'], $existingIds, true)) {
                        $update->execute([$r['name'], $position, $r['id']]);
                    } else {
                        $insert->execute([
                            (int)$course['academic_section_id'],
                            (int)$course['institution_id'],
                            $courseId,
                            $r['name'],
                            $type,
                            $position,
                        ]);
                    }
                }
            }

            // 5) Update courses.max_extra_subjects
            $pdo->prepare('UPDATE courses SET max_extra_subjects = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$maxExtra, $courseId]);

            $pdo->commit();

            log_activity(
                'subjects.saved',
                'course',
                $courseId,
                ['compulsory' => count($compulsoryRows), 'extra' => count($extraRows)]
            );
            flash_set('success', 'Subjects saved successfully.');
            redirect('/admin/subjects.php?course_id=' . $courseId);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[subjects save] ' . $e->getMessage());
            $errors['general'] = 'Could not save subjects. Please try again.';
        }
    }
}

/* -------------------------------------------------------------------
   Build rows for rendering (POST on error → DB on load)
------------------------------------------------------------------- */
if ($mode === 'edit' && !(is_post() && !empty($errors))) {
    $stmt = db()->prepare(
        'SELECT id, name FROM subjects
         WHERE course_id = ? AND type = ?
         ORDER BY display_order, id'
    );
    $stmt->execute([$courseId, 'compulsory']);
    $compulsoryRows = $stmt->fetchAll();

    $stmt->execute([$courseId, 'extra']);
    $extraRows = $stmt->fetchAll();
}

// Ensure at least one blank row for new configurations
if (empty($compulsoryRows)) {
    $compulsoryRows = [['id' => 0, 'name' => '']];
}
if (empty($extraRows)) {
    $extraRows = [['id' => 0, 'name' => '']];
}

// Warnings to display on the edit page
if ($mode === 'edit' && $course) {
    $realCompulsory = array_filter($compulsoryRows, fn($r) => $r['name'] !== '');
    $realExtra      = array_filter($extraRows,      fn($r) => $r['name'] !== '');
    $maxExtra       = (int)$course['max_extra_subjects'];
    $extraCount     = count($realExtra);

    if (empty($realCompulsory)) {
        $warnings[] = 'No compulsory subjects are configured for this course. Applicants will receive no automatic subject selections.';
    }
    if ($maxExtra > 0 && $extraCount === 0) {
        $warnings[] = 'Maximum extra subjects is set to ' . $maxExtra . ', but no extra subjects are configured. Applicants will see no choices.';
    } elseif ($maxExtra > $extraCount) {
        $warnings[] = 'Maximum extra subjects is set to ' . $maxExtra . ', but only ' . $extraCount . ' extra subject(s) are configured. Applicants will be limited to ' . $extraCount . '.';
    }
    if ($maxExtra === 0 && $extraCount > 0) {
        $warnings[] = 'Maximum extra subjects is 0. The ' . $extraCount . ' configured extra subject(s) will not be offered to applicants.';
    }
    if ((int)$course['section_archived'] === 1) {
        $warnings[] = 'The academic section for this course has been archived.';
    }
    if ((int)$course['institution_archived'] === 1) {
        $warnings[] = 'The institution for this course has been archived.';
    }
    if ((int)$course['course_archived'] === 1) {
        $warnings[] = 'This course is archived. Subjects are visible only to the administrator.';
    }
}

require __DIR__ . '/../../includes/admin-header.php';
?>

<?php if ($mode === 'picker'): ?>

  <div class="card max-w-2xl">
    <div class="card-header">
      <div>
        <h2 class="font-semibold">Configure Subjects</h2>
        <p class="text-xs text-slate-500 mt-0.5">Select a course to manage its compulsory and extra subjects.</p>
      </div>
    </div>
    <form method="get" action="<?= e(absolute_url('/admin/subjects.php')) ?>" class="card-body space-y-4">
      <div>
        <label class="form-label" for="picker_section">Academic Section</label>
        <select id="picker_section" name="section_id" class="form-select">
          <option value="">Choose section…</option>
          <?php foreach ($sections as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label" for="picker_institution">Institution</label>
        <select id="picker_institution" name="institution_id" class="form-select" disabled>
          <option value="">Choose institution…</option>
        </select>
      </div>
      <div>
        <label class="form-label" for="picker_course">Course <span class="text-red-600">*</span></label>
        <select id="picker_course" name="course_id" class="form-select" required disabled>
          <option value="">Choose course…</option>
        </select>
      </div>
      <div class="pt-3 border-t border-slate-200">
        <button type="submit" class="btn btn-primary">Load Course Subjects</button>
        <a href="<?= e(absolute_url('/admin/courses.php')) ?>" class="btn btn-secondary">Back to Courses</a>
      </div>
    </form>
  </div>

  <script>
  (function () {
    'use strict';
    var institutions = <?= json_encode($institutions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var courses      = <?= json_encode($courses,      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var sectionSel   = document.getElementById('picker_section');
    var instSel      = document.getElementById('picker_institution');
    var courseSel    = document.getElementById('picker_course');
    if (!sectionSel) return;

    function filterInstitutions() {
      var sid = sectionSel.value;
      instSel.innerHTML = '<option value="">Choose institution…</option>';
      if (!sid) { instSel.disabled = true; filterCourses(); return; }
      instSel.disabled = false;
      institutions.filter(function (i) {
        return String(i.academic_section_id) === String(sid);
      }).forEach(function (i) {
        var opt = document.createElement('option');
        opt.value = i.id; opt.textContent = i.name;
        instSel.appendChild(opt);
      });
      filterCourses();
    }
    function filterCourses() {
      var iid = instSel.value;
      courseSel.innerHTML = '<option value="">Choose course…</option>';
      if (!iid) { courseSel.disabled = true; return; }
      courseSel.disabled = false;
      courses.filter(function (c) {
        return String(c.institution_id) === String(iid);
      }).forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name + (c.duration ? ' (' + c.duration + ')' : '');
        courseSel.appendChild(opt);
      });
    }
    sectionSel.addEventListener('change', filterInstitutions);
    instSel.addEventListener('change', filterCourses);
    filterInstitutions();
  })();
  </script>

<?php else: ?>

  <div class="card max-w-5xl">
    <div class="card-header">
      <div class="min-w-0">
        <h2 class="font-semibold truncate"><?= e($course['name']) ?></h2>
        <div class="text-xs text-slate-500 mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
          <span><?= e($course['section_name']) ?></span>
          <span>›</span>
          <span><?= e($course['institution_name']) ?></span>
          <?php if ((int)$course['section_archived'] === 1): ?>
            <span class="badge badge-danger">Section archived</span>
          <?php endif; ?>
          <?php if ((int)$course['institution_archived'] === 1): ?>
            <span class="badge badge-danger">Institution archived</span>
          <?php endif; ?>
          <?php if ((int)$course['course_archived'] === 1): ?>
            <span class="badge badge-danger">Course archived</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="flex gap-2">
        <a href="<?= e(absolute_url('/admin/subjects.php')) ?>" class="btn btn-sm btn-secondary">Change Course</a>
        <a href="<?= e(absolute_url('/admin/courses.php?action=edit&id=' . $courseId)) ?>" class="btn btn-sm btn-secondary">Edit Course</a>
      </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
      <div class="p-4">
        <div class="alert alert-error"><?= e($errors['general']) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
      <div class="p-4 space-y-2">
        <?php foreach ($warnings as $w): ?>
          <div class="alert alert-warning"><?= e($w) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="card-body space-y-6" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="course_id" value="<?= $courseId ?>">

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="form-label" for="max_extra_subjects">Maximum Extra Subject Selection</label>
          <input type="number" id="max_extra_subjects" name="max_extra_subjects" min="0" max="100"
                 value="<?= (int)$course['max_extra_subjects'] ?>" class="form-input">
          <p class="form-help">Applicants may select at most this many extra subjects. Set to 0 to disallow extra selections.</p>
          <?php if (!empty($errors['max_extra_subjects'])): ?><p class="form-error"><?= e($errors['max_extra_subjects']) ?></p><?php endif; ?>
        </div>
        <div class="text-sm text-slate-600 self-end">
          <div>Duration: <span class="font-medium text-slate-800"><?= e($course['duration']) ?></span></div>
          <div>Price: <span class="font-medium text-slate-800"><?= (float)$course['price'] > 0 ? e(format_money($course['price'])) : 'Free' ?></span></div>
        </div>
      </div>

      <!-- Compulsory subjects -->
      <div>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <div>
            <h3 class="font-semibold">Compulsory Subjects</h3>
            <p class="text-xs text-slate-500">Automatically selected for every applicant. Students cannot remove them.</p>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" data-add-subject-row="compulsory">+ Add Row</button>
        </div>
        <?php if (!empty($errors['compulsory_names'])): ?>
          <div class="alert alert-error mb-2"><?= e($errors['compulsory_names']) ?></div>
        <?php endif; ?>
        <div data-subject-container="compulsory">
          <?php foreach ($compulsoryRows as $row): ?>
            <div class="subject-row">
              <input type="hidden" name="compulsory_ids[]" value="<?= (int)($row['id'] ?? 0) ?>">
              <input type="text" name="compulsory_names[]" class="form-input subject-name"
                     maxlength="200" placeholder="Subject name"
                     value="<?= e($row['name'] ?? '') ?>">
              <button type="button" class="btn-icon" data-move="up" aria-label="Move up" title="Move up">▲</button>
              <button type="button" class="btn-icon" data-move="down" aria-label="Move down" title="Move down">▼</button>
              <button type="button" class="btn-icon btn-icon-danger" data-remove aria-label="Remove" title="Remove">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Extra subjects -->
      <div>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <div>
            <h3 class="font-semibold">Extra Subjects</h3>
            <p class="text-xs text-slate-500">Optional choices. Applicants pick at most the configured maximum.</p>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" data-add-subject-row="extra">+ Add Row</button>
        </div>
        <?php if (!empty($errors['extra_names'])): ?>
          <div class="alert alert-error mb-2"><?= e($errors['extra_names']) ?></div>
        <?php endif; ?>
        <div data-subject-container="extra">
          <?php foreach ($extraRows as $row): ?>
            <div class="subject-row">
              <input type="hidden" name="extra_ids[]" value="<?= (int)($row['id'] ?? 0) ?>">
              <input type="text" name="extra_names[]" class="form-input subject-name"
                     maxlength="200" placeholder="Subject name"
                     value="<?= e($row['name'] ?? '') ?>">
              <button type="button" class="btn-icon" data-move="up" aria-label="Move up" title="Move up">▲</button>
              <button type="button" class="btn-icon" data-move="down" aria-label="Move down" title="Move down">▼</button>
              <button type="button" class="btn-icon btn-icon-danger" data-remove aria-label="Remove" title="Remove">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-wrap gap-2 pt-3 border-t border-slate-200">
        <button type="submit" class="btn btn-primary">Save Subjects</button>
        <a href="<?= e(absolute_url('/admin/courses.php')) ?>" class="btn btn-secondary">Back to Courses</a>
        <div class="text-xs text-slate-500 self-center ml-auto">
          Each subject is stored as an individual record. Duplicate names within a list are rejected.
        </div>
      </div>
    </form>
  </div>

  <script src="<?= e(absolute_url('/assets/js/subjects.js')) ?>"></script>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>