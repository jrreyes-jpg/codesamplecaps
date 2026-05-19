<?php
require_once __DIR__ . '/../includes/foreman_helpers.php';

$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
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
$isProjects = $currentFile === 'projects.php';
$isReports = in_array($currentFile, ['reports.php', 'report_list.php', 'report_detail.php'], true);
$isProcurement = $currentFile === 'procurement.php';
$isQuotations = $currentFile === 'quotation_reviews.php';
$isAssets = $currentFile === 'asset_status.php';
$isLogs = $currentFile === 'usage_logs.php';
$isWorkers = $currentFile === 'worker_summary.php';
?>
<?php auth_render_back_button_logout_script(); ?>
<button id="sidebarMobileToggle" class="sidebar-mobile-toggle" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
    <span></span>
    <span></span>
    <span></span>
</button>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-toggle-row">
        <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-controls="sidebar" aria-expanded="true">
            <span id="toggleIcon" class="sidebar-toggle-icon" aria-hidden="true">
                <svg class="sidebar-toggle-svg" viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                    <path d="M11.75 4.75L6.5 10l5.25 5.25"></path>
                </svg>
            </span>
        </button>
        <div class="sidebar-toggle-title sidebar-toggle-title--brand" aria-hidden="true">
            <span class="sidebar-toggle-title__eyebrow">Field Ops</span>
            <span class="sidebar-toggle-title__shine">Foreman Workspace</span>
        </div>
    </div>

    <div class="nav-divider"></div>

    <div class="sidebar-section-label">Workspace</div>
    <ul class="nav-menu">
        <li>
            <a href="/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php" class="menu-link<?php echo $isOverview ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="2"></rect>
                            <rect x="14" y="3" width="7" height="5" rx="2"></rect>
                            <rect x="14" y="10" width="7" height="11" rx="2"></rect>
                            <rect x="3" y="12" width="7" height="9" rx="2"></rect>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Home</span>
                </span>
                <span class="menu-text">Overview</span>
            </a>
        </li>
        <li>
            <button id="qrScannerBtn" class="menu-link menu-link--button" type="button">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M7 4H5a1 1 0 0 0-1 1v2"></path>
                            <path d="M17 4h2a1 1 0 0 1 1 1v2"></path>
                            <path d="M20 17v2a1 1 0 0 1-1 1h-2"></path>
                            <path d="M4 17v2a1 1 0 0 0 1 1h2"></path>
                            <path d="M7 12h10"></path>
                            <path d="M12 7v10"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Scan</span>
                </span>
                <span class="menu-text">Scan Asset</span>
            </button>
        </li>
        <li>
            <a href="/codesamplecaps/FOREMAN/dashboards/projects.php" class="menu-link<?php echo $isProjects ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M4 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7z"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Proj</span>
                </span>
                <span class="menu-text">My Projects</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/FOREMAN/dashboards/reports.php" class="menu-link<?php echo $isReports ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M7 4h10"></path>
                            <path d="M7 8h10"></path>
                            <path d="M7 12h6"></path>
                            <path d="M6 20h12a2 2 0 0 0 2-2V6l-4-4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Rep</span>
                </span>
                <span class="menu-text">Reports</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/FOREMAN/dashboards/procurement.php" class="menu-link<?php echo $isProcurement ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M4 7h16"></path>
                            <path d="M6 7V5.5A1.5 1.5 0 0 1 7.5 4h9A1.5 1.5 0 0 1 18 5.5V7"></path>
                            <path d="M6 7v11a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"></path>
                            <path d="M9 12h6"></path>
                            <path d="M9 16h4"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Proc</span>
                </span>
                <span class="menu-text">Procurement</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php" class="menu-link<?php echo $isQuotations ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M7 4h10"></path>
                            <path d="M7 8h10"></path>
                            <path d="M7 12h6"></path>
                            <path d="M6 20h12a2 2 0 0 0 2-2V6l-4-4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Quote</span>
                </span>
                <span class="menu-text">Review Quotations</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/FOREMAN/dashboards/asset_status.php" class="menu-link<?php echo $isAssets ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M4 8.5 12 4l8 4.5"></path>
                            <path d="M4 8.5V16l8 4 8-4V8.5"></path>
                            <path d="M12 12l8-3.5"></path>
                            <path d="M12 12 4 8.5"></path>
                            <path d="M12 12v8"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Asset</span>
                </span>
                <span class="menu-text">Asset Status</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/FOREMAN/dashboards/usage_logs.php" class="menu-link<?php echo $isLogs ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M8 6h8"></path>
                            <path d="M8 10h8"></path>
                            <path d="M8 14h5"></path>
                            <rect x="4" y="3" width="16" height="18" rx="2"></rect>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Logs</span>
                </span>
                <span class="menu-text">Usage Logs</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/FOREMAN/dashboards/worker_summary.php" class="menu-link<?php echo $isWorkers ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M16 20a4 4 0 0 0-8 0"></path>
                            <circle cx="12" cy="9" r="3.5"></circle>
                            <path d="M19 20a3 3 0 0 0-3-3"></path>
                            <path d="M5 20a3 3 0 0 1 3-3"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Team</span>
                </span>
                <span class="menu-text">Worker Summary</span>
            </a>
        </li>
    </ul>

    <div class="nav-divider"></div>
    <div class="sidebar-section-label">Account</div>
    <ul class="nav-menu nav-menu--utility">
        <li>
            <a href="/codesamplecaps/LOGIN/php/forgot.php" class="menu-link">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <rect x="5" y="11" width="14" height="9" rx="2"></rect>
                            <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Pass</span>
                </span>
                <span class="menu-text">Reset Password</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/LOGIN/php/logout.php" class="menu-link logout">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M10 5H6.5A1.5 1.5 0 0 0 5 6.5v11A1.5 1.5 0 0 0 6.5 19H10"></path>
                            <path d="M13 8l4 4-4 4"></path>
                            <path d="M9 12h8"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Exit</span>
                </span>
                <span class="menu-text">Logout</span>
            </a>
        </li>
    </ul>
</nav>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<header class="global-topbar" aria-live="polite">
    <div class="global-topbar__copy">
        <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo" class="global-topbar__brand-logo">
        <strong>EDGE Automation</strong>
    </div>

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
