<?php
// Shared admin UI classes ang gamit dito para same design sa Super Admin.
$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$isDashboard = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/dashboard.php')
    || str_contains($currentPath, '/INVENTORY_CLERK/dashboards/inventory_clerk_dashboard.php');
$isInventory = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/inventory.php');
$isStockIn = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/stock_in.php');
$isStockOut = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/stock_out.php');
$isStockHistory = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/stock_history.php');
$inventoryClerkName = (string)($_SESSION['name'] ?? 'Inventory Clerk');
$inventoryClerkRole = ucfirst(str_replace('_', ' ', (string)($_SESSION['role'] ?? 'inventory_clerk')));
$initials = '';
foreach (preg_split('/\s+/', trim($inventoryClerkName)) as $namePart) {
    if ($namePart !== '') {
        $initials .= strtoupper(substr($namePart, 0, 1));
    }
}
$initials = substr($initials !== '' ? $initials : 'IC', 0, 2);
?>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>
<header class="global-topbar" aria-live="polite">
    <a href="/codesamplecaps/INVENTORY_CLERK/sidebar/dashboard.php" class="global-topbar__copy global-topbar__brand-link" aria-label="Go to Inventory Clerk dashboard">
        <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo" class="global-topbar__brand-logo">
        <strong>EDGE Automation</strong>
    </a>
    <div class="global-topbar__actions">
        <div class="topbar-profile" data-profile-root>
            <button
                title="Account"
                id="topbarProfileToggle"
                class="topbar-profile__toggle"
                type="button"
                aria-label="Open profile menu"
                aria-controls="topbarProfileDropdown"
                aria-expanded="false"
            >
                <span class="topbar-profile__avatar-shell" aria-hidden="true">
                    <span class="topbar-profile__avatar-fallback"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="topbar-profile__chevron-badge">
                        <span class="topbar-profile__chevron" aria-hidden="true">
                            <svg viewBox="0 0 20 20" focusable="false">
                                <path d="M5 7.5 10 12.5 15 7.5"></path>
                            </svg>
                        </span>
                    </span>
                </span>
            </button>

            <div id="topbarProfileDropdown" class="topbar-profile__dropdown" hidden>
                <div class="topbar-profile__panel-head">
                    <span class="topbar-profile__avatar-shell topbar-profile__avatar-shell--panel" aria-hidden="true">
                        <span class="topbar-profile__avatar-fallback topbar-profile__avatar-fallback--panel"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <div>
                        <strong><?php echo htmlspecialchars($inventoryClerkName, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span><?php echo htmlspecialchars($inventoryClerkRole, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="topbar-profile__links">
                    <a href="/codesamplecaps/INVENTORY_CLERK/sidebar/dashboard.php">Dashboard</a>
                    <a href="/codesamplecaps/LOGIN/php/logout.php">Logout</a>
                </div>
            </div>
        </div>
        <div class="topbar-notifications" data-notification-root>
            <button
                title="Notifications"
                id="topbarNotificationToggle"
                class="topbar-notifications__toggle"
                type="button"
                aria-label="Open notifications"
                aria-controls="topbarNotificationDropdown"
                aria-expanded="false"
            >
                <span class="topbar-notifications__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor"/>
                    </svg>
                </span>
            </button>

            <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
                <div class="topbar-notifications__panel-head">
                    <div>
                        <strong>Notifications</strong>
                        <span>No new alerts</span>
                    </div>
                </div>
                <div class="topbar-notifications__empty">No inventory alerts right now.</div>
            </div>
        </div>
        <div class="global-topbar__clock">
            <span class="global-topbar__clock-label">Philippines Time</span>
            <strong class="global-topbar__time" data-ph-time>--:--:--</strong>
            <span class="global-topbar__date" data-ph-date>Loading date</span>
        </div>
    </div>
</header>
