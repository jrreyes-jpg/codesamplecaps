<?php
// Centralized header ng lahat ng Admin pages.
$adminPageTitle = $adminPageTitle ?? 'Admin Dashboard - Edge Automation';
$adminCssFiles = $adminCssFiles ?? [];

if (!is_array($adminCssFiles)) {
    $adminCssFiles = [];
}
// Shared styles muna bago page-specific styles para puwedeng
// mag-override ang individual Admin pages kapag kailangan.
$sharedCssFiles = [
    '/codesamplecaps/SHARED/header/core/header.css',
    '/codesamplecaps/SHARED/sidebar/css/sidebar.css',
];

$allAdminCssFiles = array_values(
    array_unique(
        array_merge($sharedCssFiles, $adminCssFiles)
    )
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- Apply sidebar state early para maiwasan ang layout flash. -->
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar-state.js"></script>

    <!-- Shared sidebar behavior for all Admin pages. -->
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js" defer></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <?php foreach ($allAdminCssFiles as $cssFile): ?>
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
    <div class="container">