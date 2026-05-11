<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_access.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../includes/foreman_helpers.php';

require_role('foreman');

$userId = (int)($_SESSION['user_id'] ?? 0);
$foremanProfileName = (string)($_SESSION['name'] ?? 'Foreman');
$foremanProfile = foreman_fetch_profile($conn, $userId);
$foremanProfileName = (string)($foremanProfile['full_name'] ?? $foremanProfileName);
$dashboardData = foreman_fetch_dashboard_data($conn, $userId);
$assetSummary = $dashboardData['asset_summary'];
$usageSummary = $dashboardData['usage_summary'];
$scanSummary = $dashboardData['scan_summary'];
$supportSummary = $dashboardData['support_summary'];
$assignedProjects = $dashboardData['assigned_projects'];
$foremanNotifications = [
    'attention_count' => (int)($assetSummary['maintenance_assets'] ?? 0) + (int)($assetSummary['damaged_assets'] ?? 0),
    'logs_today' => (int)($usageSummary['logs_today'] ?? 0),
    'scans_today' => (int)($scanSummary['scans_today'] ?? 0),
];
$projectRoleSummary = project_role_summary_label('foreman');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreman Projects - Edge Automation</title>
    <link rel="stylesheet" href="../css/sidebar_foreman.css">
    <link rel="stylesheet" href="../css/foreman_dashboard.css">
    <link rel="stylesheet" href="../css/qr_scanner.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>

<main class="main-content">
    <div class="page-shell">
        <section class="page-hero">
            <div class="page-hero__content">
                <span class="page-hero__eyebrow">My Projects</span>
                <h1 class="page-hero__title">Assigned Field Projects</h1>
                <p class="page-hero__copy">
                    Read-only project visibility for site coordination. Foreman access stays focused on schedule awareness,
                    open work, and field follow-through.
                </p>
                <p class="page-hero__copy page-hero__copy--compact"><?php echo htmlspecialchars($projectRoleSummary); ?></p>
                <div class="hero-actions">
                    <a class="btn-primary" href="/codesamplecaps/FOREMAN/dashboards/usage_logs.php">Open Usage Logs</a>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php">Back To Overview</a>
                </div>
            </div>

            <aside class="page-hero__aside">
                <div class="aside-stat">
                    <span>Assigned Projects</span>
                    <strong><?php echo count($assignedProjects); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Active Projects</span>
                    <strong><?php echo (int)($supportSummary['active_projects'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Open Tasks</span>
                    <strong><?php echo (int)($supportSummary['open_tasks'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Logs Today</span>
                    <strong><?php echo (int)($usageSummary['logs_today'] ?? 0); ?></strong>
                </div>
            </aside>
        </section>

        <section class="panel-card">
            <div class="section-heading">
                <div>
                    <span class="section-badge">Visibility Only</span>
                    <h2>Project Status And Deadline Watch</h2>
                    <p>Critical project edits stay with super admin, while engineer handles execution updates.</p>
                </div>
            </div>

            <?php if (!empty($assignedProjects)): ?>
                <div class="project-list">
                    <?php foreach ($assignedProjects as $project): ?>
                        <?php
                        $projectStatus = (string)($project['status'] ?? 'pending');
                        $projectProgress = build_role_project_progress($project, 'foreman');
                        $deadlineMeta = foreman_build_deadline_meta($project['next_deadline'] ?? null, $projectStatus);
                        ?>
                        <article class="project-card">
                            <div class="project-card__header">
                                <div>
                                    <span class="section-badge">Project #<?php echo (int)($project['id'] ?? 0); ?></span>
                                    <h3><?php echo htmlspecialchars((string)($project['project_name'] ?? 'Untitled Project')); ?></h3>
                                </div>
                                <span class="status-badge status-badge--<?php echo htmlspecialchars($projectStatus); ?>">
                                    <?php echo htmlspecialchars(foreman_status_label($projectStatus)); ?>
                                </span>
                            </div>

                            <p><?php echo htmlspecialchars(foreman_excerpt($project['description'] ?? '', 220)); ?></p>

                            <div class="project-meta">
                                <div>
                                    <span>Client</span>
                                    <strong><?php echo htmlspecialchars((string)($project['client_name'] ?? 'N/A')); ?></strong>
                                </div>
                                <div>
                                    <span>Project Owner</span>
                                    <strong><?php echo htmlspecialchars((string)($project['project_owner_name'] ?? 'N/A')); ?></strong>
                                </div>
                                <div>
                                    <span>P.O Date</span>
                                    <strong><?php echo htmlspecialchars(foreman_format_date($project['start_date'] ?? null)); ?></strong>
                                </div>
                                <div>
                                    <span>Completed</span>
                                    <strong><?php echo htmlspecialchars(foreman_format_date($project['end_date'] ?? null)); ?></strong>
                                </div>
                            </div>

                            <div class="project-meta">
                                <div>
                                    <span><?php echo htmlspecialchars((string)$projectProgress['label']); ?></span>
                                    <strong><?php echo htmlspecialchars((string)$projectProgress['summary']); ?></strong>
                                </div>
                                <div>
                                    <span>Open Work</span>
                                    <strong><?php echo (int)($project['open_tasks'] ?? 0); ?> task(s)</strong>
                                </div>
                                <div>
                                    <span>Completion</span>
                                    <strong><?php echo (int)$projectProgress['percent']; ?>%</strong>
                                </div>
                                <div>
                                    <span>Deadline</span>
                                    <strong><span class="status-badge <?php echo htmlspecialchars($deadlineMeta['class']); ?>"><?php echo htmlspecialchars($deadlineMeta['label']); ?></span></strong>
                                </div>
                            </div>
                            <p class="page-hero__copy page-hero__copy--compact"><?php echo htmlspecialchars((string)$projectProgress['hint']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No assigned projects yet.</div>
            <?php endif; ?>
        </section>
    </div>
</main>

<div class="qr-modal" id="qrScannerModal" aria-hidden="true">
    <div class="qr-modal-content">
        <div class="qr-modal-header">
            <h2>QR Asset Scanner</h2>
            <button id="qrScannerClose" class="qr-close" type="button" aria-label="Close">X</button>
        </div>
        <div class="qr-modal-body">
            <div class="qr-status" id="qrStatus">Ready to scan.</div>
            <div class="qr-scanner-area" id="qr-reader"></div>
            <div class="qr-error" id="qrScannerError"></div>
            <div class="qr-asset-info" id="qrAssetInfo"></div>
            <div class="qr-input-row">
                <input id="qrWorkerName" placeholder="Worker / personnel name" aria-label="Worker name">
                <textarea id="qrNotes" rows="2" placeholder="Optional notes" aria-label="Notes"></textarea>
            </div>
            <div class="qr-actions">
                <button class="btn-primary" id="qrLogUsage" type="button">Log Usage</button>
                <button class="btn-secondary" id="qrScannerCloseSecondary" type="button">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="../js/sidebar_foreman.js"></script>
<script src="../js/html5-qrcode.min.js"></script>
<script src="../js/qr_scanner_foreman.js"></script>
</body>
</html>
