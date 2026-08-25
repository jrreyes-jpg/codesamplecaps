<?php
require_once __DIR__ . '/../includes/foreman_helpers.php';

$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$currentQuery = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '';
$currentFile = basename($currentPath);
$foremanProfileName = $foremanProfileName ?? (string)($_SESSION['name'] ?? 'Foreman');
$foremanProfileRole = 'Foreman';
$foremanProfileInitials = foreman_profile_initials($foremanProfileName);
$foremanNotifications = $foremanNotifications ?? [
    'attention_count' => 0,
    'logs_today' => 0,
    'scans_today' => 0,
];

$isOverview = $currentFile === 'foreman_dashboard.php';
$isArchive = $currentFile === 'projects.php' && (str_contains($currentQuery, 'view=trash') || str_contains($currentQuery, 'view=archive'));
$isProjects = $currentFile === 'projects.php' && !$isArchive;
$isReports = in_array($currentFile, ['reports.php', 'report_list.php', 'report_detail.php'], true);
$isProcurement = $currentFile === 'procurement.php';
$isQuotations = $currentFile === 'quotation_reviews.php';
$isAssets = $currentFile === 'asset_status.php';
$isLogs = $currentFile === 'usage_logs.php';
$isWorkers = $currentFile === 'worker_summary.php';
?>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>
<header class="global-topbar" aria-live="polite">
    <a href="/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php" class="global-topbar__copy global-topbar__brand-link" aria-label="Go to Foreman overview">
        <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo" class="global-topbar__brand-logo">
        <strong>EDGE Automation</strong>
    </a>

    <div class="global-topbar__actions">
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
                        <path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor"></path>
                    </svg>
                </span>
                <?php if (($foremanNotifications['attention_count'] ?? 0) > 0): ?>
                    <span class="topbar-notifications__badge"><?php echo (int)$foremanNotifications['attention_count']; ?></span>
                <?php endif; ?>
            </button>

            <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
                <div class="topbar-notifications__panel-head">
                    <div>
                        <strong>Today</strong>
                        <span><?php echo (int)($foremanNotifications['attention_count'] ?? 0); ?> need attention</span>
                    </div>
                </div>
                <div class="topbar-notifications__section">
                    <article class="notification-item notification-item--neutral">
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo (int)($foremanNotifications['logs_today'] ?? 0); ?> usage log(s)</strong>
                            <span>Field asset usage recorded today.</span>
                        </div>
                    </article>
                    <article class="notification-item notification-item--neutral">
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo (int)($foremanNotifications['scans_today'] ?? 0); ?> scan(s)</strong>
                            <span>QR scans captured by this account today.</span>
                        </div>
                    </article>
                    <article class="notification-item notification-item--warning">
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo (int)($foremanNotifications['attention_count'] ?? 0); ?> asset(s) need follow-up</strong>
                            <span>Maintenance, damaged, or lost assets require checking.</span>
                        </div>
                    </article>
                </div>
            </div>
        </div>

        <div class="topbar-profile" data-profile-root>
            <button
                id="topbarProfileToggle"
                class="topbar-profile__toggle"
                type="button"
                aria-label="Open profile menu"
                aria-controls="topbarProfileDropdown"
                aria-expanded="false"
            >
                <span class="topbar-profile__avatar"><?php echo htmlspecialchars($foremanProfileInitials); ?></span>
                <span class="topbar-profile__chevron" aria-hidden="true">
                    <svg viewBox="0 0 20 20" focusable="false">
                        <path d="M5 7.5 10 12.5 15 7.5"></path>
                    </svg>
                </span>
            </button>

            <div id="topbarProfileDropdown" class="topbar-profile__dropdown" hidden>
                <div class="topbar-profile__panel-head">
                    <span class="topbar-profile__avatar topbar-profile__avatar--panel"><?php echo htmlspecialchars($foremanProfileInitials); ?></span>
                    <div>
                        <strong><?php echo htmlspecialchars($foremanProfileName); ?></strong>
                        <span><?php echo htmlspecialchars($foremanProfileRole); ?></span>
                    </div>
                </div>
                <div class="topbar-profile__links">
                    <a href="/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php">Overview</a>
                    <a href="/codesamplecaps/LOGIN/php/forgot.php">Reset Password</a>
                    <a href="/codesamplecaps/LOGIN/php/logout.php">Logout</a>
                </div>
            </div>
        </div>

        <div class="global-topbar__clock">
            <span class="global-topbar__clock-label">Philippines Time</span>
            <strong class="global-topbar__time" data-ph-time>--:--:--</strong>
            <span class="global-topbar__date" data-ph-date>Loading date...</span>
        </div>
    </div>
</header>
