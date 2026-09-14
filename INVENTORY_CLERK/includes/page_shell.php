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

        $inventoryAlerts = [];
        $materialShortages = [];
        $inventoryAlertCount = 0;
        $materialShortageCount = 0;

        $inventoryAlertCountResult = $conn->query(
            "SELECT COUNT(*) AS alert_count
             FROM inventory
             WHERE status IN ('low-stock', 'out-of-stock')"
        );
        if ($inventoryAlertCountResult instanceof mysqli_result) {
            $inventoryAlertCount = (int)(($inventoryAlertCountResult->fetch_assoc()['alert_count'] ?? 0));
        }

        $inventoryAlertResult = $conn->query(
            "SELECT a.asset_name, i.quantity, i.min_stock, i.status
             FROM inventory i
             INNER JOIN assets a ON a.id = i.asset_id
             WHERE i.status IN ('low-stock', 'out-of-stock')
             ORDER BY FIELD(i.status, 'out-of-stock', 'low-stock'), a.asset_name ASC
             LIMIT 8"
        );
        if ($inventoryAlertResult instanceof mysqli_result) {
            $inventoryAlerts = $inventoryAlertResult->fetch_all(MYSQLI_ASSOC);
        }

        $materialShortageCountResult = $conn->query(
            "SELECT COUNT(*) AS alert_count
             FROM project_material_reservations r
             WHERE r.status = 'active'
               AND r.required_quantity > r.reserved_quantity + r.issued_quantity"
        );
        if ($materialShortageCountResult instanceof mysqli_result) {
            $materialShortageCount = (int)(($materialShortageCountResult->fetch_assoc()['alert_count'] ?? 0));
        }

        $materialShortageResult = $conn->query(
            "SELECT p.project_name, m.material_name, m.unit,
                    GREATEST(r.required_quantity - r.reserved_quantity - r.issued_quantity, 0) AS shortage_quantity
             FROM project_material_reservations r
             INNER JOIN projects p ON p.id = r.project_id
             INNER JOIN materials m ON m.id = r.material_id
             WHERE r.status = 'active'
               AND r.required_quantity > r.reserved_quantity + r.issued_quantity
             ORDER BY p.created_at ASC, r.id ASC
             LIMIT 8"
        );
        if ($materialShortageResult instanceof mysqli_result) {
            $materialShortages = $materialShortageResult->fetch_all(MYSQLI_ASSOC);
        }

        $notificationAlertCount = $inventoryAlertCount + $materialShortageCount;

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
            ['label' => 'Logout', 'href' => '/codesamplecaps/LOGIN/php/logout.php'],
        ];
        include __DIR__ . '/../../SHARED/header/profile/php/profile.php';
        ?>
        <div class="topbar-notifications" data-notification-root>
            <button id="topbarNotificationToggle" class="topbar-notifications__toggle" type="button" aria-label="Open notifications" aria-controls="topbarNotificationDropdown" aria-expanded="false">
                <span class="topbar-notifications__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor"/></svg></span>
                <span class="topbar-notifications__badge"<?php echo $notificationAlertCount > 0 ? '' : ' hidden'; ?>><?php echo $notificationAlertCount > 99 ? '99+' : $notificationAlertCount; ?></span>
            </button>
            <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
                <div class="topbar-notifications__panel-head">
                    <div>
                        <strong>Inventory Alerts</strong>
                        <span><?php echo $notificationAlertCount > 0 ? $notificationAlertCount . ' need attention' : 'All clear'; ?></span>
                    </div>
                </div>
                <?php if ($notificationAlertCount === 0): ?>
                    <div class="topbar-notifications__empty">No inventory alerts right now.</div>
                <?php else: ?>
                    <div class="topbar-notifications__section">
                        <?php foreach ($inventoryAlerts as $alert): ?>
                            <?php $isOutOfStock = ($alert['status'] ?? '') === 'out-of-stock'; ?>
                            <a class="notification-item notification-item--<?php echo $isOutOfStock ? 'danger' : 'warning'; ?>" href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?status=<?php echo $isOutOfStock ? 'out-of-stock' : 'low-stock'; ?>">
                                <span class="notification-item__dot" aria-hidden="true"></span>
                                <span class="notification-item__copy">
                                    <strong><?php echo $isOutOfStock ? 'Out of Stock' : 'Low Stock'; ?></strong>
                                    <span><?php echo htmlspecialchars((string)$alert['asset_name']); ?> · <?php echo (int)$alert['quantity']; ?> available<?php echo $alert['min_stock'] !== null ? ' · Min: ' . (int)$alert['min_stock'] : ''; ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($materialShortages !== []): ?>
                        <div class="topbar-notifications__section">
                            <div class="topbar-notifications__section-title">Material Shortages</div>
                            <?php foreach ($materialShortages as $shortage): ?>
                                <a class="notification-item notification-item--warning" href="/codesamplecaps/INVENTORY_CLERK/dashboards/materials.php">
                                    <span class="notification-item__dot" aria-hidden="true"></span>
                                    <span class="notification-item__copy">
                                        <strong>Needs Procurement</strong>
                                        <span><?php echo htmlspecialchars((string)$shortage['material_name']); ?> · Short <?php echo htmlspecialchars((string)$shortage['shortage_quantity'] . ' ' . $shortage['unit']); ?> · <?php echo htmlspecialchars((string)$shortage['project_name']); ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $operationsHeaderActionsHtml = (string)ob_get_clean();
        $operationsHeaderRole = 'inventory_clerk';
        $operationsHeaderClass = 'global-topbar';
        $operationsHeaderBrandClass = 'global-topbar__copy global-topbar__brand-link';
        $operationsHeaderActionsClass = 'global-topbar__actions';
        $operationsHeaderClockClass = 'global-topbar__clock';
        $operationsHeaderHomeHref = '/codesamplecaps/INVENTORY_CLERK/dashboards/dashboard.php';
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

function inventory_clerk_render_page(string $pageTitle, callable $renderContent, array $pageStyles = [], string $mainClass = '', array $pageScripts = []): void
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
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/base.css">
        <link rel="stylesheet" href="/codesamplecaps/INVENTORY_CLERK/common/css/inventory-clerk-common.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/header/core/header.css">
        <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
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
    <script src="/codesamplecaps/INVENTORY_CLERK/common/js/inventory-clerk-common.js" defer></script>
    <?php foreach ($pageScripts as $scriptPath): ?>
        <script src="<?php echo htmlspecialchars((string)$scriptPath, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endforeach; ?>
    </body>
    </html>
    <?php
}
