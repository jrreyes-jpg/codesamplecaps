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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engineer Reports - Edge Automation</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
    <style>
        .reports-shell,
        .report-card-grid,
        .report-links-grid,
        .report-project-list {
            display: grid;
            gap: 20px;
        }

        .reports-shell {
            padding: 0;
        }

        .reports-hero,
        .report-surface,
        .report-link-card,
        .report-project-card {
            border: 1px solid rgba(22, 48, 43, 0.08);
            box-shadow: 0 18px 48px rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.92);
        }

        .reports-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.9fr);
            gap: 20px;
            padding: 24px;
            border-radius: 24px;
            margin-bottom: 24px;
        }

        .reports-hero h1,
        .report-surface h2,
        .report-link-card h3,
        .report-project-card h3 {
            margin: 0;
            color: #0f172a;
        }

        .reports-hero p,
        .report-surface p,
        .report-link-card p,
        .report-project-card p {
            color: #4f6460;
        }

        .report-kicker {
            margin: 0 0 10px;
            font-size: 0.78rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            font-weight: 700;
            color: #15803d;
        }

        .reports-hero h1 {
            font-size: clamp(2rem, 4vw, 2.7rem);
            line-height: 1.06;
        }

        .report-card-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .report-stat-card {
            padding: 18px;
            border-radius: 20px;
            background: #f8fafc;
        }

        .report-stat-card span {
            display: block;
            font-size: 0.82rem;
            color: #64748b;
        }

        .report-stat-card strong {
            display: block;
            margin-top: 6px;
            font-size: 1.8rem;
            color: #0f172a;
        }

        .report-surface {
            padding: 24px;
            border-radius: 24px;
        }

        .report-links-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .report-link-card,
        .report-project-card {
            padding: 20px;
            border-radius: 20px;
        }

        .report-link-card {
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .report-link-card:hover,
        .report-link-card:focus-visible {
            transform: translateY(-3px);
            box-shadow: 0 22px 42px rgba(15, 23, 42, 0.12);
            outline: none;
        }

        .report-link-card__meta,
        .report-project-card__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
            color: #166534;
            font-weight: 700;
        }

        @media (max-width: 1200px) {
            .report-card-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .reports-hero,
            .report-links-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .report-card-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_engineer.php'; ?>

<div class="main-content">
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

<script src="../js/engineer.js"></script>
</body>
</html>
