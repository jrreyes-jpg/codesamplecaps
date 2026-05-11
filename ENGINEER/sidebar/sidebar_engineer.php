<?php
$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$currentFile = basename($currentPath);

$isOverview = $currentFile === 'engineer_dashboard.php';
$isProjects = $currentFile === 'projects.php';
$isProcurement = $currentFile === 'procurement.php';
$isQuotations = in_array($currentFile, ['quotations.php', 'quotation_form.php'], true);
$isReports = $currentFile === 'reports.php';
$isTasks = $currentFile === 'tasks.php';
$isUpdates = $currentFile === 'progress_updates.php';
$isProfile = $currentFile === 'profile.php';
?>
<?php auth_render_back_button_logout_script(); ?>
<button
    class="sidebar-mobile-toggle"
    type="button"
    aria-label="Toggle menu"
    data-sidebar-mobile-toggle
>
    <span></span>
    <span></span>
    <span></span>
</button>

<nav class="sidebar" id="sidebar">
    <button
        class="sidebar-toggle"
        type="button"
        aria-label="Collapse menu"
        aria-expanded="true"
        data-sidebar-toggle
    >
        <span class="sidebar-toggle-icon" aria-hidden="true">
            <svg class="sidebar-toggle-svg" viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                <path d="M11.75 4.75L6.5 10l5.25 5.25"></path>
            </svg>
        </span>
    </button>
    <div class="brand-block">
        <span class="brand-title">Engineer Overview</span>
    </div>
    <div class="nav-divider"></div>
    <ul class="nav-menu">
        <li>
            <a href="/codesamplecaps/ENGINEER/dashboards/engineer_dashboard.php" class="menu-link<?php echo $isOverview ? ' active-link' : ''; ?>">
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
            <a href="/codesamplecaps/ENGINEER/dashboards/tasks.php" class="menu-link<?php echo $isTasks ? ' active-link' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M8 7h10"></path>
                            <path d="M8 12h10"></path>
                            <path d="M8 17h10"></path>
                            <path d="M4.5 7h.01"></path>
                            <path d="M4.5 12h.01"></path>
                            <path d="M4.5 17h.01"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Tasks</span>
                </span>
                <span class="menu-text">My Tasks</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/ENGINEER/dashboards/projects.php" class="menu-link<?php echo $isProjects ? ' active-link' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M3.5 7.5a2 2 0 0 1 2-2h4l1.6 1.8H18.5a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"></path>
                            <path d="M3.5 10.5h17"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Proj</span>
                </span>
                <span class="menu-text">My Projects</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/ENGINEER/dashboards/procurement.php" class="menu-link<?php echo $isProcurement ? ' active-link' : ''; ?>">
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
                    <span class="menu-mini-label">PO</span>
                </span>
                <span class="menu-text">Procurement</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/ENGINEER/dashboards/quotations.php" class="menu-link<?php echo $isQuotations ? ' active-link' : ''; ?>">
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
                <span class="menu-text">Quotations</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/ENGINEER/dashboards/reports.php" class="menu-link<?php echo $isReports ? ' active-link' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M5 19h14"></path>
                            <path d="M7 16V9"></path>
                            <path d="M12 16V5"></path>
                            <path d="M17 16v-4"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Rpt</span>
                </span>
                <span class="menu-text">Reports</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/ENGINEER/dashboards/progress_updates.php" class="menu-link<?php echo $isUpdates ? ' active-link' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M12 6v6l4 2"></path>
                            <path d="M21 12a9 9 0 1 1-3-6.7"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Update</span>
                </span>
                <span class="menu-text">Progress Updates</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/ENGINEER/dashboards/profile.php" class="menu-link<?php echo $isProfile ? ' active-link' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <circle cx="12" cy="8" r="3.25"></circle>
                            <path d="M5 19a7 7 0 0 1 14 0"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Profile</span>
                </span>
                <span class="menu-text">Profile</span>
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

<div class="sidebar-overlay" data-sidebar-overlay></div>
