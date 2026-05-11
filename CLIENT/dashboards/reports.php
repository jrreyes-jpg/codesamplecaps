<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../../config/quotation_module.php';
require_once __DIR__ . '/../includes/client_shell.php';

require_role('client');

$userId = (int)($_SESSION['user_id'] ?? 0);
$clientName = trim((string)($_SESSION['name'] ?? 'Client User'));
$clientEmail = trim((string)($_SESSION['email'] ?? ''));
$clientEmailDisplay = $clientEmail !== '' ? $clientEmail : 'No email on record';
$shellContext = client_shell_build_topbar_context($conn, $userId, $clientName, $clientEmailDisplay);

function client_reports_format_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Not set';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

$projectStatement = $conn->prepare(
    "SELECT
        p.id,
        p.project_name,
        p.status,
        p.start_date,
        p.end_date,
        COALESCE(task_totals.total_tasks, 0) AS total_tasks,
        COALESCE(task_totals.completed_tasks, 0) AS completed_tasks,
        task_totals.next_deadline
     FROM projects p
     LEFT JOIN (
         SELECT
             project_id,
             COUNT(*) AS total_tasks,
             SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
             MIN(CASE WHEN status <> 'completed' AND deadline IS NOT NULL THEN deadline END) AS next_deadline
         FROM tasks
         GROUP BY project_id
     ) task_totals ON task_totals.project_id = p.id
     WHERE p.client_id = ?
     AND p.status <> 'draft'
     ORDER BY p.created_at DESC, p.id DESC"
);
$projectRows = [];
if ($projectStatement) {
    $projectStatement->bind_param('i', $userId);
    $projectStatement->execute();
    $result = $projectStatement->get_result();
    $projectRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $projectStatement->close();
}

$quotationRows = quotation_module_tables_ready($conn) ? quotation_module_fetch_quotations($conn, 'client', $userId) : [];
$totalProjects = count($projectRows);
$activeProjects = 0;
$completedProjects = 0;
$overallTasks = 0;
$completedTasks = 0;
$nextDeadline = null;
$quotationSummary = [
    'sent' => 0,
    'accepted' => 0,
    'rejected' => 0,
    'approved' => 0,
];

foreach ($projectRows as $projectRow) {
    $status = (string)($projectRow['status'] ?? 'pending');
    if (in_array($status, ['pending', 'ongoing', 'on-hold'], true)) {
        $activeProjects++;
    }
    if ($status === 'completed') {
        $completedProjects++;
    }

    $overallTasks += (int)($projectRow['total_tasks'] ?? 0);
    $completedTasks += (int)($projectRow['completed_tasks'] ?? 0);
    $candidateDeadline = trim((string)($projectRow['next_deadline'] ?? ''));
    if ($candidateDeadline !== '' && ($nextDeadline === null || $candidateDeadline < $nextDeadline)) {
        $nextDeadline = $candidateDeadline;
    }
}

foreach ($quotationRows as $quotationRow) {
    $status = (string)($quotationRow['status'] ?? '');
    if (isset($quotationSummary[$status])) {
        $quotationSummary[$status]++;
    }
}

