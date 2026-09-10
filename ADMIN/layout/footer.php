<?php
// Footer ng Admin layout. Dito lang ilalagay ang common JS at closing HTML.
$adminJsFiles = $adminJsFiles ?? [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/assets/js/realtime-updates.js',
];

$sharedAdminJsFiles = [
    '/codesamplecaps/assets/js/app-window-guard.js',
    '/codesamplecaps/SHARED/header/core/operations-header.js',
    '/codesamplecaps/SHARED/toast/js/toast.js',
];

$adminJsFiles = array_values(array_unique(array_merge($sharedAdminJsFiles, $adminJsFiles)));
?>
</div>
<?php foreach ($adminJsFiles as $jsFile): ?>
    <script src="<?php echo htmlspecialchars($jsFile, ENT_QUOTES, 'UTF-8'); ?>"<?php echo str_contains($jsFile, 'realtime-updates.js') ? ' defer' : ''; ?>></script>
<?php endforeach; ?>
</body>
</html>
