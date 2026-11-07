<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/application-service.php';

auth_require();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid application.');
    redirect('/admin/applications.php');
}

$stmt = db()->prepare(
    'SELECT a.*, s.name AS student_name, s.father_name, s.mother_name, s.dob,
            s.mobile AS student_mobile, s.email AS student_email, s.created_at AS student_created_at
     FROM applications a
     JOIN students s ON s.id = a.student_id
     WHERE a.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$app = $stmt->fetch();
if (!$app) {
    flash_set('error', 'Application not found.');
    redirect('/admin/applications.php');
}

/* ---------- POST handlers ---------- */
$errors = [];
if (is_post()) {
    csrf_require();
    $do = (string)post('do', '');

    if ($do === 'update_status') {
        $newStatus = (string)post('new_status', '');
        $notes     = (string)post('admin_notes', '');
        $valid     = ['submitted', 'under_review', 'documents_required', 'approved', 'rejected'];

        if (!in_array($newStatus, $valid, true)) {
            $errors[] = 'Please select a valid application status.';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                $statusChanged = $newStatus !== $app['application_status'];

                $pdo->prepare(
                    'UPDATE applications SET application_status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$newStatus, $notes, $id]);

                if ($statusChanged) {
                    $pdo->prepare(
                        'INSERT INTO application_status_history
                            (application_id, previous_status, new_status, notes, changed_by_admin_id, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())'
                    )->execute([
                        $id,
                        $app['application_status'],
                        $newStatus,
                        $notes !== '' ? $notes : null,
                        (int)$_SESSION['admin_id'],
                    ]);
                }

                $pdo->commit();
                log_activity('application.status_updated', 'application', $id, ['new_status' => $newStatus]);
                flash_set('success', 'Application updated.');
                redirect('/admin/application-view.php?id=' . $id);
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                error_log('[app update] ' . $e->getMessage());
                $errors[] = 'Could not save the changes. Please try again.';
            }
        }
    }
}

/* ---------- Load related data ---------- */
$stmt = db()->prepare(
    'SELECT * FROM application_subject_selections WHERE application_id = ? ORDER BY subject_type, id'
);
$stmt->execute([$id]);
$allSubjects = $stmt->fetchAll();
$compulsory  = array_values(array_filter($allSubjects, fn($s) => $s['subject_type'] === 'compulsory'));
$extra       = array_values(array_filter($allSubjects, fn($s) => $s['subject_type'] === 'extra'));

$stmt = db()->prepare('SELECT * FROM application_answers WHERE application_id = ? ORDER BY id');
$stmt->execute([$id]);
$answers = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM application_documents WHERE application_id = ? ORDER BY id');
$stmt->execute([$id]);
$documents = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM payment_transactions WHERE application_id = ? ORDER BY id DESC');
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT h.*, u.full_name AS admin_name
     FROM application_status_history h
     LEFT JOIN admins u ON u.id = h.changed_by_admin_id
     WHERE h.application_id = ?
     ORDER BY h.id DESC'
);
$stmt->execute([$id]);
$history = $stmt->fetchAll();

$institute = [
    'name'      => (string)app_setting('institute_name', 'Brainwavers'),
    'mobile'    => (string)app_setting('institute_mobile', ''),
    'email'     => (string)app_setting('institute_email', ''),
    'address'   => (string)app_setting('institute_address', ''),
    'logo_path' => (string)app_setting('institute_logo', ''),
];

/* ---------- Print mode: emit standalone HTML and exit ---------- */
if (isset($_GET['print'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo bw_render_application_print_html($app, $compulsory, $extra, $answers, $documents, $payments, $institute);
    echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});</script>';
    exit;
}

$currentPage = 'applications.php';
$page_title  = 'Application ' . $app['application_id'];

