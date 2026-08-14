<?php
// Kapag binuksan ito direkta sa sidebar, gamitin pa rin ang main dashboard shell.
if (!defined('ADMIN_RENDER_OVERVIEW_PARTIAL')) {
    header('Location: /codesamplecaps/ADMIN/dashboards/admin_dashboard.php');
    exit;
}

/** @var string $activeTab */
/** @var int $totalUsers */
/** @var int $totalProjects */
/** @var int $pendingProjects */
/** @var int $ongoingProjects */
/** @var int $openTasks */
/** @var int $pendingQuotations */
/** @var int $pendingInquiries */
/** @var int $delayedTasks */
/** @var int $onHoldProjects */
/** @var int $inventoryAlertCount */
/** @var string $csrfToken */
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
$pendingInquiries = $pendingInquiries ?? 0;
$csrfToken = $csrfToken ?? '';
?>
<div id="dashboard" class="tab-content <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
    <section class="dashboard-grid overview-dashboard" data-superadmin-overview>
        <section class="dashboard-panel summary-panel">
            <div class="panel-heading">
                <div>
                    <h1 class="dashboard-section-title">Overview</h1>
                </div>
            </div>
            <div class="metric-strip metric-strip-compact overview-summary-grid">
                <a href="/codesamplecaps/ADMIN/sidebar/projects/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-projects">
                    <span>Active Projects</span>
                    <strong data-live-metric="active_projects"><?php echo $activeProjectCount; ?></strong>
                    <small><?php echo $ongoingProjects; ?> ongoing, <?php echo $pendingProjects; ?> pending</small>
                </a>
                <a href="/codesamplecaps/ADMIN/sidebar/projects/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-tasks">
                    <span>Open Tasks</span>
                    <strong data-live-metric="open_tasks"><?php echo $openTasks; ?></strong>
                    <small><?php echo $delayedTasks; ?> delayed</small>
                </a>
                <a href="/codesamplecaps/ADMIN/sidebar/quotations.php" class="metric-tile metric-tile-link metric-tile-quotations">
                    <span>Pending Quotations</span>
                    <strong data-live-metric="pending_quotations"><?php echo $pendingQuotations; ?></strong>
                    <small>Need approval</small>
                </a>
                <a href="/codesamplecaps/ADMIN/dashboards/admin_dashboard.php" class="metric-tile metric-tile-link metric-tile-alerts">
                    <span>New Inquiries</span>
                    <strong data-live-metric="pending_inquiries"><?php echo $pendingInquiries ?? 0; ?></strong>
                    <small>Pending review</small>
                </a>
                <a href="/codesamplecaps/ADMIN/sidebar/scan_history.php" class="metric-tile metric-tile-link metric-tile-assets">
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
                <a href="/codesamplecaps/ADMIN/sidebar/projects/projects.php?status=active" class="overview-attention-card overview-attention-card--danger<?php echo $delayedTasks > 0 ? ' is-active' : ' is-clear'; ?>">
                    <span>Delayed Tasks</span>
                    <strong data-live-metric="delayed_tasks"><?php echo $delayedTasks; ?></strong>
                    <small><?php echo $totalTasks; ?> total tasks</small>
                </a>
                <a href="/codesamplecaps/ADMIN/sidebar/inventory.php" class="overview-attention-card overview-attention-card--warning<?php echo $inventoryAlertCount > 0 ? ' is-active' : ' is-clear'; ?>">
                    <span>Inventory Alerts</span>
                    <strong data-live-metric="inventory_alerts"><?php echo $inventoryAlertCount; ?></strong>
                    <small><?php echo $lowStockItems; ?> low, <?php echo $outOfStockItems; ?> out</small>
                </a>
                <a href="/codesamplecaps/ADMIN/sidebar/projects/projects.php?status=on-hold" class="overview-attention-card overview-attention-card--neutral<?php echo $onHoldProjects > 0 ? ' is-active' : ' is-clear'; ?>">
                    <span>On-Hold Projects</span>
                    <strong data-live-metric="on_hold_projects"><?php echo $onHoldProjects; ?></strong>
                    <small>Needs follow-up</small>
                </a>
                <a href="/codesamplecaps/ADMIN/sidebar/quotations.php" class="overview-attention-card overview-attention-card--neutral<?php echo $pendingQuotations > 0 ? ' is-active' : ' is-clear'; ?>">
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
                <a href="/codesamplecaps/ADMIN/sidebar/activity_history.php" class="action-chip">View all</a>
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

        <section class="dashboard-panel overview-inquiries-panel">
            <div class="panel-heading">
                <div>
                    <h2 class="dashboard-section-title">Latest Inquiries</h2>
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                    <span class="action-chip" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;">Pending review</span>
                    <?php if (($pendingInquiries ?? 0) > 0): ?>
                        <span style="display:inline-flex; align-items:center; gap:0.35rem; padding:0.4rem 0.7rem; border-radius:999px; background:#dc2626; color:#fff; font-weight:700; font-size:0.78rem;">
                            <span style="width:7px; height:7px; border-radius:999px; background:#fff; display:inline-block;"></span>
                            <?php echo (int)$pendingInquiries; ?> new
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="activity-feed activity-feed-compact">
                <?php if (empty($inquiryRows)): ?>
                    <div class="alert-empty">No inquiries yet.</div>
                <?php else: ?>
                    <?php foreach ($inquiryRows as $inquiry): ?>
                        <article class="activity-item">
                            <span class="activity-badge activity-quotations">
                                <?php echo htmlspecialchars(substr((string)($inquiry['status'] ?? 'Pending Review'), 0, 1), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="activity-copy">
                                <strong><?php echo htmlspecialchars((string)($inquiry['client_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?php echo htmlspecialchars((string)($inquiry['service_category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars((string)($inquiry['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                            <div class="activity-item-actions">
                                <?php if ((string)($inquiry['status'] ?? 'Pending Review') === 'Pending Review'): ?>
                                    <form method="POST" class="inline-action-form" style="display:inline-flex; gap:0.5rem; margin-top:0.75rem;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="update_inquiry_status">
                                        <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                        <input type="hidden" name="status" value="Verified Lead">
                                        <button type="submit" class="btn-secondary">Verify Lead</button>
                                    </form>
                                    <form method="POST" class="inline-action-form" style="display:inline-flex; gap:0.5rem; margin-top:0.75rem;" onsubmit="return confirm('Reject this inquiry?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="update_inquiry_status">
                                        <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                        <input type="hidden" name="status" value="Not Qualified">
                                        <button type="submit" class="btn-danger">Not Qualified</button>
                                    </form>
                                <?php else: ?>
                                    <span class="activity-status-pill"><?php echo htmlspecialchars((string)($inquiry['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <details style="margin-top:0.75rem; color:#4b5563;">
                                <summary style="cursor:pointer; font-weight:600; color:#2563eb;">View details</summary>
                                <div style="margin-top:0.6rem; padding:0.75rem; border-left:3px solid #2563eb; background:#f8fafc; border-radius:0.5rem;">
                                    <div style="margin-bottom:0.35rem;"><strong>Company:</strong> <?php echo htmlspecialchars((string)($inquiry['company_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="margin-bottom:0.35rem;"><strong>Contact:</strong> <?php echo htmlspecialchars((string)($inquiry['contact_no'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="margin-bottom:0.35rem;"><strong>Site Address:</strong> <?php echo htmlspecialchars((string)($inquiry['site_address'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="margin-bottom:0.35rem;"><strong>Preferred Inspection Date:</strong> <?php echo htmlspecialchars((string)($inquiry['preferred_inspection_date'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div><strong>Message:</strong><br><?php echo nl2br(htmlspecialchars((string)($inquiry['description'] ?? '—'), ENT_QUOTES, 'UTF-8')); ?></div>
                                </div>
                            </details>
                            <time datetime="<?php echo htmlspecialchars((string)($inquiry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="activity-time-relative"><?php echo htmlspecialchars((string)($inquiry['status'] ?? 'Pending Review'), ENT_QUOTES, 'UTF-8'); ?></span>
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
            <a href="/codesamplecaps/ADMIN/sidebar/projects/projects.php#create-project">Create Project</a>
            <a href="/codesamplecaps/ADMIN/sidebar/user_management.php?create=1">Add User</a>
            <a href="/codesamplecaps/ADMIN/sidebar/assets.php">Add Asset</a>
            <a href="/codesamplecaps/ADMIN/sidebar/quotations.php">Review Quotations</a>
        </section>
    </section>
</div>
