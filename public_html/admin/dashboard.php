<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$currentPage = 'dashboard.php';
$page_title  = 'Dashboard';

$stats = [
    'applications'   => 0,
    'pending'        => 0,
    'approved'       => 0,
    'paid_today'     => 0,
    'sections'       => 0,
    'institutions'   => 0,
    'courses'        => 0,
];

try {
    $stats['applications'] = (int)db()->query('SELECT COUNT(*) FROM applications')->fetchColumn();
    $stats['pending']      = (int)db()->query(
        "SELECT COUNT(*) FROM applications WHERE application_status IN ('submitted','under_review','payment_pending','documents_required')"
    )->fetchColumn();
    $stats['approved']     = (int)db()->query(
        "SELECT COUNT(*) FROM applications WHERE application_status = 'approved'"
    )->fetchColumn();
    $stats['paid_today']   = (int)db()->query(
        "SELECT COUNT(*) FROM payment_transactions WHERE status = 'paid' AND DATE(created_at) = CURDATE()"
    )->fetchColumn();
    $stats['sections']     = (int)db()->query(
        'SELECT COUNT(*) FROM academic_sections WHERE is_archived = 0'
    )->fetchColumn();
    $stats['institutions'] = (int)db()->query(
        'SELECT COUNT(*) FROM institutions WHERE is_archived = 0'
    )->fetchColumn();
    $stats['courses']      = (int)db()->query(
        'SELECT COUNT(*) FROM courses WHERE is_archived = 0'
    )->fetchColumn();
} catch (Throwable $e) {
    // ignore; empty state
}

$recent = [];
try {
    $recent = db()->query(
        'SELECT a.application_id, a.application_status, a.payment_status, a.submission_date,
                s.name AS student_name, c.name AS course_name
         FROM applications a
         JOIN students s ON s.id = a.student_id
         JOIN courses  c ON c.id = a.course_id
         ORDER BY a.id DESC
         LIMIT 10'
    )->fetchAll();
} catch (Throwable $e) { /* ignore */ }

require __DIR__ . '/../../includes/admin-header.php';
?>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <div class="bg-white border border-slate-200 rounded-lg p-5">
    <div class="text-xs uppercase text-slate-500">Total Applications</div>
    <div class="mt-1 text-2xl font-semibold"><?= (int)$stats['applications'] ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-lg p-5">
    <div class="text-xs uppercase text-slate-500">Pending Review</div>
    <div class="mt-1 text-2xl font-semibold text-indigo-900"><?= (int)$stats['pending'] ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-lg p-5">
    <div class="text-xs uppercase text-slate-500">Approved</div>
    <div class="mt-1 text-2xl font-semibold text-green-600"><?= (int)$stats['approved'] ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-lg p-5">
    <div class="text-xs uppercase text-slate-500">Paid Today</div>
    <div class="mt-1 text-2xl font-semibold"><?= (int)$stats['paid_today'] ?></div>
  </div>
</div>

<div class="grid gap-4 sm:grid-cols-3 mt-6">
  <div class="bg-white border border-slate-200 rounded-lg p-5">
    <div class="text-xs uppercase text-slate-500">Academic Sections</div>
    <div class="mt-1 text-xl font-semibold"><?= (int)$stats['sections'] ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-lg p-5">
    <div class="text-xs uppercase text-slate-500">Institutions</div>
    <div class="mt-1 text-xl font-semibold"><?= (int)$stats['institutions'] ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-lg p-5">
    <div class="text-xs uppercase text-slate-500">Courses</div>
    <div class="mt-1 text-xl font-semibold"><?= (int)$stats['courses'] ?></div>
  </div>
</div>

<div class="bg-white border border-slate-200 rounded-lg mt-6">
  <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
    <h2 class="font-semibold">Recent Applications</h2>
  </div>
  <?php if (empty($recent)): ?>
    <div class="p-6 text-slate-500 text-sm">
      No applications have been submitted yet. Once students start applying, they will appear here.
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 uppercase text-xs">
          <tr>
            <th class="text-left px-5 py-3">Application ID</th>
            <th class="text-left px-5 py-3">Student</th>
            <th class="text-left px-5 py-3">Course</th>
            <th class="text-left px-5 py-3">Status</th>
            <th class="text-left px-5 py-3">Submitted</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="px-5 py-3 font-mono text-xs"><?= e($r['application_id']) ?></td>
              <td class="px-5 py-3"><?= e($r['student_name']) ?></td>
              <td class="px-5 py-3"><?= e($r['course_name']) ?></td>
              <td class="px-5 py-3"><?= e(ucwords(str_replace('_', ' ', (string)$r['application_status']))) ?></td>
              <td class="px-5 py-3"><?= e(format_datetime($r['submission_date'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>