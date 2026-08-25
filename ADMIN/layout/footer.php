<?php
// Footer ng Admin layout. Dito lang ilalagay ang common JS at closing HTML.
$adminJsFiles = $adminJsFiles ?? [
    '/codesamplecaps/SHARED/sidebar/js/sidebar.js',
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/assets/js/realtime-updates.js',
];

if (!in_array('/codesamplecaps/SHARED/sidebar/js/sidebar.js', $adminJsFiles, true)) {
    array_unshift($adminJsFiles, '/codesamplecaps/SHARED/sidebar/js/sidebar.js');
}

if (!in_array('/codesamplecaps/SHARED/js/operations-header.js', $adminJsFiles, true)) {
    array_unshift($adminJsFiles, '/codesamplecaps/SHARED/js/operations-header.js');
}

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
