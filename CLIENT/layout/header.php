<?php
// Centralized header ng Client pages.
$clientPageTitle = $clientPageTitle ?? 'Client Dashboard - Edge Automation';
$clientCssFiles = $clientCssFiles ?? [
    '/codesamplecaps/CLIENT/css/client_dashboard.css',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($clientPageTitle, ENT_QUOTES, 'UTF-8'); ?></title>

    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar-state.js"></script>
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js" defer></script>

    <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/header/core/header.css">

    <?php foreach ($clientCssFiles as $cssFile): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars((string)$cssFile, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="icon"
        type="image/x-icon"
        href="/codesamplecaps/IMAGES/edge.jpg"
    >
</head>

<body>