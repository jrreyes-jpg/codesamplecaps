<?php
// Iisang shell para shared ang header, sidebar, at page spacing ng Inventory Clerk.
require_once __DIR__ . '/../../config/profile_photo_storage.php';

if (!function_exists('inventory_clerk_render_header')) {
    function inventory_clerk_render_header(mysqli $conn): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $name = trim((string)($_SESSION['name'] ?? 'Inventory Clerk'));
        $photoUrl = '';

        $profileStmt = $conn->prepare('SELECT full_name, profile_photo_path FROM users WHERE id = ? LIMIT 1');
        if ($profileStmt) {
            $profileStmt->bind_param('i', $userId);
            $profileStmt->execute();
            $profile = $profileStmt->get_result()->fetch_assoc() ?: [];
            $profileStmt->close();
            $name = trim((string)($profile['full_name'] ?? $name)) ?: $name;
            $photoPath = trim((string)($profile['profile_photo_path'] ?? ''));
            if ($photoPath !== '') {
                $photoUrl = profile_photo_public_url($photoPath, $userId);
            }
        }

        $initials = '';
        foreach (preg_split('/\s+/', $name) ?: [] as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        $initials = substr($initials ?: 'IC', 0, 2);

        ob_start();
        $headerProfileRootAttr = 'data-profile-root';
        $headerProfileToggleId = 'topbarProfileToggle';
        $headerProfileDropdownId = 'topbarProfileDropdown';
        $headerProfileToggleAttr = 'data-profile-toggle';
        $headerProfileName = $name;
        $headerProfileRole = 'Inventory Clerk';
        $headerProfilePhotoUrl = $photoUrl;
        $headerProfileInitials = $initials;
        $headerProfileAlt = 'Inventory Clerk profile photo';
        $headerProfileLinks = [
            ['label' => 'Dashboard', 'href' => '/codesamplecaps/INVENTORY_CLERK/sidebar/dashboard.php'],
            ['label' => 'Logout', 'href' => '/codesamplecaps/LOGIN/php/logout.php'],
        ];
        include __DIR__ . '/../../SHARED/header/profile/php/profile.php';
        ?>
        <div class="topbar-notifications" data-notification-root>
            <button id="topbarNotificationToggle" class="topbar-notifications__toggle" type="button" aria-label="Open notifications" aria-controls="topbarNotificationDropdown" aria-expanded="false">
                <span class="topbar-notifications__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor"/></svg></span>
            </button>
            <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
                <div class="topbar-notifications__panel-head"><div><strong>Notifications</strong><span>No new alerts</span></div></div>
                <div class="topbar-notifications__empty">No inventory alerts right now.</div>
            </div>
        </div>
        <?php
        $operationsHeaderActionsHtml = (string)ob_get_clean();
        $operationsHeaderRole = 'inventory_clerk';
        $operationsHeaderClass = 'global-topbar';
        $operationsHeaderBrandClass = 'global-topbar__copy global-topbar__brand-link';
        $operationsHeaderActionsClass = 'global-topbar__actions';
        $operationsHeaderClockClass = 'global-topbar__clock';
        $operationsHeaderHomeHref = '/codesamplecaps/INVENTORY_CLERK/sidebar/dashboard.php';
        $operationsHeaderBrandText = 'EDGE Automation';
        $operationsHeaderLogoClass = 'global-topbar__brand-logo operations-topbar__brand-logo';
        $operationsHeaderBrandLabel = 'Go to Inventory Clerk dashboard';
        $operationsHeaderTime = '--:--:--';
        $operationsHeaderDate = 'Loading date...';
        $operationsHeaderTimeAttr = 'class="global-topbar__time" data-ph-time';
        $operationsHeaderDateAttr = 'class="global-topbar__date" data-ph-date';
        $operationsHeaderAttrs = 'aria-live="polite"';
        include __DIR__ . '/../../SHARED/header/core/operations-header.php';
    }
}

function inventory_clerk_render_page(string $pageTitle, callable $renderContent, array $pageStyles = [], string $mainClass = ''): void
{
    global $conn;
    $mainClasses = trim('main-content ' . $mainClass);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
        <script src="/codesamplecaps/SHARED/sidebar/js/sidebar-state.js"></script>
        <script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js" defer></script>
        <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/base.css">
        <link rel="stylesheet" href="/codesamplecaps/INVENTORY_CLERK/css/inventory_clerk_dashboard.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/header/core/header.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/layout.css">
        <link rel="stylesheet" href="/codesamplecaps/assets/css/responsive-foundation.css">
        <?php foreach ($pageStyles as $stylePath): ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars((string)$stylePath, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
        <link rel="icon" type="image/x-icon" href="/codesamplecaps/IMAGES/edge.jpg">
    </head>
    <body>
    <div class="container">
        <?php include __DIR__ . '/../sidebar/inventory_clerk_sidebar.php'; ?>
        <div class="<?php echo htmlspecialchars($mainClasses, ENT_QUOTES, 'UTF-8'); ?>">
            <?php inventory_clerk_render_header($conn); ?>
            <main class="inventory-clerk-page-body">
                <?php $renderContent(); ?>
            </main>
        </div>
    </div>
    <script src="/codesamplecaps/assets/js/app-window-guard.js"></script>
    <script src="/codesamplecaps/SHARED/header/core/operations-header.js"></script>
    </body>
    </html>
    <?php
}
