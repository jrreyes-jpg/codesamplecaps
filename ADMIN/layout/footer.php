<?php
// Footer ng Admin layout. Dito lang ilalagay ang common JS at closing HTML.
$adminJsFiles = $adminJsFiles ?? [
    '/codesamplecaps/ADMIN/js/super_admin_dashboard.js',
    '/codesamplecaps/ADMIN/js/overview.js',
    '/codesamplecaps/assets/js/realtime-updates.js',
];

if (!in_array('/codesamplecaps/assets/js/app-window-guard.js', $adminJsFiles, true)) {
    array_unshift($adminJsFiles, '/codesamplecaps/assets/js/app-window-guard.js');
}
?>
</div>
<?php foreach ($adminJsFiles as $jsFile): ?>
    <script src="<?php echo htmlspecialchars($jsFile, ENT_QUOTES, 'UTF-8'); ?>"<?php echo str_contains($jsFile, 'realtime-updates.js') ? ' defer' : ''; ?>></script>
<?php endforeach; ?>
</body>
</html>
