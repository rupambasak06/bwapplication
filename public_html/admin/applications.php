<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/application-service.php';

auth_require();

$currentPage = 'applications.php';
$page_title  = 'Applications';

$filters = bw_application_filters();
$whereSql = $filters['where_sql'];
$params   = $filters['params'];

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(200, (int)app_setting('pagination_size', '20')));

try {
    $countStmt = db()->prepare(
        "SELECT COUNT(*)
         FROM applications a
         JOIN students s ON s.id = a.student_id
         $whereSql"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(
        "SELECT a.id, a.application_id, a.application_status, a.payment_status,
                a.submission_date, a.course_name_snapshot, a.institution_name_snapshot,
                a.section_name_snapshot, a.course_price_snapshot,
                s.name AS student_name, s.mobile AS student_mobile, s.email AS student_email
         FROM applications a
         JOIN students s ON s.id = a.student_id
         $whereSql
         ORDER BY a.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $apps = $stmt->fetchAll();
} catch (Throwable $e) {
    $apps = [];
    $total = 0;
    $totalPages = 1;
    $offset = 0;
}

$sections = [];
$institutions = [];
$courses = [];
try {
    $sections     = db()->query('SELECT id, name FROM academic_sections WHERE is_archived = 0 ORDER BY display_order, name')->fetchAll();
    $institutions = db()->query('SELECT id, name FROM institutions WHERE is_archived = 0 ORDER BY name')->fetchAll();
    $courses      = db()->query('SELECT id, name FROM courses WHERE is_archived = 0 ORDER BY name')->fetchAll();
} catch (Throwable $e) { /* ignore */ }

require __DIR__ . '/../../includes/admin-header.php';
?>

<div class="card">
  <div class="card-header">
    <div>
      <h2 class="font-semibold">Applications</h2>
      <p class="text-xs text-slate-500 mt-0.5">Total: <?= (int)$total ?> application(s) matching current filters.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="<?= e(absolute_url('/admin/export-applications.php?' . http_build_query($_GET))) ?>"
         class="btn btn-secondary">Export CSV</a>
    </div>
  </div>

  <form method="get" class="p-4 border-b border-slate-200 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Student name / email / mobile" class="form-input">
    <input type="text" name="application_id" value="<?= e($_GET['application_id'] ?? '') ?>" placeholder="Application ID" class="form-input">
    <input type="text" name="mobile" value="<?= e($_GET['mobile'] ?? '') ?>" placeholder="Mobile number" class="form-input">
    <input type="email" name="email" value="<?= e($_GET['email'] ?? '') ?>" placeholder="Email address" class="form-input">

    <select name="section_id" class="form-select">
      <option value="0">All sections</option>
      <?php foreach ($sections as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= (int)($_GET['section_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
          <?= e($s['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="institution_id" class="form-select">
      <option value="0">All institutions</option>
      <?php foreach ($institutions as $i): ?>
        <option value="<?= (int)$i['id'] ?>" <?= (int)($_GET['institution_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>>
          <?= e($i['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="course_id" class="form-select">
      <option value="0">All courses</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)($_GET['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
          <?= e($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-select">
      <?php
        $statusOptions = [
          'all' => 'All statuses',
          'submitted' => 'Submitted',
          'under_review' => 'Under Review',
          'documents_required' => 'Documents Required',
          'approved' => 'Approved',
          'rejected' => 'Rejected',
          'payment_pending' => 'Payment Pending',
        ];
        foreach ($statusOptions as $v => $l):
          $sel = (string)($_GET['status'] ?? 'all') === $v ? 'selected' : '';
      ?>
        <option value="<?= e($v) ?>" <?= $sel ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="payment_status" class="form-select">
      <?php
        $payOptions = [
          'all' => 'All payment states',
          'not_required' => 'Not Required',
          'pending' => 'Pending',
          'paid' => 'Paid',
          'failed' => 'Failed',
          'refunded' => 'Refunded',
        ];
        foreach ($payOptions as $v => $l):
          $sel = (string)($_GET['payment_status'] ?? 'all') === $v ? 'selected' : '';
      ?>
        <option value="<?= e($v) ?>" <?= $sel ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>

    <input type="date" name="from_date" value="<?= e($_GET['from_date'] ?? '') ?>" placeholder="From date" class="form-input">
    <input type="date" name="to_date"   value="<?= e($_GET['to_date'] ?? '') ?>"   placeholder="To date" class="form-input">

    <div class="flex gap-2 lg:col-span-2">
      <button type="submit" class="btn btn-primary">Filter</button>
      <a href="<?= e(absolute_url('/admin/applications.php')) ?>" class="btn btn-secondary">Reset</a>
    </div>
  </form>

  <?php if (empty($apps)): ?>
    <div class="p-8 text-center text-slate-500">
      <div class="text-lg font-medium text-slate-700 mb-1">No applications found</div>
      <p class="text-sm">Adjust your filters or wait for students to apply.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Application ID</th>
            <th>Student</th>
            <th>Course</th>
            <th>Submitted</th>
            <th>Payment</th>
            <th>Status</th>
            <th class="w-40">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($apps as $a): ?>
            <tr>
              <td class="font-mono text-xs">
                <a href="<?= e(absolute_url('/admin/application-view.php?id=' . (int)$a['id'])) ?>" class="text-indigo-900 hover:underline">
                  <?= e($a['application_id']) ?>
                </a>
              </td>
              <td>
                <div class="font-medium"><?= e($a['student_name']) ?></div>
                <div class="text-xs text-slate-500"><?= e($a['student_mobile']) ?></div>
              </td>
              <td>
                <div class="text-sm"><?= e($a['course_name_snapshot']) ?></div>
                <div class="text-xs text-slate-500"><?= e($a['institution_name_snapshot']) ?></div>
              </td>
              <td class="text-xs"><?= e(format_datetime($a['submission_date'])) ?></td>
              <td>
                <?php
                  $payClass = [
                    'paid'         => 'badge-success',
                    'pending'      => 'badge-warning',
                    'failed'       => 'badge-danger',
                    'not_required' => 'badge-neutral',
                    'refunded'     => 'badge-info',
                  ][$a['payment_status']] ?? 'badge-neutral';
                ?>
                <span class="badge <?= $payClass ?>"><?= e(ucwords(str_replace('_', ' ', $a['payment_status']))) ?></span>
              </td>
              <td><?= bw_status_badge((string)$a['application_status']) ?></td>
              <td>
                <div class="flex flex-wrap gap-1">
                  <a href="<?= e(absolute_url('/admin/application-view.php?id=' . (int)$a['id'])) ?>" class="btn btn-sm btn-primary">View</a>
                  <a href="<?= e(absolute_url('/admin/application-view.php?id=' . (int)$a['id'] . '&print=1')) ?>"
                     target="_blank" rel="noopener" class="btn btn-sm btn-secondary">Print</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="p-4 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
        <div class="text-sm text-slate-500">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> total</div>
        <div class="flex gap-2">
          <?php if ($page > 1): ?>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>" class="btn btn-sm btn-secondary">Previous</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>" class="btn btn-sm btn-secondary">Next</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>