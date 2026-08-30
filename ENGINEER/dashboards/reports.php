<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/quotation_module.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

require_role('engineer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$taskStatusOptions = ['pending', 'ongoing', 'completed', 'delayed'];
$data = engineer_fetch_data($conn, $userId, $taskStatusOptions);

function engineer_reports_table_exists(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

$assignedProjects = $data['assigned_projects'];
$recentUpdates = $data['recent_updates'];
$quotationRows = quotation_module_tables_ready($conn) ? quotation_module_fetch_quotations($conn, 'engineer', $userId) : [];
$technicalIssues = 0;
$materialRequests = 0;
$pendingQuotationApprovals = 0;

foreach ($data['tasks'] as $taskRow) {
    if ((string)($taskRow['status'] ?? '') === 'delayed') {
        $technicalIssues++;
    }
}

if (engineer_reports_table_exists($conn, 'purchase_requests')) {
    $statement = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM purchase_requests pr
         INNER JOIN project_assignments pa ON pa.project_id = pr.project_id
         WHERE pa.engineer_id = ?
         AND pr.status IN ('submitted', 'under_review', 'approved')"
    );
    if ($statement) {
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();
        $materialRequests = (int)(($result ? $result->fetch_assoc() : [])['total'] ?? 0);
        $statement->close();
    }
}

foreach ($quotationRows as $quotationRow) {
    if (in_array((string)($quotationRow['status'] ?? ''), ['draft', 'sent', 'foreman_review'], true)) {
        $pendingQuotationApprovals++;
    }
}
$engineerPageTitle = 'Engineer Reports - Edge Automation';
$engineerCssFiles = ['/codesamplecaps/ENGINEER/css/reports.css'];
require __DIR__ . '/../layout/header.php';
?>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>

<div class="main-content">
    <?php
    include __DIR__ . '/../includes/header.php';
    ?>

    <section class="reports-hero">
        <div>
            <p class="report-kicker">Engineer Reports</p>
            <h1>Technical reporting for assigned projects only.</h1>
            <p>This hub keeps Engineer reporting narrow on purpose: progress, technical exceptions, procurement support, quotations, and accomplishment across your assigned work.</p>
        </div>
        <div class="report-card-grid">
            <div class="report-stat-card">
                <span>Assigned projects</span>
                <strong><?php echo (int)$data['assigned_count']; ?></strong>
            </div>
            <div class="report-stat-card">
                <span>Open tasks</span>
                <strong><?php echo (int)$data['open_task_count']; ?></strong>
            </div>
            <div class="report-stat-card">
                <span>Technical issues</span>
                <strong><?php echo $technicalIssues; ?></strong>
            </div>
            <div class="report-stat-card">
                <span>Progress updates</span>
                <strong><?php echo count($recentUpdates); ?></strong>
            </div>
        </div>
    </section>

    <section class="report-surface">
        <h2>Reports you should own</h2>
        <p>These match the role boundaries we defined for Engineer access.</p>
        <div class="report-links-grid">
            <a class="report-link-card" href="/codesamplecaps/ENGINEER/dashboards/progress_updates.php">
                <h3>Project progress reports</h3>
                <p>Track status notes, update history, and execution movement across assigned tasks.</p>
                <div class="report-link-card__meta">
                    <span><?php echo count($recentUpdates); ?> recent updates</span>
                    <span>Open</span>
                </div>
            </a>
            <a class="report-link-card" href="/codesamplecaps/ENGINEER/dashboards/procurement.php">
                <h3>Material usage and procurement follow-up</h3>
                <p>Use procurement review as the engineer-side material reporting surface for site support.</p>
                <div class="report-link-card__meta">
                    <span><?php echo $materialRequests; ?> tracked requests</span>
                    <span>Open</span>
                </div>
            </a>
            <a class="report-link-card" href="/codesamplecaps/ENGINEER/dashboards/tasks.php?quick=delayed">
                <h3>Technical issue and inspection reports</h3>
                <p>Delayed tasks act as the current technical exception queue that needs engineering action.</p>
                <div class="report-link-card__meta">
                    <span><?php echo $technicalIssues; ?> flagged items</span>
                    <span>Open</span>
                </div>
            </a>
            <a class="report-link-card" href="/codesamplecaps/ENGINEER/dashboards/quotations.php">
                <h3>Assigned quotation status reports</h3>
                <p>Review quotation pipeline, drafts, and responses tied to your projects.</p>
                <div class="report-link-card__meta">
                    <span><?php echo $pendingQuotationApprovals; ?> active quotations</span>
                    <span>Open</span>
                </div>
            </a>
        </div>
    </section>

    <section class="report-surface">
        <h2>Assigned project accomplishment snapshot</h2>
        <p>This keeps the Engineer report view anchored to current delivery instead of global system data.</p>
        <div class="report-project-list">
            <?php if (!empty($assignedProjects)): ?>
                <?php foreach ($assignedProjects as $project): ?>
                    <?php $projectProgress = build_role_project_progress($project, 'engineer'); ?>
                    <article class="report-project-card">
                        <h3><?php echo htmlspecialchars((string)$project['project_name']); ?></h3>
                        <p><?php echo htmlspecialchars((string)($project['description'] ?? 'Assigned project reporting workspace.')); ?></p>
                        <div class="report-project-card__meta">
                            <span>Status: <?php echo htmlspecialchars(ucfirst((string)($project['status'] ?? 'pending'))); ?></span>
                            <span>Start: <?php echo htmlspecialchars(engineer_format_project_date($project['start_date'] ?? null)); ?></span>
                            <span>End: <?php echo htmlspecialchars(engineer_format_project_date($project['end_date'] ?? null)); ?></span>
                            <span><?php echo htmlspecialchars((string)$projectProgress['label']); ?>: <?php echo (int)$projectProgress['percent']; ?>%</span>
                        </div>
                        <p><?php echo htmlspecialchars((string)$projectProgress['summary']); ?></p>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <article class="report-project-card">
                    <h3>No assigned projects yet</h3>
                    <p>Engineer reports activate as soon as projects are assigned.</p>
                </article>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