require __DIR__ . '/../../includes/admin-header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-2 mb-4">
  <div class="min-w-0">
    <a href="<?= e(absolute_url('/admin/applications.php')) ?>" class="text-sm text-slate-600 hover:text-indigo-900">← Back to Applications</a>
    <h2 class="text-xl font-semibold mt-1 font-mono break-all"><?= e($app['application_id']) ?></h2>
    <div class="mt-1 flex flex-wrap items-center gap-2">
      <?= bw_status_badge((string)$app['application_status']) ?>
      <span class="badge <?= $app['payment_status'] === 'paid' ? 'badge-success' : ($app['payment_status'] === 'pending' ? 'badge-warning' : 'badge-neutral') ?>">
        Payment: <?= e(ucwords(str_replace('_', ' ', $app['payment_status']))) ?>
      </span>
    </div>
  </div>
  <div class="flex flex-wrap gap-2">
    <a href="<?= e(absolute_url('/admin/application-view.php?id=' . $id . '&print=1')) ?>"
       target="_blank" rel="noopener" class="btn btn-secondary">Print</a>
    <a href="<?= e(absolute_url('/admin/download-application.php?id=' . $id)) ?>"
       class="btn btn-primary">Download PDF</a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error mb-4">
    <ul class="list-disc pl-5 space-y-1">
      <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

  <div class="lg:col-span-2 space-y-4">

    <!-- Applicant details -->
    <div class="card">
      <div class="card-header"><h3 class="font-semibold">Applicant Details</h3></div>
      <div class="card-body">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div><dt class="text-slate-500">Student Name</dt><dd class="font-medium"><?= e($app['student_name']) ?></dd></div>
          <div><dt class="text-slate-500">Date of Birth</dt><dd class="font-medium"><?= $app['dob'] ? e(format_date($app['dob'])) : '—' ?></dd></div>
          <div><dt class="text-slate-500">Father's Name</dt><dd class="font-medium"><?= e($app['father_name'] ?: '—') ?></dd></div>
          <div><dt class="text-slate-500">Mother's Name</dt><dd class="font-medium"><?= e($app['mother_name'] ?: '—') ?></dd></div>
          <div><dt class="text-slate-500">Mobile Number</dt><dd class="font-medium"><?= e($app['student_mobile']) ?></dd></div>
          <div><dt class="text-slate-500">Email Address</dt><dd class="font-medium break-all"><?= e($app['student_email'] ?: '—') ?></dd></div>
        </dl>
      </div>
    </div>

    <!-- Program -->
    <div class="card">
      <div class="card-header"><h3 class="font-semibold">Program</h3></div>
      <div class="card-body">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div><dt class="text-slate-500">Academic Section</dt><dd class="font-medium"><?= e($app['section_name_snapshot']) ?></dd></div>
          <div><dt class="text-slate-500">Institution</dt><dd class="font-medium"><?= e($app['institution_name_snapshot']) ?></dd></div>
          <div><dt class="text-slate-500">Course</dt><dd class="font-medium"><?= e($app['course_name_snapshot']) ?></dd></div>
          <div><dt class="text-slate-500">Duration</dt><dd class="font-medium"><?= e($app['course_duration_snapshot'] ?: '—') ?></dd></div>
          <div><dt class="text-slate-500">Course Price</dt>
            <dd class="font-medium"><?= (float)$app['course_price_snapshot'] > 0 ? e(format_money($app['course_price_snapshot'])) : 'Free' ?></dd></div>
          <div><dt class="text-slate-500">Submitted</dt><dd class="font-medium"><?= e(format_datetime($app['submission_date'])) ?></dd></div>
        </dl>
      </div>
    </div>

    <!-- Subjects -->
    <div class="card">
      <div class="card-header"><h3 class="font-semibold">Subjects</h3></div>
      <div class="card-body grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
        <div>
          <div class="text-xs uppercase text-slate-500 mb-2">Compulsory</div>
          <?php if (empty($compulsory)): ?><p class="text-slate-500">None</p><?php else: ?>
            <ul class="space-y-1">
              <?php foreach ($compulsory as $s): ?>
                <li>• <?= e($s['subject_name_snapshot']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <div>
          <div class="text-xs uppercase text-slate-500 mb-2">Extra</div>
          <?php if (empty($extra)): ?><p class="text-slate-500">None</p><?php else: ?>
            <ul class="space-y-1">
              <?php foreach ($extra as $s): ?>
                <li>• <?= e($s['subject_name_snapshot']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Dynamic answers -->
    <?php if (!empty($answers)): ?>
      <div class="card">
        <div class="card-header"><h3 class="font-semibold">Additional Information</h3></div>
        <div class="card-body">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <?php foreach ($answers as $a): ?>
              <div class="sm:col-span-2">
                <dt class="text-slate-500"><?= e($a['field_label_snap']) ?></dt>
                <dd class="font-medium whitespace-pre-line break-words"><?= e($a['field_value']) ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
        </div>
      </div>
    <?php endif; ?>

    <!-- Documents -->
    <div class="card">
      <div class="card-header"><h3 class="font-semibold">Documents</h3></div>
      <div class="card-body">
        <?php if (empty($documents)): ?>
          <p class="text-slate-500 text-sm">No documents were uploaded with this application.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($documents as $d): ?>
              <div class="flex items-center justify-between gap-3 p-2 rounded border border-slate-200 bg-slate-50">
                <div class="min-w-0">
                  <div class="font-medium text-sm"><?= e($d['document_name']) ?></div>
                  <div class="text-xs text-slate-500 truncate">
                    <?= e($d['original_filename']) ?> · <?= number_format((int)$d['file_size'] / 1024, 1) ?> KB
                    <?= (int)$d['compression_applied'] === 1 ? ' (compressed)' : '' ?>
                  </div>
                </div>
                <a href="<?= e(absolute_url('/admin/download-document.php?id=' . (int)$d['id'])) ?>"
                   class="btn btn-sm btn-secondary shrink-0">Download</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Payments -->
    <div class="card">
      <div class="card-header"><h3 class="font-semibold">Payments</h3></div>
      <div class="card-body">
        <?php if (empty($payments)): ?>
          <p class="text-slate-500 text-sm">No payment attempts on record.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead><tr><th>Status</th><th>Amount</th><th>Order ID</th><th>Payment ID</th><th>Created</th></tr></thead>
              <tbody>
                <?php foreach ($payments as $p): ?>
                  <tr>
                    <td><span class="badge <?= $p['status'] === 'paid' ? 'badge-success' : ($p['status'] === 'failed' ? 'badge-danger' : 'badge-warning') ?>">
                      <?= e(ucwords($p['status'])) ?></span></td>
                    <td><?= e($p['currency'] . ' ' . number_format((float)$p['amount'], 2)) ?></td>
                    <td class="font-mono text-xs break-all"><?= e($p['razorpay_order_id'] ?: '—') ?></td>
                    <td class="font-mono text-xs break-all"><?= e($p['razorpay_payment_id'] ?: '—') ?></td>
                    <td class="text-xs"><?= e(format_datetime($p['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Right column: status, notes, history -->
  <div class="space-y-4">

    <div class="card">
      <div class="card-header"><h3 class="font-semibold">Update Status</h3></div>
      <form method="post" class="card-body space-y-3">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="update_status">
        <div>
          <label class="form-label" for="new_status">Application Status</label>
          <select id="new_status" name="new_status" required class="form-select">
            <?php
              $valid = [
                'submitted'          => 'Submitted',
                'under_review'       => 'Under Review',
                'documents_required' => 'Documents Required',
                'approved'           => 'Approved',
                'rejected'           => 'Rejected',
              ];
              foreach ($valid as $v => $l):
                $sel = $app['application_status'] === $v ? 'selected' : '';
            ?>
              <option value="<?= e($v) ?>" <?= $sel ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="admin_notes">Administrative Notes</label>
          <textarea id="admin_notes" name="admin_notes" rows="4" maxlength="3000"
                    class="form-textarea"><?= e($app['admin_notes']) ?></textarea>
          <p class="form-help">These notes are shown to the applicant on the public status page when the status is Under Review, Documents Required, or Rejected.</p>
        </div>
        <button type="submit" class="btn btn-primary w-full">Save Changes</button>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="font-semibold">Status History</h3></div>
      <div class="card-body">
        <?php if (empty($history)): ?>
          <p class="text-slate-500 text-sm">No status changes recorded yet.</p>
        <?php else: ?>
          <ul class="space-y-3 text-sm">
            <?php foreach ($history as $h): ?>
              <li class="border-l-2 border-slate-200 pl-3">
                <div class="font-medium"><?= e(ucwords(str_replace('_', ' ', $h['new_status']))) ?></div>
                <?php if ($h['previous_status']): ?>
                  <div class="text-xs text-slate-500">from <?= e(ucwords(str_replace('_', ' ', $h['previous_status']))) ?></div>
                <?php endif; ?>
                <div class="text-xs text-slate-500"><?= e(format_datetime($h['created_at'])) ?>
                  <?= $h['admin_name'] ? '· ' . e($h['admin_name']) : '· system' ?></div>
                <?php if (!empty($h['notes'])): ?>
                  <div class="mt-1 text-slate-700 whitespace-pre-line"><?= e($h['notes']) ?></div>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>