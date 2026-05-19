<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/foreman_helpers.php';

require_role('foreman');

$userId = (int)($_SESSION['user_id'] ?? 0);
$isPdf = isset($_GET['pdf']) && (string)$_GET['pdf'] === '1';
$isDownload = isset($_GET['download']) && (string)$_GET['download'] === '1';
$foremanProfileName = (string)($_SESSION['fullname'] ?? $_SESSION['name'] ?? 'Unknown Foreman');
$foremanProfile = foreman_fetch_profile($conn, $userId);
$foremanProfileName = (string)($foremanProfile['full_name'] ?? $foremanProfileName);
$foremanNotifications = [
    'attention_count' => 0,
    'logs_today' => 0,
    'scans_today' => 0,
];

$id = (int)($_GET['id'] ?? 0);
$type = trim((string)($_GET['type'] ?? ''));
$returnQuery = trim((string)($_GET['return'] ?? ''));
$report = foreman_fetch_report_detail($conn, $userId, $type, $id);
$backLink = '/codesamplecaps/FOREMAN/dashboards/report_list.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
$pdfLink = '/codesamplecaps/FOREMAN/dashboards/report_detail.php?id=' . $id . '&type=' . urlencode($type) . '&pdf=1';
$pdfDownloadLink = '/codesamplecaps/FOREMAN/dashboards/report_detail.php?id=' . $id . '&type=' . urlencode($type) . '&download=1';
$generatedAt = (new DateTimeImmutable('now'))->format('M j, Y g:i A');

if ($isDownload) {
    if (!$report) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Report not found or you do not have access to it.';
        exit;
    }

    foreman_stream_report_pdf($report, $foremanProfileName, $generatedAt, true);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isPdf ? 'Foreman Report PDF - Edge Automation' : 'Foreman Report Detail - Edge Automation'; ?></title>
    <?php if (!$isPdf): ?>
    <link rel="stylesheet" href="../css/sidebar_foreman.css">
    <link rel="stylesheet" href="../css/foreman_dashboard.css">
    <?php endif; ?>
    <link rel="stylesheet" href="../css/foreman_reports.css">
</head>
<body>
<?php if (!$isPdf): ?>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>
<?php endif; ?>

<?php if ($isPdf): ?>
    <?php include __DIR__ . '/report_pdf_template.php'; ?>
<?php else: ?>
<main class="main-content">
    <div class="page-shell">
        <?php if (!$report): ?>
            <section class="panel-card">
                <div class="empty-state">Report not found or you do not have access to it.</div>
                <div class="hero-actions">
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/report_list.php">Back to List</a>
                </div>
            </section>
        <?php else: ?>
            <article class="report-detail-card">
                <header class="report-detail__header">
                    <div>
                        <p class="report-detail__code"><?php echo htmlspecialchars((string)$report['reference_no']); ?></p>
                        <h1><?php echo htmlspecialchars((string)$report['title']); ?></h1>
                        <p class="report-detail__subtitle"><?php echo htmlspecialchars(ucfirst((string)$report['type'])); ?> report for <?php echo htmlspecialchars((string)$report['project_name']); ?></p>
                    </div>

                    <div class="report-detail__actions no-print">
                        <a class="btn-secondary" href="<?php echo htmlspecialchars($backLink); ?>">Back to List</a>
                        <a class="btn-secondary" href="<?php echo htmlspecialchars($pdfLink); ?>" target="_blank" rel="noopener noreferrer">Open Print View</a>
                        <a class="btn-primary" href="<?php echo htmlspecialchars($pdfDownloadLink); ?>">Download PDF</a>
                    </div>
                </header>

                <section class="report-detail__status">
                    <span class="report-pill <?php echo htmlspecialchars(foreman_report_priority_class($report)); ?>">
                        <?php echo htmlspecialchars(foreman_report_priority_label($report)); ?>
                    </span>
                    <span class="status-badge status-badge--neutral">
                        <?php echo htmlspecialchars(foreman_status_label((string)$report['status'])); ?>
                    </span>
                    <span class="report-pill report-pill--neutral">Due <?php echo htmlspecialchars(foreman_format_date($report['due_date'] ?? null)); ?></span>
                    <span class="report-pill report-pill--neutral">Updated <?php echo htmlspecialchars(foreman_format_datetime($report['updated_at'] ?? null)); ?></span>
                </section>

                <section class="report-detail__block">
                    <h2>Report Summary</h2>
                    <div class="report-detail__meta-grid">
                        <div>
                            <span>Project</span>
                            <strong><?php echo htmlspecialchars((string)$report['project_name']); ?></strong>
                        </div>
                        <div>
                            <span>Type</span>
                            <strong><?php echo htmlspecialchars(ucfirst((string)$report['type'])); ?></strong>
                        </div>
                        <div>
                            <span>Status</span>
                            <strong><?php echo htmlspecialchars(foreman_status_label((string)$report['status'])); ?></strong>
                        </div>
                        <div>
                            <span>Due Date</span>
                            <strong><?php echo htmlspecialchars(foreman_format_date($report['due_date'] ?? null)); ?></strong>
                        </div>
                        <div>
                            <span>Created</span>
                            <strong><?php echo htmlspecialchars(foreman_format_datetime($report['created_at'] ?? null)); ?></strong>
                        </div>
                        <div>
                            <span>Last Updated</span>
                            <strong><?php echo htmlspecialchars(foreman_format_datetime($report['updated_at'] ?? null)); ?></strong>
                        </div>
                        <div>
                            <span>Generated By</span>
                            <strong><?php echo htmlspecialchars($foremanProfileName); ?></strong>
                        </div>
                        <div>
                            <span>Generated At</span>
                            <strong><?php echo htmlspecialchars($generatedAt); ?></strong>
                        </div>
                    </div>
                </section>

                <section class="report-detail__block">
                    <h2>Description / Details</h2>
                    <div class="report-detail__body">
                        <?php echo nl2br(htmlspecialchars(trim((string)($report['details'] ?? '')) !== '' ? (string)$report['details'] : 'No details recorded.')); ?>
                    </div>
                </section>
            </article>
        <?php endif; ?>
    </div>
</main>
<?php endif; ?>
<script src="../js/sidebar_foreman.js"></script>
</body>
</html>
