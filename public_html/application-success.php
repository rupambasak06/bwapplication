<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$page_title = 'Application Received';
$result = $_SESSION['_app_result'] ?? null;

require __DIR__ . '/../includes/public-header.php';
?>

<?php if (!$result): ?>
  <div class="max-w-2xl mx-auto">
    <div class="card card-body text-center">
      <h2 class="text-xl font-semibold mb-2">Application Session Not Found</h2>
      <p class="text-slate-600 text-sm">
        This page displays a summary immediately after submission. To view an existing
        application, use the application status page once available.
      </p>
      <a href="<?= e(absolute_url('/')) ?>" class="btn btn-primary mt-4">Return Home</a>
    </div>
  </div>
<?php else: ?>
  <div class="max-w-3xl mx-auto">
    <div class="card overflow-hidden">
      <div class="bg-indigo-900 text-white p-6 sm:p-8 text-center">
        <h2 class="text-xl sm:text-2xl font-semibold">Application Received</h2>
        <p class="mt-1 text-indigo-100 text-sm">Please save your Application ID</p>
      </div>
      <div class="card-body space-y-5">
        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-5 text-center">
          <div class="text-xs uppercase tracking-wider text-indigo-900/70">Your Application ID</div>
          <div class="text-2xl sm:text-3xl font-bold font-mono tracking-wider text-indigo-900 mt-1">
            <?= e($result['application_id']) ?>
          </div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div><dt class="text-slate-500">Student Name</dt><dd class="font-medium"><?= e($result['name']) ?></dd></div>
          <div><dt class="text-slate-500">Mobile Number</dt><dd class="font-medium"><?= e($result['mobile']) ?></dd></div>
          <div><dt class="text-slate-500">Academic Section</dt><dd class="font-medium"><?= e($result['section']) ?></dd></div>
          <div><dt class="text-slate-500">Institution</dt><dd class="font-medium"><?= e($result['institution']) ?></dd></div>
          <div class="sm:col-span-2"><dt class="text-slate-500">Course</dt><dd class="font-medium"><?= e($result['course']) ?></dd></div>
          <div class="sm:col-span-2"><dt class="text-slate-500">Submitted</dt><dd class="font-medium"><?= e(format_datetime($result['submitted_at'])) ?></dd></div>
        </dl>

        <?php if ($result['requires_payment']): ?>
          <div class="alert alert-warning">
            <strong>Payment Pending.</strong>
            An amount of <?= e(format_money($result['amount'])) ?> is required to complete your application.
            <?php if ((int)app_setting('online_payment_enabled', '0') === 1): ?>
              <div class="mt-3">
                <button type="button" class="btn btn-primary opacity-50 cursor-not-allowed" disabled>
                  Complete Payment (Coming Soon)
                </button>
                <p class="text-xs text-amber-900 mt-2">
                  Your application has been saved. Online payment processing will be enabled shortly.
                  Please keep your Application ID and try again later.
                </p>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="alert alert-success">
            Your application has been submitted and is now under review.
          </div>
        <?php endif; ?>

        <div class="pt-4 border-t border-slate-200 text-sm text-slate-600">
          Please save your Application ID and registered mobile number.
          You will need both to check your application status.
        </div>

        <div class="flex gap-3 flex-wrap">
          <a href="<?= e(absolute_url('/')) ?>" class="btn btn-secondary">Return Home</a>
          <button type="button" class="btn btn-secondary" onclick="window.print()">Print This Page</button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/public-footer.php'; ?>