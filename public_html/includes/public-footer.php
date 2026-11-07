</main>
<footer class="bg-white border-t border-slate-200 mt-12">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 grid gap-6 md:grid-cols-3">
    <div>
      <div class="font-semibold text-slate-900"><?= e(app_setting('institute_name', 'Brainwavers')) ?></div>
      <?php if ($a = app_setting('institute_address', '')): ?>
        <p class="text-sm text-slate-600 mt-2 whitespace-pre-line"><?= e($a) ?></p>
      <?php endif; ?>
    </div>
    <div class="text-sm text-slate-600 space-y-1">
      <?php if ($m = app_setting('institute_mobile', '')): ?><div>Phone: <?= e($m) ?></div><?php endif; ?>
      <?php if ($em = app_setting('institute_email', '')): ?><div>Email: <?= e($em) ?></div><?php endif; ?>
      <?php if ($w = app_setting('institute_website', '')): ?><div>Website: <?= e($w) ?></div><?php endif; ?>
    </div>
    <div class="text-sm text-slate-600 space-y-1">
      <?php
        $footerLinks = [
          'About'                    => '/about.php',
          'FAQ'                      => '/faq.php',
          'Contact'                  => '/contact.php',
          'Check Application Status' => '/check-status.php',
        ];
        foreach ($footerLinks as $label => $href):
          if (!is_file(BW_PUBLIC_DIR . $href)) continue;
      ?>
        <a href="<?= e(absolute_url($href)) ?>" class="block hover:text-indigo-900"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="border-t border-slate-200 py-4 text-center text-xs text-slate-500">
    <?php
      $footer = app_setting('footer_text', '');
      echo $footer !== ''
          ? e($footer)
          : '© ' . date('Y') . ' ' . e(app_setting('institute_name', 'Brainwavers')) . '. All rights reserved.';
    ?>
  </div>
</footer>
<script src="<?= e(absolute_url('/assets/js/public.js')) ?>"></script>
</body>
</html>