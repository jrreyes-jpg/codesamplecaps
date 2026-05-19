<?php
/** @var string $activeTab */
/** @var int $totalUsers */
/** @var int $totalProjects */
/** @var int $ongoingProjects */
/** @var int $openTasks */
/** @var int $scansToday */
/** @var int $delayedTasks */
/** @var int $onHoldProjects */
/** @var int $inventoryAlertCount */
/** @var int $lowStockItems */
/** @var int $outOfStockItems */
/** @var int $projectCompletionRate */
/** @var int $completedProjects */
/** @var int $taskDelayRate */
/** @var int $totalTasks */
/** @var int $inventoryAlertRate */
/** @var int $inventoryItems */
/** @var int $activeEngineerCount */
/** @var int $activeForemanCount */
/** @var int $activeClientCount */
/** @var int $projectsCreatedThisWeek */
/** @var int $tasksCreatedThisWeek */
/** @var int $scansThisWeek */
/** @var int $scanTrendPeak */
?>
<div id="dashboard" class="tab-content <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
    <section class="dashboard-grid overview-dashboard">
        <section class="dashboard-panel summary-panel">
            <div class="panel-heading">
                <div>
                    <h1 class="dashboard-section-title">Overview</h1>
                </div>
            </div>
            <div class="metric-strip metric-strip-compact overview-summary-grid">
                <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=users" class="metric-tile metric-tile-link metric-tile-people">
                    <span>People</span>
                    <strong><?php echo $totalUsers; ?></strong>
                    <small>Manage users</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-projects">
                    <span>Projects</span>
                    <strong><?php echo $totalProjects; ?></strong>
                    <small><?php echo $ongoingProjects; ?> active</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-tasks">
                    <span>Tasks</span>
                    <strong><?php echo $openTasks; ?></strong>
                    <small>Open work</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/scan_history.php" class="metric-tile metric-tile-link metric-tile-scans">
                    <span>Scans</span>
                    <strong><?php echo $scansToday; ?></strong>
                    <small>Today</small>
                </a>
            </div>
        </section>

        <section class="dashboard-panel overview-attention-panel">
            <div class="panel-heading">
                <div>
                    <h2 class="dashboard-section-title">Needs Attention</h2>
                </div>
            </div>
            <div class="overview-attention-grid">
                <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=active" class="overview-attention-card<?php echo $delayedTasks > 0 ? ' is-warning' : ' is-clear'; ?>">
                    <span>Delayed Tasks</span>
                    <strong><?php echo $delayedTasks; ?></strong>
                    <small><?php echo $totalTasks; ?> total tasks</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php" class="overview-attention-card<?php echo $inventoryAlertCount > 0 ? ' is-warning' : ' is-clear'; ?>">
                    <span>Inventory Alerts</span>
                    <strong><?php echo $inventoryAlertCount; ?></strong>
                    <small><?php echo $lowStockItems; ?> low, <?php echo $outOfStockItems; ?> out</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=on-hold" class="overview-attention-card<?php echo $onHoldProjects > 0 ? ' is-warning' : ' is-clear'; ?>">
                    <span>On-Hold Projects</span>
                    <strong><?php echo $onHoldProjects; ?></strong>
                    <small>Needs follow-up</small>
                </a>
            </div>
        </section>

        <section class="overview-quick-actions" aria-label="Quick actions">
            <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=users">Users</a>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php">Projects</a>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/scan_history.php">Scans</a>
        </section>

        <details class="dashboard-panel analytics-panel overview-analytics-details" data-overview-analytics>
            <summary class="overview-analytics-summary">
                <span>
                    <strong>Operations Analytics</strong>
                    <small>Completion, delays, inventory, workforce, intake, and scan trend</small>
                </span>
                <span class="overview-analytics-summary__chevron" aria-hidden="true"></span>
            </summary>
            <div class="panel-heading">
                <div>
                    <h2 class="dashboard-section-title">Operations Analytics</h2>
                </div>
            </div>
            <div class="mini-overview">
                <article class="mini-overview-card">
                    <span>Project Completion</span>
                    <strong><?php echo $projectCompletionRate; ?>%</strong>
                    <small><?php echo $completedProjects; ?> of <?php echo $totalProjects; ?> projects completed</small>
                </article>
                <article class="mini-overview-card">
                    <span>Task Delay Pressure</span>
                    <strong><?php echo $taskDelayRate; ?>%</strong>
                    <small><?php echo $delayedTasks; ?> delayed out of <?php echo $totalTasks; ?> total tasks</small>
                </article>
                <article class="mini-overview-card">
                    <span>Inventory Alerts</span>
                    <strong><?php echo $inventoryAlertCount; ?></strong>
                    <small><?php echo $inventoryAlertRate; ?>% of inventory items need attention</small>
                </article>
                <article class="mini-overview-card">
                    <span>Active Workforce</span>
                    <strong><?php echo $activeEngineerCount + $activeForemanCount + $activeClientCount; ?></strong>
                    <small><?php echo $activeEngineerCount; ?> engineers, <?php echo $activeForemanCount; ?> foremen, <?php echo $activeClientCount; ?> clients</small>
                </article>
                <article class="mini-overview-card">
                    <span>7-Day Intake</span>
                    <strong><?php echo $projectsCreatedThisWeek; ?>/<?php echo $tasksCreatedThisWeek; ?></strong>
                    <small><?php echo $projectsCreatedThisWeek; ?> projects and <?php echo $tasksCreatedThisWeek; ?> tasks created this week</small>
                </article>
                <article class="mini-overview-card">
                    <span>Scan Activity</span>
                    <strong><?php echo $scansThisWeek; ?></strong>
                    <small>Last 7 days, peak daily scans: <?php echo $scanTrendPeak; ?></small>
                </article>
            </div>
        </details>
    </section>
</div>
