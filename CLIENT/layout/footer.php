<?php
// Centralized footer ng lahat ng Client pages.
$clientJsFiles = $clientJsFiles ?? [];

if (!is_array($clientJsFiles)) {
    $clientJsFiles = [];
}

// Shared JavaScript na ginagamit ng lahat ng Client pages.
$sharedClientJsFiles = [
    '/codesamplecaps/assets/js/app-window-guard.js',
];

$allClientJsFiles = array_values(
    array_unique(
        array_merge($sharedClientJsFiles, $clientJsFiles)
    )
);
?>

<?php foreach ($allClientJsFiles as $jsFile): ?>
    <script src="<?php echo htmlspecialchars((string)$jsFile, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endforeach; ?>

</body>
</html>
