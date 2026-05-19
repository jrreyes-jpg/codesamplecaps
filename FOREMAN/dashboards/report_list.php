<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/foreman_helpers.php';

require_role('foreman');

$userId = (int)($_SESSION['user_id'] ?? 0);
$foremanProfileName = (string)($_SESSION['name'] ?? 'Foreman');
$foremanProfile = foreman_fetch_profile($conn, $userId);
$foremanProfileName = (string)($foremanProfile['full_name'] ?? $foremanProfileName);
$foremanNotifications = [
    'attention_count' => 0,
    'logs_today' => 0,
    'scans_today' => 0,
];

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$quick = trim((string)($_GET['quick'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$allowedQuickFilters = ['', 'overdue', 'due_today', 'blocked', 'completed'];

if (!in_array($quick, $allowedQuickFilters, true)) {
    $quick = '';
}

$filters = [
    'q' => $q,
    'status' => $status,
    'quick' => $quick,
];

$allReports = foreman_fetch_normalized_reports($conn, $userId, $filters);
$totalReports = count($allReports);
$totalPages = max(1, (int)ceil($totalReports / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$reports = array_slice($allReports, $offset, $perPage);

$statusOptions = [
    '' => 'All Statuses',
    'pending' => 'Pending',
    'ongoing' => 'Ongoing',
    'completed' => 'Completed',
    'delayed' => 'Delayed',
    'submitted' => 'Submitted',
    'engineer_review' => 'Engineer Review',
    'engineer_approved' => 'Engineer Approved',
    'engineer_rejected' => 'Engineer Rejected',
    'approved' => 'Approved',
    'cancelled' => 'Cancelled',
];

$baseParams = array_filter(
    [
        'q' => $q,
        'status' => $status,
        'quick' => $quick,
    ],
    static fn($value): bool => $value !== ''
);

$returnParams = array_merge($baseParams, ['page' => $page]);
$currentQueryString = http_build_query($returnParams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreman Report List - Edge Automation</title>
    <link rel="stylesheet" href="../css/sidebar_foreman.css">
    <link rel="stylesheet" href="../css/foreman_dashboard.css">
    <link rel="stylesheet" href="../css/foreman_reports.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>

<main class="main-content">
    <div class="page-shell">
        <section class="page-hero reports-page-hero reports-page-hero--compact">
            <div class="page-hero__content">
                <span class="page-hero__eyebrow">Report List</span>
                <h1 class="page-hero__title">Operational Report Workspace</h1>
                <p class="page-hero__copy page-hero__copy--compact">Search, filter, and process field task and procurement reports in one table-first view.</p>
            </div>
        </section>

        <section class="panel-card reports-toolbar-panel">
            <form method="get" class="reports-toolbar">
                <div class="reports-toolbar__search">
                    <label for="report-search">Search</label>
                    <input id="report-search" type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search title, project, status, request no">
                </div>

                <div class="reports-toolbar__filter">
                    <label for="report-status">Status</label>
                    <select id="report-status" name="status">
                        <?php foreach ($statusOptions as $optionValue => $optionLabel): ?>
                            <option value="<?php echo htmlspecialchars($optionValue); ?>"<?php echo $status === $optionValue ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($optionLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="reports-toolbar__filter">
                    <label for="report-quick">Quick Filter</label>
                    <select id="report-quick" name="quick">
                        <option value="">All Reports</option>
                        <option value="overdue"<?php echo $quick === 'overdue' ? ' selected' : ''; ?>>Overdue</option>
                        <option value="due_today"<?php echo $quick === 'due_today' ? ' selected' : ''; ?>>Due Today</option>
                        <option value="blocked"<?php echo $quick === 'blocked' ? ' selected' : ''; ?>>Blocked</option>
                        <option value="completed"<?php echo $quick === 'completed' ? ' selected' : ''; ?>>Completed</option>
                    </select>
                </div>

                <div class="reports-toolbar__actions">
                    <button class="btn-primary" type="submit">Apply</button>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/report_list.php">Reset</a>
                </div>
            </form>

            <div class="reports-chip-row">
                <a class="report-chip<?php echo $quick === 'overdue' ? ' report-chip--active' : ''; ?>" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=overdue">Overdue</a>
                <a class="report-chip<?php echo $quick === 'due_today' ? ' report-chip--active' : ''; ?>" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=due_today">Due Today</a>
                <a class="report-chip<?php echo $quick === 'blocked' ? ' report-chip--active' : ''; ?>" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=blocked">Blocked</a>
                <a class="report-chip<?php echo $quick === 'completed' ? ' report-chip--active' : ''; ?>" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=completed">Completed</a>
            </div>
        </section>

        <section class="panel-card">
            <div class="reports-table-head">
                <div>
                    <span class="section-badge">Queue</span>
                    <h2>Reports</h2>
                </div>
                <p><?php echo $totalReports; ?> total result<?php echo $totalReports === 1 ? '' : 's'; ?></p>
            </div>

            <?php if (empty($reports)): ?>
                <div class="empty-state">No reports matched the current filters.</div>
            <?php else: ?>
                <div class="table-wrap reports-table-wrap">
                    <table class="data-table reports-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th>Updated At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $report): ?>
                                <?php $detailLink = '/codesamplecaps/FOREMAN/dashboards/report_detail.php?id=' . (int)$report['id'] . '&type=' . urlencode((string)$report['type']) . ($currentQueryString !== '' ? '&return=' . urlencode($currentQueryString) : ''); ?>
                                <tr>
                                    <td>
                                        <div class="report-title-cell">
                                            <span class="report-pill <?php echo htmlspecialchars(foreman_report_priority_class($report)); ?>">
                                                <?php echo htmlspecialchars(foreman_report_priority_label($report)); ?>
                                            </span>
                                            <strong><?php echo htmlspecialchars((string)$report['title']); ?></strong>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars(ucfirst((string)$report['type'])); ?></td>
                                    <td><?php echo htmlspecialchars((string)$report['project_name']); ?></td>
                                    <td>
                                        <span class="status-badge status-badge--neutral">
                                            <?php echo htmlspecialchars(foreman_status_label((string)$report['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo htmlspecialchars(foreman_report_due_class($report)); ?>">
                                        <?php echo htmlspecialchars(foreman_format_date($report['due_date'] ?? null)); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(foreman_format_datetime($report['updated_at'] ?? null)); ?></td>
                                    <td><a class="reports-link-action" href="<?php echo htmlspecialchars($detailLink); ?>">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
                <nav class="reports-pagination" aria-label="Report pagination">
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <?php $pageLink = '/codesamplecaps/FOREMAN/dashboards/report_list.php?' . http_build_query(array_merge($baseParams, ['page' => $pageNumber])); ?>
                        <a class="reports-pagination__link<?php echo $pageNumber === $page ? ' reports-pagination__link--active' : ''; ?>" href="<?php echo htmlspecialchars($pageLink); ?>">
                            <?php echo $pageNumber; ?>
                        </a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        </section>
    </div>
</main>
<script src="../js/sidebar_foreman.js"></script>
</body>
</html>
