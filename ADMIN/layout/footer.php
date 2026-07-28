<?php
// Footer ng Admin layout. Dito lang ilalagay ang common JS at closing HTML.
$adminJsFiles = $adminJsFiles ?? [
    '/codesamplecaps/ADMIN/js/super_admin_dashboard.js',
    '/codesamplecaps/ADMIN/js/overview.js',
    '/codesamplecaps/assets/js/realtime-updates.js',
];
?>
</div>
<?php foreach ($adminJsFiles as $jsFile): ?>
    <script src="<?php echo htmlspecialchars($jsFile, ENT_QUOTES, 'UTF-8'); ?>"<?php echo str_contains($jsFile, 'realtime-updates.js') ? ' defer' : ''; ?>></script>
<?php endforeach; ?>
</body>
</html>
