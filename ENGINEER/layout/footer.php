<?php
// Iisang footer para shared ang header at page scripts ng Engineer.
$engineerJsFiles = is_array($engineerJsFiles ?? null) ? $engineerJsFiles : [];
$sharedJsFiles = [
    '/codesamplecaps/assets/js/app-window-guard.js',
    '/codesamplecaps/SHARED/header/core/operations-header.js',
    '/codesamplecaps/ENGINEER/js/engineer.js',
];
$allEngineerJsFiles = array_values(array_unique(array_merge($sharedJsFiles, $engineerJsFiles)));
?>
</div>
<?php foreach ($allEngineerJsFiles as $jsFile): ?>
    <script src="<?php echo htmlspecialchars((string)$jsFile, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endforeach; ?>
</body>
</html>
