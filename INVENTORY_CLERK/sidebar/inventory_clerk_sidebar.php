<?php
// Sidebar ng Inventory Clerk. Inventory lang muna ang access niya.
$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$isInventory = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/inventory.php');
?>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-toggle-row">
        <div class="sidebar-toggle-title">
            <span class="sidebar-toggle-title__shine">Inventory Clerk</span>
        </div>
    </div>
    <div class="nav-divider"></div>
    <ul class="nav-menu">
        <li>
            <a href="/codesamplecaps/INVENTORY_CLERK/sidebar/inventory.php" class="menu-link<?php echo $isInventory ? ' active' : ''; ?>">
                <span class="menu-text">Inventory</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/LOGIN/php/logout.php" class="menu-link logout">
                <span class="menu-text">Logout</span>
            </a>
        </li>
    </ul>
</nav>
