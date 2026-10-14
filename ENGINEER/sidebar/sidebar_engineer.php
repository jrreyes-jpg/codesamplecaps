<?php
$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$currentFile = basename($currentPath);

$isOverview = in_array($currentFile, ['overview.php', 'engineer_dashboard.php'], true);
$isProjects = $currentFile === 'projects.php';
$isInspections = $currentFile === 'site_inspections.php';
?>
<?php auth_render_back_button_logout_script(); ?>
<script>
    // I-apply agad ang saved shrink state para hindi gumalaw ang sidebar kada lipat ng page.
    (function () {
        try {
            if (window.innerWidth > 768 && window.localStorage.getItem('engineer.sidebar.shrink') === '1') {
                document.documentElement.classList.add('ops-sidebar-shrink-pref');
            }
        } catch (error) {
            // Safe fallback kapag blocked ang browser storage.
        }
    })();
</script>
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

<nav class="sidebar sidebar--engineer" id="sidebar">
    <div class="brand-block">
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
        <a class="brand-title brand-title-link" href="/codesamplecaps/ENGINEER/dashboards/overview.php">Engineer</a>
    </div>
    <div class="nav-divider"></div>
    <ul class="nav-menu">
        <li>
            <a href="/codesamplecaps/ENGINEER/dashboards/overview.php" class="menu-link<?php echo $isOverview ? ' active-link' : ''; ?>">
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
            <a href="/codesamplecaps/ENGINEER/dashboards/site_inspections.php" class="menu-link<?php echo $isInspections ? ' active-link' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M12 21s6-5.4 6-11a6 6 0 1 0-12 0c0 5.6 6 11 6 11z"></path>
                            <circle cx="12" cy="10" r="2"></circle>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Site</span>
                </span>
                <span class="menu-text">Site Inspections</span>
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