$clientProgressTotals = 0;
foreach ($projectRows as $projectRow) {
    $clientProgressTotals += (int)build_role_project_progress($projectRow, 'client')['percent'];
}
$overallProgress = $totalProjects > 0 ? (int)round($clientProgressTotals / $totalProjects) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Reports - Edge Automation</title>
    <link rel="stylesheet" href="../css/client_sidebar.css">
    <link rel="stylesheet" href="../css/client_dashboard.css">
    <style>
        .reports-shell,
        .report-stats,
        .report-links,
        .project-report-list {
            display: grid;
            gap: 20px;
        }

        .reports-shell {
            padding: 0;
        }

        .reports-hero,
        .reports-panel,
        .report-link-card,
        .project-report-card {
            background: #fff;
            border: 1px solid #d9e6df;
            box-shadow: var(--shadow-soft);
        }

        .reports-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.9fr);
            gap: 20px;
            padding: 28px;
            border-radius: 28px;
        }

        .reports-hero h1,
        .reports-panel h2,
        .report-link-card h3,
        .project-report-card h3 {
            margin: 0;
            color: var(--text-primary);
        }

        .reports-hero p,
        .reports-panel p,
        .report-link-card p,
        .project-report-card p {
            color: var(--text-muted);
        }

        .reports-kicker {
            margin: 0 0 10px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--brand-deep);
        }

        .reports-hero h1 {
            font-size: clamp(2rem, 4vw, 2.8rem);
            line-height: 1.05;
        }

        .report-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .report-stat {
            padding: 18px;
            border-radius: 20px;
            background: var(--surface-soft);
        }

        .report-stat span {
            display: block;
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .report-stat strong {
            display: block;
            margin-top: 6px;
            font-size: 1.8rem;
            color: var(--text-primary);
        }

        .reports-panel {
            padding: 24px;
            border-radius: 24px;
        }

        .report-links {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .report-link-card,
        .project-report-card {
            display: grid;
            gap: 12px;
            padding: 20px;
            border-radius: 22px;
        }

        .report-link-card {
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .report-link-card:hover,
        .report-link-card:focus-visible {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lift);
            outline: none;
        }

        .report-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            color: var(--brand-deep);
            font-weight: 700;
        }

        @media (max-width: 1024px) {
            .reports-hero,
            .report-links {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .report-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../sidebar/client_sidebar.php'; ?>
<?php client_shell_render_topbar($shellContext); ?>
<main class="main-content" id="mainContent">
    <div class="reports-shell">
        <section class="reports-hero">
            <div>
                <p class="reports-kicker">Client Reports</p>
                <h1>Clear project-facing reports for <?php echo htmlspecialchars($clientName); ?>.</h1>
                <p>Client reporting stays read-only and project-facing: progress summary, quotation status, timeline visibility, and delivery signals only.</p>
            </div>
            <div class="report-stats">
                <div class="report-stat">
                    <span>Projects</span>
                    <strong><?php echo $totalProjects; ?></strong>
                </div>
                <div class="report-stat">
                    <span>Active projects</span>
                    <strong><?php echo $activeProjects; ?></strong>
                </div>
                <div class="report-stat">
                    <span>Overall progress</span>
                    <strong><?php echo $overallProgress; ?>%</strong>
                </div>
                <div class="report-stat">
                    <span>Next milestone</span>
                    <strong><?php echo htmlspecialchars($nextDeadline ? client_reports_format_date($nextDeadline) : 'None'); ?></strong>
                </div>
            </div>
        </section>

        <section class="reports-panel">
            <h2>Reports available to Client</h2>
            <p>These cards keep Client visibility useful without exposing internal operational reports.</p>
            <div class="report-links">
                <a class="report-link-card" href="/codesamplecaps/CLIENT/dashboards/client_dashboard.php#projects-tab">
                    <h3>Project progress summary</h3>
                    <p>See live project-level execution progress and current delivery posture.</p>
                    <div class="report-meta">
                        <span><?php echo $activeProjects; ?> active project(s)</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/CLIENT/dashboards/quotations.php">
                    <h3>Approved and pending quotation summary</h3>
                    <p>Review quotations sent to your account and track accept or reject status.</p>
                    <div class="report-meta">
                        <span><?php echo count($quotationRows); ?> quotation(s)</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/CLIENT/dashboards/client_dashboard.php#overview-section">
                    <h3>Timeline and milestone status</h3>
                    <p>Use your dashboard summary to monitor delivery timing and upcoming targets.</p>
                    <div class="report-meta">
                        <span><?php echo htmlspecialchars($nextDeadline ? client_reports_format_date($nextDeadline) : 'No tracked deadline'); ?></span>
                        <span>Open</span>
                    </div>
                </a>
                <div class="project-report-card">
                    <h3>Billing and payment status</h3>
                    <p>Your current system does not yet track billing records as a separate client module, so this report is intentionally hidden until that data exists.</p>
                    <div class="report-meta">
                        <span>Read-only scope</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="reports-panel">
            <h2>Project report snapshot</h2>
            <p>Only your own projects appear here.</p>
            <div class="project-report-list">
                <?php if (!empty($projectRows)): ?>
                    <?php foreach ($projectRows as $projectRow): ?>
                        <?php $projectProgress = build_role_project_progress($projectRow, 'client'); ?>
                        <article class="project-report-card">
                            <h3><?php echo htmlspecialchars((string)$projectRow['project_name']); ?></h3>
                            <p><?php echo htmlspecialchars((string)$projectProgress['label']); ?>: <?php echo (int)$projectProgress['percent']; ?>% | Status: <?php echo htmlspecialchars(ucfirst((string)$projectRow['status'])); ?></p>
                            <div class="report-meta">
                                <span>Start: <?php echo htmlspecialchars(client_reports_format_date($projectRow['start_date'] ?? null)); ?></span>
                                <span>End: <?php echo htmlspecialchars(client_reports_format_date($projectRow['end_date'] ?? null)); ?></span>
                                <span><?php echo htmlspecialchars((string)$projectProgress['summary']); ?></span>
                            </div>
                            <p><?php echo htmlspecialchars((string)$projectProgress['hint']); ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <article class="project-report-card">
                        <h3>No project reports yet</h3>
                        <p>Project-facing reports will appear here once a live project is linked to your account.</p>
                    </article>
                <?php endif; ?>
            </div>
        </section>

        <section class="reports-panel">
            <h2>Quotation response snapshot</h2>
            <p>This keeps the client-side reporting short and easy to scan.</p>
            <div class="report-stats">
                <div class="report-stat">
                    <span>Sent</span>
                    <strong><?php echo $quotationSummary['sent']; ?></strong>
                </div>
                <div class="report-stat">
                    <span>Accepted</span>
                    <strong><?php echo $quotationSummary['accepted']; ?></strong>
                </div>
                <div class="report-stat">
                    <span>Rejected</span>
                    <strong><?php echo $quotationSummary['rejected']; ?></strong>
                </div>
                <div class="report-stat">
                    <span>Approved</span>
                    <strong><?php echo $quotationSummary['approved']; ?></strong>
                </div>
            </div>
        </section>
    </div>
</main>
<script src="../js/client_dashboard.js"></script>
</body>
</html>
