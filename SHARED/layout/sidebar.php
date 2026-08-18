<?php
require_once __DIR__ . '/../config/navigation.php';

if (function_exists('auth_render_back_button_logout_script')) {
    auth_render_back_button_logout_script();
}

$sharedSidebarRole = (string)($_SESSION['role'] ?? '');
$sharedSidebarRoleKey = $sharedSidebarRole === 'admin' ? 'admin' : ($sharedSidebarRole === 'engineer' ? 'engineer' : $sharedSidebarRole);
$sharedSidebarItems = shared_navigation_items_for_role($sharedSidebarRoleKey);
$sharedSidebarPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$sharedSidebarPath = preg_replace('#^/codesamplecaps#i', '', $sharedSidebarPath) ?: $sharedSidebarPath;
$sharedSidebarQuery = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '';
$sharedSidebarCurrent = $sharedSidebarPath . ($sharedSidebarQuery !== '' ? '?' . $sharedSidebarQuery : '');
$sharedSidebarBrand = strtoupper(str_replace('_', ' ', $sharedSidebarRoleKey ?: 'Menu'));
$sharedSidebarHome = shared_navigation_role_home($sharedSidebarRoleKey);

if (!function_exists('shared_sidebar_icon')) {
    function shared_sidebar_icon(string $icon): string
    {
        $icons = [
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="2"></rect><rect x="14" y="3" width="7" height="5" rx="2"></rect><rect x="14" y="10" width="7" height="11" rx="2"></rect><rect x="3" y="12" width="7" height="9" rx="2"></rect>',
            'projects' => '<path d="M3.5 7.5a2 2 0 0 1 2-2h4l1.6 1.8H18.5a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"></path><path d="M3.5 10.5h17"></path>',
            'assets' => '<path d="M4 19h16"></path><path d="M6 19V9l6-4 6 4v10"></path><path d="M9 19v-4h6v4"></path>',
            'quotations' => '<path d="M7 4h10"></path><path d="M7 8h10"></path><path d="M7 12h6"></path><path d="M6 20h12a2 2 0 0 0 2-2V6l-4-4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z"></path>',
            'inquiries' => '<path d="M5 6h14"></path><path d="M5 10h14"></path><path d="M5 14h8"></path><path d="M5 18h10"></path><path d="M17 16l2 2 3-4"></path>',
            'reports' => '<path d="M5 19h14"></path><path d="M7 16V9"></path><path d="M12 16V5"></path><path d="M17 16v-4"></path>',
            'activity' => '<path d="M12 8v5l3 2"></path><circle cx="12" cy="12" r="8"></circle>',
            'archive' => '<path d="M5 7h14"></path><path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7"></path><path d="M7 7l.8 11a2 2 0 0 0 2 1.85h4.4a2 2 0 0 0 2-1.85L17 7"></path><path d="M10 11v5"></path><path d="M14 11v5"></path>',
            'tasks' => '<path d="M8 7h10"></path><path d="M8 12h10"></path><path d="M8 17h10"></path><path d="M4.5 7h.01"></path><path d="M4.5 12h.01"></path><path d="M4.5 17h.01"></path>',
            'procurement' => '<path d="M4 7h16"></path><path d="M6 7V5.5A1.5 1.5 0 0 1 7.5 4h9A1.5 1.5 0 0 1 18 5.5V7"></path><path d="M6 7v11a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"></path><path d="M9 12h6"></path><path d="M9 16h4"></path>',
            'inventory' => '<path d="M4 6h16"></path><path d="M4 10h16"></path><path d="M6 10v8a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-8"></path><path d="M9 14h6"></path><path d="M9 17h4"></path>',
            'site' => '<path d="M12 21s6-5.4 6-11a6 6 0 1 0-12 0c0 5.6 6 11 6 11z"></path><circle cx="12" cy="10" r="2"></circle>',
            'progress' => '<path d="M12 6v6l4 2"></path><path d="M21 12a9 9 0 1 1-3-6.7"></path>',
        ];

        return $icons[$icon] ?? $icons['dashboard'];
    }
}

if (!function_exists('shared_sidebar_is_active')) {
    function shared_sidebar_is_active(array $item, string $path, string $current): bool
    {
        $query = parse_url($current, PHP_URL_QUERY) ?? '';

        foreach (($item['exclude_query'] ?? []) as $excludedQuery) {
            if ($query !== '' && str_contains($query, (string)$excludedQuery)) {
                return false;
            }
        }

        foreach (($item['active'] ?? []) as $activeRoute) {
            $activeRoute = (string)$activeRoute;
            if (str_contains($activeRoute, '?')) {
                if ($current === $activeRoute) {
                    return true;
                }
                continue;
            }

            if ($path === $activeRoute) {
                return true;
            }
        }

        return false;
    }
}
?>
<button class="sidebar-mobile-toggle" type="button" aria-label="Toggle menu" data-sidebar-mobile-toggle>
    <span></span>
    <span></span>
    <span></span>
</button>

<nav class="sidebar sidebar--<?php echo htmlspecialchars($sharedSidebarRoleKey, ENT_QUOTES, 'UTF-8'); ?>" id="sidebar" data-shared-sidebar>
    <div class="brand-block">
        <button class="sidebar-toggle" type="button" aria-label="Collapse menu" aria-expanded="true" data-sidebar-toggle>
            <span class="sidebar-toggle-icon" aria-hidden="true">
                <svg class="sidebar-toggle-svg" viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                    <path d="M11.75 4.75L6.5 10l5.25 5.25"></path>
                </svg>
            </span>
        </button>
        <a class="brand-title brand-title-link" href="<?php echo htmlspecialchars($sharedSidebarHome, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($sharedSidebarBrand, ENT_QUOTES, 'UTF-8'); ?>
        </a>
    </div>
    <div class="nav-divider"></div>
    <ul class="nav-menu">
        <?php foreach ($sharedSidebarItems as $item): ?>
            <?php $isActive = shared_sidebar_is_active($item, $sharedSidebarPath, $sharedSidebarCurrent); ?>
            <li>
                <a
                    href="<?php echo htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8'); ?>"
                    class="menu-link<?php echo $isActive ? ' active-link' : ''; ?>"
                    <?php echo $isActive ? 'aria-current="page" data-active="true"' : ''; ?>
                >
                    <span class="menu-visual" aria-hidden="true">
                        <span class="menu-icon">
                            <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                <?php echo shared_sidebar_icon((string)$item['icon']); ?>
                            </svg>
                        </span>
                        <span class="menu-mini-label"><?php echo htmlspecialchars((string)$item['mini'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <span class="menu-text"><?php echo htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
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
