<?php
/** @var string $activeTab */
/** @var int $totalUsers */
/** @var int $totalProjects */
/** @var int $pendingProjects */
/** @var int $ongoingProjects */
/** @var int $openTasks */
/** @var int $pendingQuotations */
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
/** @var int $scansToday */
/** @var array<int, array<string, mixed>> $recentDashboardActivity */
$activeProjectCount = $ongoingProjects + $pendingProjects + $onHoldProjects;
$activeWorkforceCount = $activeEngineerCount + $activeForemanCount + $activeClientCount;
?>
<link rel="stylesheet" href="/codesamplecaps/assets/css/responsive-foundation.css">
<script src="/codesamplecaps/assets/js/realtime-updates.js" defer></script>
<div id="dashboard" class="tab-content <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
    <section class="dashboard-grid overview-dashboard" data-superadmin-overview>
        <section class="dashboard-panel summary-panel">
            <div class="panel-heading">
                <div>
                    <h1 class="dashboard-section-title">Overview</h1>
                </div>
            </div>
            <div class="metric-strip metric-strip-compact overview-summary-grid">
                <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-projects">
                    <span>Active Projects</span>
                    <strong data-live-metric="active_projects"><?php echo $activeProjectCount; ?></strong>
                    <small><?php echo $ongoingProjects; ?> ongoing, <?php echo $pendingProjects; ?> pending</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-tasks">
                    <span>Open Tasks</span>
                    <strong data-live-metric="open_tasks"><?php echo $openTasks; ?></strong>
                    <small><?php echo $delayedTasks; ?> delayed</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/quotations.php" class="metric-tile metric-tile-link metric-tile-quotations">
                    <span>Pending Quotations</span>
                    <strong data-live-metric="pending_quotations"><?php echo $pendingQuotations; ?></strong>
                    <small>Need approval</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/scan_history.php" class="metric-tile metric-tile-link metric-tile-assets">
                    <span>Scans Today</span>
                    <strong data-live-metric="scans_today"><?php echo $scansToday; ?></strong>
                    <small>Asset activity</small>
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
                <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=active" class="overview-attention-card overview-attention-card--danger<?php echo $delayedTasks > 0 ? ' is-active' : ' is-clear'; ?>">
                    <span>Delayed Tasks</span>
                    <strong data-live-metric="delayed_tasks"><?php echo $delayedTasks; ?></strong>
                    <small><?php echo $totalTasks; ?> total tasks</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php" class="overview-attention-card overview-attention-card--warning<?php echo $inventoryAlertCount > 0 ? ' is-active' : ' is-clear'; ?>">
                    <span>Inventory Alerts</span>
                    <strong data-live-metric="inventory_alerts"><?php echo $inventoryAlertCount; ?></strong>
                    <small><?php echo $lowStockItems; ?> low, <?php echo $outOfStockItems; ?> out</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=on-hold" class="overview-attention-card overview-attention-card--neutral<?php echo $onHoldProjects > 0 ? ' is-active' : ' is-clear'; ?>">
                    <span>On-Hold Projects</span>
                    <strong data-live-metric="on_hold_projects"><?php echo $onHoldProjects; ?></strong>
                    <small>Needs follow-up</small>
                </a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/quotations.php" class="overview-attention-card overview-attention-card--neutral<?php echo $pendingQuotations > 0 ? ' is-active' : ' is-clear'; ?>">
                    <span>Pending Approvals</span>
                    <strong data-live-metric="pending_quotations"><?php echo $pendingQuotations; ?></strong>
                    <small>Quotation review queue</small>
                </a>
            </div>
        </section>

        <section class="dashboard-panel activity-panel overview-activity-panel">
            <div class="panel-heading">
                <div>
                    <h2 class="dashboard-section-title">Recent Activity</h2>
                </div>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/activity_history.php" class="action-chip">View all</a>
            </div>
            <div class="activity-feed activity-feed-compact" data-live-activity-feed>
                <?php if (empty($recentDashboardActivity)): ?>
                    <div class="alert-empty">No recent activity yet.</div>
                <?php else: ?>
                    <?php foreach ($recentDashboardActivity as $activity): ?>
                        <?php $badge = preg_replace('/[^a-z0-9_-]/i', '', strtolower((string)($activity['badge'] ?? 'audit'))) ?: 'audit'; ?>
                        <article class="activity-item">
                            <span class="activity-badge activity-<?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(strtoupper(substr((string)($activity['badge'] ?? 'A'), 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="activity-copy">
                                <strong><?php echo htmlspecialchars((string)($activity['title'] ?? 'Activity'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?php echo htmlspecialchars((string)($activity['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                            <time datetime="<?php echo htmlspecialchars((string)($activity['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="activity-time-relative"><?php echo htmlspecialchars((string)($activity['relative_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                            </time>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <details class="dashboard-panel analytics-panel overview-analytics-details" data-overview-analytics>
            <summary class="overview-analytics-summary">
                <span>
                    <strong>Operations Analytics</strong>
                    <small>Progress, task health, asset activity, workforce, and intake</small>
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
                    <span>Task Health</span>
                    <strong><?php echo $taskDelayRate; ?>%</strong>
                    <small><?php echo $delayedTasks; ?> delayed out of <?php echo $totalTasks; ?> total tasks</small>
                </article>
                <article class="mini-overview-card">
                    <span>Asset Activity</span>
                    <strong data-live-metric="scans_today"><?php echo $scansToday; ?></strong>
                    <small>QR scans captured today</small>
                </article>
                <article class="mini-overview-card">
                    <span>Active Workforce</span>
                    <strong><?php echo $activeWorkforceCount; ?></strong>
                    <small><?php echo $activeEngineerCount; ?> engineers, <?php echo $activeForemanCount; ?> foremen, <?php echo $activeClientCount; ?> clients</small>
                </article>
                <article class="mini-overview-card">
                    <span>7-Day Intake</span>
                    <strong><?php echo $projectsCreatedThisWeek; ?>/<?php echo $tasksCreatedThisWeek; ?></strong>
                    <small><?php echo $projectsCreatedThisWeek; ?> projects and <?php echo $tasksCreatedThisWeek; ?> tasks created this week</small>
                </article>
            </div>
        </details>

        <section class="overview-quick-actions" aria-label="Quick actions">
            <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php#create-project">Create Project</a>
            <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=create">Add User</a>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/assets.php">Add Asset</a>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/quotations.php">Review Quotations</a>
        </section>
    </section>
</div>
