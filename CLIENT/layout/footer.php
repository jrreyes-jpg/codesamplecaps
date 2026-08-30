<?php
// Centralized footer ng Client pages.
$clientJsFiles = $clientJsFiles ?? [];

if (!in_array('/codesamplecaps/assets/js/app-window-guard.js', $clientJsFiles, true)) {
    array_unshift(
        $clientJsFiles,
        '/codesamplecaps/assets/js/app-window-guard.js'
    );
}
?>

<?php foreach ($clientJsFiles as $jsFile): ?>
    <script src="<?php echo htmlspecialchars((string)$jsFile, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endforeach; ?>

</body>
</html>