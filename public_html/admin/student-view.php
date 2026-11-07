<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

auth_require();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { flash_set('error', 'Invalid student.'); redirect('/admin/students.php'); }

$stmt = db()->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) { flash_set('error', 'Student not found.'); redirect('/admin/students.php'); }

$stmt = db()->prepare(
    'SELECT a.*, s.name AS section_name, i.name AS institution_name, c.name AS course_name
     FROM applications a
     LEFT JOIN academic_sections s ON s.id = a.academic_section_id
     LEFT JOIN institutions i ON i.id = a.institution_id
     LEFT JOIN courses c ON c.id = a.course_id
     WHERE a.student_id = ?
     ORDER BY a.id DESC'
);
$stmt->execute([$id]);
$apps = $stmt->fetchAll();

$currentPage = 'students.php';
$page_title  = 'Student: ' . $student['name'];

require __DIR__ . '/../../includes/admin-header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-2 mb-4">
  <div class="min-w-0">
    <a href="<?= e(absolute_url('/admin/students.php')) ?>" class="text-sm text-slate-600 hover:text-indigo-900">← Back to Students</a>
    <h2 class="text-xl font-semibold mt-1"><?= e($student['name']) ?></h2>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

  <div class="card">
    <div class="card-header"><h3 class="font-semibold">Student Details</h3></div>
    <div class="card-body">
      <dl class="space-y-2 text-sm">
        <div><dt class="text-slate-500">Mobile</dt><dd class="font-medium"><?= e($student['mobile']) ?></dd></div>
        <div><dt class="text-slate-500">Email</dt><dd class="font-medium break-all"><?= e($student['email'] ?: '—') ?></dd></div>
        <div><dt class="text-slate-500">Date of Birth</dt><dd class="font-medium"><?= $student['dob'] ? e(format_date($student['dob'])) : '—' ?></dd></div>
        <div><dt class="text-slate-500">Father's Name</dt><dd class="font-medium"><?= e($student['father_name'] ?: '—') ?></dd></div>
        <div><dt class="text-slate-500">Mother's Name</dt><dd class="font-medium"><?= e($student['mother_name'] ?: '—') ?></dd></div>
        <div><dt class="text-slate-500">Record Created</dt><dd class="font-medium"><?= e(format_datetime($student['created_at'])) ?></dd></div>
      </dl>
    </div>
  </div>

  <div class="card lg:col-span-2">
    <div class="card-header">
      <h3 class="font-semibold">Applications</h3>
      <span class="text-xs text-slate-500"><?= count($apps) ?> total</span>
    </div>
    <div class="card-body">
      <?php if (empty($apps)): ?>
        <p class="text-slate-500 text-sm">This student has no applications.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>Application ID</th><th>Course</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($apps as $a): ?>
                <tr>
                  <td class="font-mono text-xs break-all"><?= e($a['application_id']) ?></td>
                  <td class="text-sm">
                    <div><?= e($a['course_name_snapshot']) ?></div>
                    <div class="text-xs text-slate-500"><?= e($a['institution_name_snapshot']) ?></div>
                  </td>
                  <td class="text-xs"><?= e(format_datetime($a['submission_date'])) ?></td>
                  <td><?= bw_status_badge((string)$a['application_status']) ?></td>
                  <td>
                    <a href="<?= e(absolute_url('/admin/application-view.php?id=' . (int)$a['id'])) ?>" class="btn btn-sm btn-secondary">View</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>