<?php
require_once __DIR__ . '/../../config/navigation.php';

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
            'user' => '<path d="M16 20a4 4 0 0 0-8 0"></path><circle cx="12" cy="9" r="3.5"></circle><path d="M19 20a3 3 0 0 0-3-3"></path><path d="M5 20a3 3 0 0 1 3-3"></path>',
            'security' => '<path d="M12 3l7 4v5c0 5-3 8-7 9-4-1-7-4-7-9V7l7-4z"></path><path d="M9 12l2 2 4-4"></path>',
            'lock' => '<rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path>',
            'settings' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.8 1.8 0 0 0 .36 2l.05.05-2.12 2.12-.05-.05a1.8 1.8 0 0 0-2-.36 1.8 1.8 0 0 0-1.1 1.66V20h-3v-.08A1.8 1.8 0 0 0 10.4 18.3a1.8 1.8 0 0 0-2 .36l-.05.05-2.12-2.12.05-.05a1.8 1.8 0 0 0 .36-2A1.8 1.8 0 0 0 5 13.5H4v-3h1a1.8 1.8 0 0 0 1.66-1.1 1.8 1.8 0 0 0-.36-2l-.05-.05 2.12-2.12.05.05a1.8 1.8 0 0 0 2 .36A1.8 1.8 0 0 0 11.5 4h3a1.8 1.8 0 0 0 1.1 1.66 1.8 1.8 0 0 0 2-.36l.05-.05 2.12 2.12-.05.05a1.8 1.8 0 0 0-.36 2A1.8 1.8 0 0 0 21 10.5h1v3h-1A1.8 1.8 0 0 0 19.4 15z"></path>',
            'backup' => '<path d="M4 7h16v12H4z"></path><path d="M8 7V5h8v2"></path><path d="M12 11v5"></path><path d="M9.5 13.5 12 16l2.5-2.5"></path>',
            'scan' => '<path d="M7 4H5a1 1 0 0 0-1 1v2"></path><path d="M17 4h2a1 1 0 0 1 1 1v2"></path><path d="M20 17v2a1 1 0 0 1-1 1h-2"></path><path d="M4 17v2a1 1 0 0 0 1 1h2"></path><path d="M7 12h10"></path><path d="M12 7v10"></path>',
            'stock-in' => '<path d="M12 5v14"></path><path d="M7 10l5-5 5 5"></path><path d="M5 19h14"></path>',
            'stock-out' => '<path d="M12 19V5"></path><path d="M7 14l5 5 5-5"></path><path d="M5 5h14"></path>',
            'profile' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8"></path><path d="M5 20a7 7 0 0 1 14 0"></path>',
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

if (!function_exists('shared_sidebar_has_active_children')) {
    function shared_sidebar_has_active_children(array $item, string $path, string $current): bool
    {
        if (!isset($item['children']) || !is_array($item['children']) || $item['children'] === []) {
            return false;
        }

        foreach ($item['children'] as $childItem) {
            if (is_array($childItem) && shared_sidebar_is_active($childItem, $path, $current)) {
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
            <?php
                $hasChildren = isset($item['children']) && is_array($item['children']) && $item['children'] !== [];
                $hasActiveChild = $hasChildren && shared_sidebar_has_active_children($item, $sharedSidebarPath, $sharedSidebarCurrent);
                $isActive = $hasChildren
                    ? $hasActiveChild
                    : shared_sidebar_is_active($item, $sharedSidebarPath, $sharedSidebarCurrent);
            ?>
            <?php if ($hasChildren): ?>
                <li class="nav-menu-group<?php echo $hasActiveChild ? ' is-open' : ''; ?>">
                    <button
                        class="menu-link menu-link--button menu-link--group-toggle"
                        type="button"
                        data-sidebar-group-toggle
                        aria-expanded="<?php echo $hasActiveChild ? 'true' : 'false'; ?>"
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
                        <span class="menu-group-arrow" aria-hidden="true">
                            <svg class="menu-group-arrow-svg" viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                <path d="M6 8l4 4 4-4"></path>
                            </svg>
                        </span>
                    </button>
                    <ul class="menu-submenu" data-sidebar-group-panel<?php echo $hasActiveChild ? '' : ' hidden'; ?>>
                        <?php foreach ($item['children'] as $childItem): ?>
                            <?php if (!is_array($childItem)): ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php $isChildActive = shared_sidebar_is_active($childItem, $sharedSidebarPath, $sharedSidebarCurrent); ?>
                            <li>
                                <a
                                    href="<?php echo htmlspecialchars((string)$childItem['href'], ENT_QUOTES, 'UTF-8'); ?>"
                                    class="menu-link menu-submenu-link<?php echo $isChildActive ? ' active-link' : ''; ?>"
                                    <?php echo $isChildActive ? 'aria-current="page" data-active="true"' : ''; ?>
                                    <?php echo isset($childItem['data_section_link']) ? 'data-section-link="' . htmlspecialchars((string)$childItem['data_section_link'], ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
                                >
                                    <span class="menu-visual" aria-hidden="true">
                                        <span class="menu-icon">
                                            <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                                <?php echo shared_sidebar_icon((string)$childItem['icon']); ?>
                                            </svg>
                                        </span>
                                        <span class="menu-mini-label"><?php echo htmlspecialchars((string)$childItem['mini'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </span>
                                    <span class="menu-text"><?php echo htmlspecialchars((string)$childItem['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php else: ?>
                <li>
                    <?php if (($item['type'] ?? 'link') === 'button'): ?>
                        <button
                            id="<?php echo htmlspecialchars((string)($item['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            class="menu-link menu-link--button<?php echo $isActive ? ' active-link' : ''; ?>"
                            type="button"
                            <?php echo $isActive ? 'data-active="true"' : ''; ?>
                        >
                    <?php else: ?>
                        <a
                            href="<?php echo htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="menu-link<?php echo $isActive ? ' active-link' : ''; ?>"
                            <?php echo $isActive ? 'aria-current="page" data-active="true"' : ''; ?>
                            <?php echo isset($item['data_section_link']) ? 'data-section-link="' . htmlspecialchars((string)$item['data_section_link'], ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
                        >
                    <?php endif; ?>
                        <span class="menu-visual" aria-hidden="true">
                            <span class="menu-icon">
                                <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                    <?php echo shared_sidebar_icon((string)$item['icon']); ?>
                                </svg>
                            </span>
                            <span class="menu-mini-label"><?php echo htmlspecialchars((string)$item['mini'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                        <span class="menu-text"><?php echo htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (($item['type'] ?? 'link') === 'button'): ?>
                        </button>
                    <?php else: ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
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
