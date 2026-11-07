<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$page_title = 'Check Application Status';

$errors = [];
$result = null;
$input  = ['application_id' => '', 'mobile' => ''];

if (is_post()) {
    csrf_require();
    $input['application_id'] = (string)post('application_id', '');
    $input['mobile']         = (string)post('mobile', '');

    $ip       = client_ip();
    $limitKey = '__sc:' . $ip;

    // Rate limit: 10 failed attempts in 15 minutes per IP
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = ? AND success = 0
               AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$limitKey]);
        $failedAttempts = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $failedAttempts = 0;
    }

    if ($failedAttempts >= 10) {
        $errors['general'] = 'Too many attempts. Please try again in 15 minutes.';
    } elseif ($input['application_id'] === '' || $input['mobile'] === '') {
        $errors['general'] = 'Please enter both the Application ID and registered mobile number.';
    } else {
        try {
            $stmt = db()->prepare(
                'SELECT a.application_id, a.application_status, a.payment_status,
                        a.submission_date, a.admin_notes,
                        a.section_name_snapshot, a.institution_name_snapshot, a.course_name_snapshot,
                        s.name AS student_name, s.mobile AS student_mobile
                 FROM applications a
                 JOIN students s ON s.id = a.student_id
                 WHERE a.application_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$input['application_id']]);
            $app = $stmt->fetch();

            $digitsPosted = preg_replace('/\D+/', '', $input['mobile']);
            $digitsStored = $app ? preg_replace('/\D+/', '', (string)$app['student_mobile']) : '';

            if (!$app || $digitsPosted === '' || $digitsPosted !== $digitsStored) {
                try {
                    db()->prepare(
                        'INSERT INTO login_attempts (username, ip_address, success, attempted_at)
                         VALUES (?, ?, 0, NOW())'
                    )->execute([$limitKey, $ip]);
                } catch (Throwable $e) { /* ignore */ }
                $errors['general'] = 'No application matches the details provided.';
            } else {
                try {
                    db()->prepare(
                        'INSERT INTO login_attempts (username, ip_address, success, attempted_at)
                         VALUES (?, ?, 1, NOW())'
                    )->execute([$limitKey, $ip]);
                } catch (Throwable $e) { /* ignore */ }

                $result = $app;
            }
        } catch (Throwable $e) {
            error_log('[check-status] ' . $e->getMessage());
            $errors['general'] = 'Could not look up the application. Please try again shortly.';
        }
    }
}

require __DIR__ . '/../includes/public-header.php';
?>

<div class="max-w-2xl mx-auto">
  <div class="card">
    <div class="card-header">
      <h2 class="font-semibold">Check Application Status</h2>
    </div>
    <form method="post" class="card-body space-y-4" autocomplete="off">
      <?= csrf_field() ?>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <div>
        <label class="form-label" for="application_id">Application ID</label>
        <input type="text" id="application_id" name="application_id" required
               value="<?= e($input['application_id']) ?>"
               placeholder="e.g. BW-2025-000001" class="form-input">
      </div>
      <div>
        <label class="form-label" for="mobile">Registered Mobile Number</label>
        <input type="tel" id="mobile" name="mobile" required
               value="<?= e($input['mobile']) ?>"
               placeholder="Enter the mobile number you registered with" class="form-input">
      </div>

      <button type="submit" class="btn btn-primary w-full">Check Status</button>
    </form>
  </div>

  <?php if ($result): ?>
    <div class="card mt-4">
      <div class="card-body space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <div class="text-xs uppercase text-slate-500">Application ID</div>
            <div class="text-lg font-semibold font-mono break-all"><?= e($result['application_id']) ?></div>
          </div>
          <div><?= bw_status_badge((string)$result['application_status']) ?></div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
          <div><dt class="text-slate-500">Student Name</dt><dd class="font-medium"><?= e($result['student_name']) ?></dd></div>
          <div><dt class="text-slate-500">Academic Section</dt><dd class="font-medium"><?= e($result['section_name_snapshot']) ?></dd></div>
          <div><dt class="text-slate-500">Institution</dt><dd class="font-medium"><?= e($result['institution_name_snapshot']) ?></dd></div>
          <div><dt class="text-slate-500">Course</dt><dd class="font-medium"><?= e($result['course_name_snapshot']) ?></dd></div>
          <div><dt class="text-slate-500">Submitted On</dt><dd class="font-medium"><?= e(format_datetime($result['submission_date'])) ?></dd></div>
          <div><dt class="text-slate-500">Payment Status</dt><dd class="font-medium"><?= e(ucwords(str_replace('_', ' ', $result['payment_status']))) ?></dd></div>
        </dl>

        <?php if ($result['application_status'] === 'payment_pending'): ?>
          <div class="alert alert-warning">
            <strong>Payment pending.</strong>
            Please complete the payment on your application summary page to submit your application.
          </div>
        <?php elseif ($result['application_status'] === 'approved'): ?>
          <div class="alert alert-success">Congratulations! Your application has been approved.</div>
        <?php elseif ($result['application_status'] === 'rejected'): ?>
          <div class="alert alert-error">Your application was not approved. See the administrator's note below.</div>
        <?php elseif ($result['application_status'] === 'documents_required'): ?>
          <div class="alert alert-warning">Additional documents are required. See the administrator's note below.</div>
        <?php endif; ?>

        <?php if (!empty($result['admin_notes'])): ?>
          <div>
            <div class="text-xs uppercase text-slate-500 mb-1">Administrator's Note</div>
            <div class="rounded border border-slate-200 bg-slate-50 p-3 text-sm whitespace-pre-line break-words">
              <?= e($result['admin_notes']) ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/public-footer.php'; ?>