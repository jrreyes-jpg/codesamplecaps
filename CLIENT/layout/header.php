<?php
// Centralized header ng lahat ng Client pages.
$clientPageTitle = $clientPageTitle ?? 'Client Dashboard - Edge Automation';

$clientCssFiles = $clientCssFiles ?? [];

if (!is_array($clientCssFiles)) {
    $clientCssFiles = [];
}

// Shared CSS na ginagamit din ng ibang roles.
$sharedCssFiles = [
    '/codesamplecaps/SHARED/header/core/header.css',
    '/codesamplecaps/SHARED/sidebar/css/sidebar.css',
    '/codesamplecaps/CLIENT/common/css/client-common.css',
];

$allClientCssFiles = array_values(
    array_unique(
        array_merge($sharedCssFiles, $clientCssFiles)
    )
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($clientPageTitle, ENT_QUOTES, 'UTF-8'); ?></title>

    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar-state.js"></script>
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js" defer></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <?php foreach ($allClientCssFiles as $cssFile): ?>
        <link
            rel="stylesheet"
            href="<?php echo htmlspecialchars((string)$cssFile, ENT_QUOTES, 'UTF-8'); ?>"
        >
    <?php endforeach; ?>

    <link
        rel="icon"
        type="image/x-icon"
        href="/codesamplecaps/IMAGES/edge.jpg"
    >
</head>

<body>
