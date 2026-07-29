<?php
// Sidebar ng Inventory Clerk. Inventory lang muna ang access niya.
$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$isDashboard = str_contains($currentPath, '/INVENTORY_CLERK/dashboards/inventory_clerk_dashboard.php');
$isInventory = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/inventory.php');
$isStockIn = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/stock_in.php');
$isStockOut = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/stock_out.php');
$isStockHistory = str_contains($currentPath, '/INVENTORY_CLERK/sidebar/stock_history.php');
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
            <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory_clerk_dashboard.php" class="menu-link<?php echo $isDashboard ? ' active' : ''; ?>">
                <span class="menu-text">Dashboard</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/INVENTORY_CLERK/sidebar/inventory.php" class="menu-link<?php echo $isInventory ? ' active' : ''; ?>">
                <span class="menu-text">Inventory</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/INVENTORY_CLERK/sidebar/stock_in.php" class="menu-link<?php echo $isStockIn ? ' active' : ''; ?>">
                <span class="menu-text">Stock In</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/INVENTORY_CLERK/sidebar/stock_out.php" class="menu-link<?php echo $isStockOut ? ' active' : ''; ?>">
                <span class="menu-text">Stock Out</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/INVENTORY_CLERK/sidebar/stock_history.php" class="menu-link<?php echo $isStockHistory ? ' active' : ''; ?>">
                <span class="menu-text">Stock History</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/LOGIN/php/logout.php" class="menu-link logout">
                <span class="menu-text">Logout</span>
            </a>
        </li>
    </ul>
</nav>
