<?php
// Shared shell ng Inventory Clerk para pantay lagi ang header, sidebar, at main spacing.
function inventory_clerk_render_page(string $pageTitle, callable $renderContent, array $pageStyles = [], string $mainClass = ''): void
{
    $mainClasses = trim('main-content ' . $mainClass);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
        <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/base.css">
        <link rel="stylesheet" href="/codesamplecaps/INVENTORY_CLERK/css/inventory_clerk_dashboard.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/header.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/notifications.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/layout.css">
        <link rel="stylesheet" href="/codesamplecaps/assets/css/responsive-foundation.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/chrome-final.css">
        <?php foreach ($pageStyles as $stylePath): ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars((string)$stylePath, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
        <link rel="icon" type="image/x-icon" href="/codesamplecaps/IMAGES/edge.jpg">
    </head>
    <body>
    <div class="container">
        <?php include __DIR__ . '/../sidebar/inventory_clerk_sidebar.php'; ?>
        <main class="<?php echo htmlspecialchars($mainClasses, ENT_QUOTES, 'UTF-8'); ?>">
            <?php $renderContent(); ?>
        </main>
    </div>
    <script src="/codesamplecaps/assets/js/app-window-guard.js"></script>
    <script src="/codesamplecaps/INVENTORY_CLERK/js/inventory_clerk_dashboard.js"></script>
    </body>
    </html>
    <?php
}
