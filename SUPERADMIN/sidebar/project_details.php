<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';
require_once __DIR__ . '/../../config/project_progress.php';

require_role('super_admin');
$csrfToken = auth_csrf_token('super_admin');

function pm_get_column_type(mysqli $conn, string $tableName, string $columnName): ?string {
    $stmt = $conn->prepare(
        'SELECT COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND COLUMN_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    return $row['COLUMN_TYPE'] ?? null;
}

function pm_table_has_column(mysqli $conn, string $tableName, string $columnName): bool {
    return pm_get_column_type($conn, $tableName, $columnName) !== null;
}

function pm_enum_supports_value(mysqli $conn, string $tableName, string $columnName, string $value): bool {
    $columnType = pm_get_column_type($conn, $tableName, $columnName);
    return $columnType !== null && str_contains($columnType, "'" . $value . "'");
}

function pm_today_date(): string {
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function pm_format_money($value): string {
    return 'PHP ' . number_format((float)$value, 2);
}

function pm_format_date(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return 'N/A';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function pm_relative_time_label(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return 'No recent update';
    }

    try {
        $date = new DateTimeImmutable($value);
        $now = new DateTimeImmutable();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 60) {
            return 'Just now';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . ' mins ago';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . ' hrs ago';
        }

        if ($diff < 604800) {
            return floor($diff / 86400) . ' days ago';
        }

        return $date->format('M j, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function pm_build_budget_health(float $budgetAmount, float $totalCost): array {
    if ($budgetAmount <= 0) {
        return ['status' => 'unplanned', 'label' => 'No budget set'];
    }

    $usage = $totalCost / $budgetAmount;
    if ($usage >= 1) {
        return ['status' => 'over', 'label' => 'Over budget'];
    }

    if ($usage >= 0.85) {
        return ['status' => 'warning', 'label' => 'Budget watch'];
    }

    return ['status' => 'healthy', 'label' => 'On track'];
}

function pm_determine_payment_status(float $totalCost, float $amountPaid): array {
    if ($amountPaid <= 0.0) {
        return ['status' => 'unpaid', 'label' => 'Unpaid'];
    }

    if ($totalCost > 0 && $amountPaid + 0.00001 < $totalCost) {
        return ['status' => 'partial', 'label' => 'Partial'];
    }

    if ($totalCost <= 0) {
        return ['status' => 'partial', 'label' => 'Partial'];
    }

    return ['status' => 'paid', 'label' => 'Paid'];
}

function pm_ensure_project_inventory_deployments_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS project_inventory_deployments (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id INT(11) NOT NULL,
            inventory_id INT(11) NOT NULL,
            quantity INT(11) NOT NULL DEFAULT 1,
            deployed_by INT(11) NOT NULL,
            deployed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            returned_at TIMESTAMP NULL DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            KEY idx_project_inventory_deployments_project (project_id),
            KEY idx_project_inventory_deployments_inventory (inventory_id),
            KEY idx_project_inventory_deployments_returned (returned_at),
            CONSTRAINT fk_project_inventory_deployments_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_project_inventory_deployments_inventory FOREIGN KEY (inventory_id) REFERENCES inventory (id) ON DELETE CASCADE,
            CONSTRAINT fk_project_inventory_deployments_user FOREIGN KEY (deployed_by) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function pm_ensure_project_inventory_return_logs_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS project_inventory_return_logs (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            deployment_id INT(11) NOT NULL,
            quantity INT(11) NOT NULL DEFAULT 1,
            returned_by INT(11) NOT NULL,
            returned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes TEXT DEFAULT NULL,
            KEY idx_project_inventory_return_logs_deployment (deployment_id),
            KEY idx_project_inventory_return_logs_returned_at (returned_at),
            CONSTRAINT fk_project_inventory_return_logs_deployment FOREIGN KEY (deployment_id) REFERENCES project_inventory_deployments (id) ON DELETE CASCADE,
            CONSTRAINT fk_project_inventory_return_logs_user FOREIGN KEY (returned_by) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function pm_ensure_project_budget_profiles_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS project_budget_profiles (
            project_id INT(11) NOT NULL PRIMARY KEY,
            budget_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            budget_notes TEXT DEFAULT NULL,
            created_by INT(11) NOT NULL,
            updated_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_project_budget_profiles_updated_at (updated_at),
            CONSTRAINT fk_project_budget_profiles_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_project_budget_profiles_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_project_budget_profiles_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function pm_ensure_project_cost_entries_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS project_cost_entries (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id INT(11) NOT NULL,
            cost_date DATE NOT NULL,
            cost_category VARCHAR(80) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            amount DECIMAL(14,2) NOT NULL,
            created_by INT(11) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_project_cost_entries_project_date (project_id, cost_date, id),
            KEY idx_project_cost_entries_created_by (created_by),
            CONSTRAINT fk_project_cost_entries_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_project_cost_entries_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function pm_ensure_project_payments_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS project_payments (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id INT(11) NOT NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT(11) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_project_payments_project_date (project_id, payment_date, id),
            KEY idx_project_payments_created_by (created_by),
            CONSTRAINT fk_project_payments_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_project_payments_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function pm_ensure_project_address_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'project_address')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_address TEXT DEFAULT NULL AFTER client_id");
    }
}

function pm_ensure_project_site_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'project_site')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_site VARCHAR(190) DEFAULT NULL AFTER client_id");
    }
}

function pm_ensure_project_email_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'project_email')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_email VARCHAR(190) DEFAULT NULL AFTER project_address");
    }
}

function pm_ensure_project_code_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'project_code')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_code VARCHAR(80) DEFAULT NULL AFTER project_email");
    }
}

function pm_ensure_project_po_number_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'po_number')) {
        $conn->query("ALTER TABLE projects ADD COLUMN po_number VARCHAR(80) DEFAULT NULL AFTER project_code");
    }
}

function pm_ensure_project_contact_person_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'contact_person')) {
        $conn->query("ALTER TABLE projects ADD COLUMN contact_person VARCHAR(190) DEFAULT NULL AFTER client_id");
    }
}

function pm_ensure_project_contact_number_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'contact_number')) {
        $conn->query("ALTER TABLE projects ADD COLUMN contact_number VARCHAR(40) DEFAULT NULL AFTER contact_person");
    }
}

function pm_ensure_project_start_date_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'project_start_date')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_start_date DATE DEFAULT NULL AFTER start_date");
    }
}

function pm_ensure_estimated_completion_date_column(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'estimated_completion_date')) {
        $conn->query("ALTER TABLE projects ADD COLUMN estimated_completion_date DATE DEFAULT NULL AFTER project_start_date");
    }
}

function pm_ensure_project_soft_delete_columns(mysqli $conn): void {
    if (!pm_table_has_column($conn, 'projects', 'deleted_at')) {
        $conn->query("ALTER TABLE projects ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER status");
    }

    if (!pm_table_has_column($conn, 'projects', 'deleted_by')) {
        $conn->query("ALTER TABLE projects ADD COLUMN deleted_by INT(11) DEFAULT NULL AFTER deleted_at");
    }

    if (!pm_table_has_column($conn, 'projects', 'delete_scheduled_at')) {
        $conn->query("ALTER TABLE projects ADD COLUMN delete_scheduled_at DATETIME DEFAULT NULL AFTER deleted_by");
    }
}

function pm_get_project_financial_snapshot(mysqli $conn, int $projectId): ?array {
    $stmt = $conn->prepare(
        'SELECT
            p.id,
            COALESCE(bp.budget_amount, 0) AS budget_amount,
            bp.budget_notes,
            COALESCE(cost_totals.total_cost, 0) AS total_cost,
            COALESCE(cost_totals.cost_entry_count, 0) AS cost_entry_count
         FROM projects p
         LEFT JOIN project_budget_profiles bp ON bp.project_id = p.id
         LEFT JOIN (
             SELECT project_id, SUM(amount) AS total_cost, COUNT(*) AS cost_entry_count
             FROM project_cost_entries
             GROUP BY project_id
         ) cost_totals ON cost_totals.project_id = p.id
         WHERE p.id = ?
         AND p.deleted_at IS NULL
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function pm_get_project_payment_snapshot(mysqli $conn, int $projectId): ?array {
    $stmt = $conn->prepare(
        'SELECT
            p.id,
            COALESCE(cost_totals.total_cost, 0) AS total_cost,
            COALESCE(payment_totals.amount_paid, 0) AS amount_paid,
            COALESCE(payment_totals.payment_entry_count, 0) AS payment_entry_count
         FROM projects p
         LEFT JOIN (
             SELECT project_id, SUM(amount) AS total_cost
             FROM project_cost_entries
             GROUP BY project_id
         ) cost_totals ON cost_totals.project_id = p.id
         LEFT JOIN (
             SELECT project_id, SUM(amount) AS amount_paid, COUNT(*) AS payment_entry_count
             FROM project_payments
             GROUP BY project_id
         ) payment_totals ON payment_totals.project_id = p.id
         WHERE p.id = ?
         AND p.deleted_at IS NULL
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function pm_fetch_project_cost_entries(mysqli $conn, int $projectId): array {
    $stmt = $conn->prepare(
        'SELECT
            pce.cost_date,
            pce.cost_category,
            pce.description,
            pce.amount,
            u.full_name AS created_by_name
         FROM project_cost_entries pce
         LEFT JOIN users u ON u.id = pce.created_by
         WHERE pce.project_id = ?
         ORDER BY pce.cost_date DESC, pce.id DESC'
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function pm_fetch_project_payment_entries(mysqli $conn, int $projectId): array {
    $stmt = $conn->prepare(
        'SELECT
            pp.payment_date,
            pp.amount,
            pp.notes,
            u.full_name AS created_by_name
         FROM project_payments pp
         LEFT JOIN users u ON u.id = pp.created_by
         WHERE pp.project_id = ?
         ORDER BY pp.payment_date DESC, pp.id DESC'
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$supportsDraftStatus = pm_enum_supports_value($conn, 'projects', 'status', 'draft');
$supportsCancelledStatus = pm_enum_supports_value($conn, 'projects', 'status', 'cancelled');
$supportsArchivedStatus = pm_enum_supports_value($conn, 'projects', 'status', 'archived');
pm_ensure_project_address_column($conn);
pm_ensure_project_site_column($conn);
pm_ensure_project_email_column($conn);
pm_ensure_project_code_column($conn);
pm_ensure_project_po_number_column($conn);
pm_ensure_project_contact_person_column($conn);
pm_ensure_project_contact_number_column($conn);
pm_ensure_project_start_date_column($conn);
pm_ensure_estimated_completion_date_column($conn);
pm_ensure_project_soft_delete_columns($conn);
$hasProjectSiteColumn = pm_table_has_column($conn, 'projects', 'project_site');
$hasProjectAddressColumn = pm_table_has_column($conn, 'projects', 'project_address');
$hasProjectEmailColumn = pm_table_has_column($conn, 'projects', 'project_email');
$hasProjectCodeColumn = pm_table_has_column($conn, 'projects', 'project_code');
$hasPoNumberColumn = pm_table_has_column($conn, 'projects', 'po_number');
$hasContactPersonColumn = pm_table_has_column($conn, 'projects', 'contact_person');
$hasContactNumberColumn = pm_table_has_column($conn, 'projects', 'contact_number');
$hasProjectStartDateColumn = pm_table_has_column($conn, 'projects', 'project_start_date');
$hasEstimatedCompletionDateColumn = pm_table_has_column($conn, 'projects', 'estimated_completion_date');
$statusOptions = [];
if ($supportsDraftStatus) {
    $statusOptions[] = 'draft';
}
$statusOptions = array_merge($statusOptions, ['pending', 'ongoing', 'completed', 'on-hold']);
if ($supportsCancelledStatus) {
    $statusOptions[] = 'cancelled';
}
if ($supportsArchivedStatus) {
    $statusOptions[] = 'archived';
}
$todayDate = pm_today_date();
$projectId = max(0, (int)($_GET['id'] ?? 0));
$detailsPath = '/codesamplecaps/SUPERADMIN/sidebar/project_details.php?id=' . $projectId;

pm_ensure_project_inventory_deployments_table($conn);
pm_ensure_project_inventory_return_logs_table($conn);
ensure_asset_unit_tracking_schema($conn);
pm_ensure_project_budget_profiles_table($conn);
pm_ensure_project_cost_entries_table($conn);
pm_ensure_project_payments_table($conn);

$flash = $_SESSION['projects_flash'] ?? null;
unset($_SESSION['projects_flash']);

$clients = [];
$engineers = [];
$project = null;
$tasks = [];
$activeDeployments = [];
$deploymentHistory = [];
$availableInventory = [];
$projectFinancials = null;
$projectCostEntries = [];
$projectPaymentSnapshot = null;
$projectPaymentEntries = [];

$clientResult = $conn->query("SELECT id, full_name FROM users WHERE role = 'client' AND status = 'active' ORDER BY full_name ASC");
if ($clientResult) {
    $clients = $clientResult->fetch_all(MYSQLI_ASSOC);
}

$engineerResult = $conn->query("SELECT id, full_name, role FROM users WHERE role IN ('engineer', 'foreman') AND status = 'active' ORDER BY full_name ASC");
if ($engineerResult) {
    $engineers = $engineerResult->fetch_all(MYSQLI_ASSOC);
}

if ($projectId > 0) {
    $projectSiteSelect = $hasProjectSiteColumn ? 'p.project_site,' : 'NULL AS project_site,';
    $projectAddressSelect = $hasProjectAddressColumn ? 'p.project_address,' : 'NULL AS project_address,';
    $projectEmailSelect = $hasProjectEmailColumn ? 'p.project_email,' : 'NULL AS project_email,';
    $contactPersonSelect = $hasContactPersonColumn ? 'p.contact_person,' : 'NULL AS contact_person,';
    $contactNumberSelect = $hasContactNumberColumn ? 'p.contact_number,' : 'NULL AS contact_number,';
    $projectCodeSelect = $hasProjectCodeColumn ? 'p.project_code,' : 'NULL AS project_code,';
    $poNumberSelect = $hasPoNumberColumn ? 'p.po_number,' : 'NULL AS po_number,';
    $projectStartDateSelect = $hasProjectStartDateColumn ? 'p.project_start_date,' : 'NULL AS project_start_date,';
    $estimatedCompletionDateSelect = $hasEstimatedCompletionDateColumn ? 'p.estimated_completion_date,' : 'NULL AS estimated_completion_date,';

    $projectStmt = $conn->prepare("
        SELECT
            p.id,
            p.project_name,
            p.description,
            {$projectSiteSelect}
            {$projectAddressSelect}
            {$projectEmailSelect}
            {$contactPersonSelect}
            {$contactNumberSelect}
            {$projectCodeSelect}
            {$poNumberSelect}
            {$projectStartDateSelect}
            {$estimatedCompletionDateSelect}
            p.client_id,
            p.start_date,
            p.end_date,
            p.status,
            p.created_at,
            client.full_name AS client_name,
            client.email AS client_email,
            assignment_summary.engineer_ids_csv,
            assignment_summary.engineer_names,
            COALESCE(task_totals.total_tasks, 0) AS total_tasks,
            COALESCE(task_totals.completed_tasks, 0) AS completed_tasks
        FROM projects p
        LEFT JOIN users client ON client.id = p.client_id
        LEFT JOIN (
            SELECT
                pa.project_id,
                GROUP_CONCAT(DISTINCT pa.engineer_id ORDER BY pa.engineer_id SEPARATOR ',') AS engineer_ids_csv,
                GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS engineer_names
            FROM project_assignments pa
            INNER JOIN users u ON u.id = pa.engineer_id
            GROUP BY pa.project_id
        ) assignment_summary ON assignment_summary.project_id = p.id
        LEFT JOIN (
            SELECT
                project_id,
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks
            FROM tasks
            GROUP BY project_id
        ) task_totals ON task_totals.project_id = p.id
        WHERE p.id = ?
        AND p.deleted_at IS NULL
        LIMIT 1
    ");

    if ($projectStmt) {
        $projectStmt->bind_param('i', $projectId);
        $projectStmt->execute();
        $projectResult = $projectStmt->get_result();
        $project = $projectResult ? $projectResult->fetch_assoc() : null;
    }

    if ($project) {
        $projectFinancials = pm_get_project_financial_snapshot($conn, $projectId);
        $projectCostEntries = pm_fetch_project_cost_entries($conn, $projectId);
        $projectPaymentSnapshot = pm_get_project_payment_snapshot($conn, $projectId);
        $projectPaymentEntries = pm_fetch_project_payment_entries($conn, $projectId);
    }

    $tasksStmt = $conn->prepare("
        SELECT
            t.id,
            t.task_name,
            t.status,
            t.deadline,
            t.description,
            assignee.full_name AS assignee_name
        FROM tasks t
        LEFT JOIN users assignee ON assignee.id = t.assigned_to
        WHERE t.project_id = ?
        ORDER BY t.deadline IS NULL, t.deadline ASC, t.id DESC
    ");

    if ($tasksStmt) {
        $tasksStmt->bind_param('i', $projectId);
        $tasksStmt->execute();
        $tasksResult = $tasksStmt->get_result();
        $tasks = $tasksResult ? $tasksResult->fetch_all(MYSQLI_ASSOC) : [];
    }

    $availableInventoryResult = $conn->query(
        "SELECT
            i.id,
            i.quantity,
            a.asset_name,
            a.asset_type,
            a.serial_number
         FROM inventory i
         INNER JOIN assets a ON a.id = i.asset_id
         WHERE i.quantity > 0
         ORDER BY a.asset_name ASC, i.id ASC"
    );

    if ($availableInventoryResult) {
        $availableInventory = $availableInventoryResult->fetch_all(MYSQLI_ASSOC);
    }

    $activeDeploymentsStmt = $conn->prepare("
        SELECT
            pid.id,
            pid.quantity,
            COALESCE(returns.returned_quantity, 0) AS returned_quantity,
            (pid.quantity - COALESCE(returns.returned_quantity, 0)) AS remaining_quantity,
            pid.deployed_at,
            pid.notes,
            a.asset_name,
            a.asset_type,
            a.serial_number,
            deployer.full_name AS deployed_by_name,
            unit_summary.unit_codes
        FROM project_inventory_deployments pid
        INNER JOIN inventory i ON i.id = pid.inventory_id
        INNER JOIN assets a ON a.id = i.asset_id
        LEFT JOIN users deployer ON deployer.id = pid.deployed_by
        LEFT JOIN (
            SELECT deployment_id, SUM(quantity) AS returned_quantity
            FROM project_inventory_return_logs
            GROUP BY deployment_id
        ) returns ON returns.deployment_id = pid.id
        LEFT JOIN (
            SELECT
                pdu.deployment_id,
                GROUP_CONCAT(au.unit_code ORDER BY au.unit_code SEPARATOR ', ') AS unit_codes
            FROM project_inventory_deployment_units pdu
            INNER JOIN asset_units au ON au.id = pdu.asset_unit_id
            WHERE pdu.returned_at IS NULL
            GROUP BY pdu.deployment_id
        ) unit_summary ON unit_summary.deployment_id = pid.id
        WHERE pid.project_id = ?
        AND (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
        ORDER BY pid.deployed_at DESC, pid.id DESC
    ");

    if ($activeDeploymentsStmt) {
        $activeDeploymentsStmt->bind_param('i', $projectId);
        $activeDeploymentsStmt->execute();
        $activeDeploymentsResult = $activeDeploymentsStmt->get_result();
        $activeDeployments = $activeDeploymentsResult ? $activeDeploymentsResult->fetch_all(MYSQLI_ASSOC) : [];
    }

    $deploymentHistoryStmt = $conn->prepare("
        SELECT
            pid.id,
            pid.quantity,
            COALESCE(returns.returned_quantity, 0) AS returned_quantity,
            (pid.quantity - COALESCE(returns.returned_quantity, 0)) AS remaining_quantity,
            pid.deployed_at,
            pid.notes,
            a.asset_name,
            a.asset_type,
            a.serial_number,
            deployer.full_name AS deployed_by_name,
            last_return.last_returned_at,
            unit_summary.unit_codes
        FROM project_inventory_deployments pid
        INNER JOIN inventory i ON i.id = pid.inventory_id
        INNER JOIN assets a ON a.id = i.asset_id
        LEFT JOIN users deployer ON deployer.id = pid.deployed_by
        LEFT JOIN (
            SELECT deployment_id, SUM(quantity) AS returned_quantity
            FROM project_inventory_return_logs
            GROUP BY deployment_id
        ) returns ON returns.deployment_id = pid.id
        LEFT JOIN (
            SELECT deployment_id, MAX(returned_at) AS last_returned_at
            FROM project_inventory_return_logs
            GROUP BY deployment_id
        ) last_return ON last_return.deployment_id = pid.id
        LEFT JOIN (
            SELECT
                pdu.deployment_id,
                GROUP_CONCAT(au.unit_code ORDER BY au.unit_code SEPARATOR ', ') AS unit_codes
            FROM project_inventory_deployment_units pdu
            INNER JOIN asset_units au ON au.id = pdu.asset_unit_id
            GROUP BY pdu.deployment_id
        ) unit_summary ON unit_summary.deployment_id = pid.id
        WHERE pid.project_id = ?
        ORDER BY pid.deployed_at DESC, pid.id DESC
    ");

    if ($deploymentHistoryStmt) {
        $deploymentHistoryStmt->bind_param('i', $projectId);
        $deploymentHistoryStmt->execute();
        $deploymentHistoryResult = $deploymentHistoryStmt->get_result();
        $deploymentHistory = $deploymentHistoryResult ? $deploymentHistoryResult->fetch_all(MYSQLI_ASSOC) : [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details - Super Admin</title>
    <link rel="stylesheet" href="../css/super_admin_dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="page-stack">
            <?php if ($flash): ?>
                <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : (($flash['type'] ?? '') === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (!$project): ?>
                <div class="empty-state">Project not found.</div>
            <?php else: ?>
                <?php
                $isDraft = ($project['status'] ?? '') === 'draft';
                $isCompleted = ($project['status'] ?? '') === 'completed';
                $assignedEngineerIds = array_values(array_filter(array_map('intval', explode(',', (string)($project['engineer_ids_csv'] ?? '')))));
                $assignedEngineerNames = trim((string)($project['engineer_names'] ?? ''));
                $defaultTaskEngineerId = $assignedEngineerIds[0] ?? 0;
                $projectProgress = build_role_project_progress($project, 'super_admin');
                $completionRate = (int)$projectProgress['percent'];
                $progressSummary = (string)$projectProgress['summary'];
                $progressStatSummary = (string)$projectProgress['hint'];
                $budgetAmount = (float)($projectFinancials['budget_amount'] ?? 0);
                $budgetNotes = trim((string)($projectFinancials['budget_notes'] ?? ''));
                $totalCost = (float)($projectFinancials['total_cost'] ?? 0);
                $remainingBudget = $budgetAmount - $totalCost;
                $costEntryCount = (int)($projectFinancials['cost_entry_count'] ?? 0);
                $budgetUsage = $budgetAmount > 0 ? min(100, round(($totalCost / $budgetAmount) * 100)) : 0;
                $budgetHealth = pm_build_budget_health($budgetAmount, $totalCost);
                $hasBudget = $budgetAmount > 0;
                $amountPaid = (float)($projectPaymentSnapshot['amount_paid'] ?? 0);
                $paymentEntryCount = (int)($projectPaymentSnapshot['payment_entry_count'] ?? 0);
                $remainingBalance = max(0, $totalCost - $amountPaid);
                $paymentUsage = $totalCost > 0 ? min(100, round(($amountPaid / $totalCost) * 100)) : 0;
                $paymentStatus = pm_determine_payment_status($totalCost, $amountPaid);
                if ($totalCost <= 0) {
                    $paymentStatusDetail = 'No project cost logged yet';
                } elseif ($paymentStatus['status'] === 'paid') {
                    $paymentStatusDetail = 'Client is fully paid';
                } elseif ($paymentStatus['status'] === 'partial') {
                    $paymentStatusDetail = 'Remaining: ' . pm_format_money($remainingBalance);
                } else {
                    $paymentStatusDetail = 'No payment recorded yet';
                }
                $delayedTaskCount = 0;
                $ongoingTaskCount = 0;
                $pendingTaskCount = 0;
                foreach ($tasks as $taskItem) {
                    $taskStatus = strtolower(trim((string)($taskItem['status'] ?? 'pending')));
                    if ($taskStatus === 'delayed') {
                        $delayedTaskCount++;
                    } elseif ($taskStatus === 'ongoing') {
                        $ongoingTaskCount++;
                    } elseif ($taskStatus === 'pending') {
                        $pendingTaskCount++;
                    }
                }
                $pulseSignal = max(12, min(100, (int)$completionRate));
                $projectCreatedAt = trim((string)($project['created_at'] ?? ''));
                $latestTrackedEventAt = $projectCreatedAt;
                $paymentLatestAt = trim((string)($projectPaymentEntries[0]['payment_date'] ?? ''));
                $costLatestAt = trim((string)($projectCostEntries[0]['cost_date'] ?? ''));
                $deploymentLatestAt = trim((string)($deploymentHistory[0]['deployed_at'] ?? ''));
                foreach ([$paymentLatestAt, $costLatestAt, $deploymentLatestAt] as $candidateDate) {
                    if ($candidateDate !== '' && ($latestTrackedEventAt === '' || strtotime($candidateDate) > strtotime($latestTrackedEventAt))) {
                        $latestTrackedEventAt = $candidateDate;
                    }
                }
                $projectCode = trim((string)($project['project_code'] ?? ''));
                $projectPoNumber = trim((string)($project['po_number'] ?? ''));
                $projectContactPerson = trim((string)($project['contact_person'] ?? ''));
                $projectContactNumber = trim((string)($project['contact_number'] ?? ''));
                $projectEmail = trim((string)($project['project_email'] ?? ''));
                $planningStageClass = $projectCreatedAt !== '' ? 'done' : 'pending';
                $quotationStageClass = ($projectPoNumber !== '' || trim((string)($project['start_date'] ?? '')) !== '') ? 'done' : 'pending';
                // Ensure $completedTasks is defined before use
                $completedTasks = isset($project['completed_tasks']) ? (int)$project['completed_tasks'] : 0;
                if (($project['status'] ?? '') === 'completed') {
                    $executionStageClass = 'done';
                } elseif ($delayedTaskCount > 0) {
                    $executionStageClass = 'issue';
                } elseif ($ongoingTaskCount > 0 || $pendingTaskCount > 0) {
                    $executionStageClass = 'ongoing';
                } else {
                    $executionStageClass = 'pending';
                }
                if (($project['status'] ?? '') === 'completed') {
                    $inspectionStageClass = 'done';
                } elseif ($completionRate >= 80 || ($completedTasks > 0 && $ongoingTaskCount === 0 && $pendingTaskCount === 0)) {
                    $inspectionStageClass = 'ongoing';
                } else {
                    $inspectionStageClass = 'pending';
                }
                $completedStageClass = ($project['status'] ?? '') === 'completed' ? 'done' : 'pending';
                $taskPreview = array_slice($tasks, 0, 4);
                $activityFeed = [];
                if (!empty($taskPreview)) {
                    $latestTaskPreview = $taskPreview[0];
                    $activityFeed[] = [
                        'actor' => 'Engineer',
                        'text' => 'Task status tracked: ' . (string)($latestTaskPreview['task_name'] ?? 'Task'),
                        'state' => (string)($latestTaskPreview['status'] ?? 'pending'),
                    ];
                }
                if (!empty($projectCostEntries)) {
                    $activityFeed[] = [
                        'actor' => 'Admin',
                        'text' => 'Cost entry logged for ' . (string)($projectCostEntries[0]['cost_category'] ?? 'project cost'),
                        'state' => 'completed',
                    ];
                }
                if (!empty($deploymentHistory)) {
                    $activityFeed[] = [
                        'actor' => 'Foreman',
                        'text' => 'Deployment history updated for ' . (string)($deploymentHistory[0]['asset_name'] ?? 'site asset'),
                        'state' => ((int)($deploymentHistory[0]['remaining_quantity'] ?? 0) > 0) ? 'ongoing' : 'completed',
                    ];
                }
                if (empty($activityFeed)) {
                    $activityFeed[] = [
                        'actor' => 'System',
                        'text' => 'No recent tracked activity yet',
                        'state' => 'pending',
                    ];
                }
                if ($delayedTaskCount > 0) {
                    $pulseAlertTitle = 'Execution delay detected';
                    $pulseAlertCopy = $delayedTaskCount . ' delayed task(s) need recovery planning.';
                    $pulseAlertNote = 'Review assignees, blockers, and deadline slippage now.';
                } elseif ($budgetHealth['status'] === 'over' || $budgetHealth['status'] === 'warning') {
                    $pulseAlertTitle = $budgetHealth['status'] === 'over' ? 'Budget pressure is high' : 'Budget needs attention';
                    $pulseAlertCopy = $budgetHealth['label'] . ' | Total cost: ' . pm_format_money($totalCost);
                    $pulseAlertNote = $budgetAmount > 0 ? 'Budget set at ' . pm_format_money($budgetAmount) : 'Add a budget profile to improve control.';
                } elseif ($paymentStatus['status'] !== 'paid') {
                    $pulseAlertTitle = 'Payment follow-up pending';
                    $pulseAlertCopy = $paymentStatusDetail;
                    $pulseAlertNote = 'Track customer collection together with delivery progress.';
                } else {
                    $pulseAlertTitle = 'Project pulse is stable';
                    $pulseAlertCopy = 'No active budget, payment, or execution red flag right now.';
                    $pulseAlertNote = 'Keep monitoring tasks and deployment history for changes.';
                }
                ?>

                <section class="form-panel project-details-shell">
                    <div class="project-details-hero">
                        <div class="project-details-hero__main">
                            <div class="project-details-hero__headline">
                                <div class="project-details-hero__eyebrow-row">
                                                        <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="btn-secondary btn-back-projects" aria-label="Back to Projects" title="Back to Projects">&#8592;</a>

                                    <span class="project-details-hero__eyebrow">Project Overview</span>
                                    <?php if ($projectCode !== ''): ?>
                                        <span class="project-details-hero__reference"><?php echo htmlspecialchars($projectCode); ?></span>
                                    <?php endif; ?>
                                </div>
                                <h1 class="project-details-hero__title"><?php echo htmlspecialchars($project['project_name']); ?></h1>
                                <div class="status-pill-wrap">
                                    <span class="status-pill status-<?php echo htmlspecialchars($project['status']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($project['status'])); ?>
                                    </span>
                                </div>
                            </div>

                            <?php if (!empty($project['description'])): ?>
                                <div class="empty-state empty-state-solid project-details-hero__description"><?php echo nl2br(htmlspecialchars($project['description'])); ?></div>
                            <?php endif; ?>

                            <section class="project-pulse-panel" aria-label="Project pulse timeline">
                                <div class="project-pulse-box">
                                    <div class="project-pulse-box__copy">
                                        <span class="project-pulse-box__eyebrow">Project Pulse</span>
                                        <strong><?php echo htmlspecialchars((string)$projectProgress['label']); ?> | <?php echo $completionRate; ?>%</strong>
                                        <small><?php echo htmlspecialchars($progressSummary); ?></small>
                                    </div>
                                    <div class="project-pulse-bar" data-progress-width="<?php echo $pulseSignal; ?>">
                                        <span class="project-pulse-bar__fill"></span>
                                    </div>
                                    <p class="project-pulse-box__time">Last tracked update: <?php echo htmlspecialchars(pm_relative_time_label($latestTrackedEventAt)); ?></p>
                                </div>

                                <div class="project-pulse-timeline">
                                    <div class="project-pulse-stage project-pulse-stage--<?php echo htmlspecialchars($planningStageClass); ?>">
                                        <div class="project-pulse-stage__circle"><?php echo $planningStageClass === 'done' ? '✔' : '•'; ?></div>
                                        <p>Planning</p>
                                    </div>
                                    <div class="project-pulse-stage project-pulse-stage--<?php echo htmlspecialchars($quotationStageClass); ?>">
                                        <div class="project-pulse-stage__circle"><?php echo $quotationStageClass === 'done' ? '✔' : '•'; ?></div>
                                        <p>Quotation</p>
                                    </div>
                                    <div class="project-pulse-stage project-pulse-stage--<?php echo htmlspecialchars($executionStageClass); ?>">
                                        <div class="project-pulse-stage__circle"><?php echo $executionStageClass === 'done' ? '✔' : ($executionStageClass === 'ongoing' ? '⏳' : ($executionStageClass === 'issue' ? '!' : '•')); ?></div>
                                        <p>Execution</p>
                                    </div>
                                    <div class="project-pulse-stage project-pulse-stage--<?php echo htmlspecialchars($inspectionStageClass); ?>">
                                        <div class="project-pulse-stage__circle"><?php echo $inspectionStageClass === 'done' ? '✔' : ($inspectionStageClass === 'ongoing' ? '⏳' : '•'); ?></div>
                                        <p>Inspection</p>
                                    </div>
                                    <div class="project-pulse-stage project-pulse-stage--<?php echo htmlspecialchars($completedStageClass); ?>">
                                        <div class="project-pulse-stage__circle"><?php echo $completedStageClass === 'done' ? '✔' : '•'; ?></div>
                                        <p>Completed</p>
                                    </div>
                                </div>

                               

                                <div class="project-pulse-alert">
                                    <strong><?php echo htmlspecialchars($pulseAlertTitle); ?></strong>
                                    <p><?php echo htmlspecialchars($pulseAlertCopy); ?></p>
                                    <p><?php echo htmlspecialchars($pulseAlertNote); ?></p>
                                </div>
                            </section>

                            <!-- Progress Insight card removed as requested -->
                        </div>

                        <div class="project-details-stats">
                            <div class="project-details-stat">
                                <span>Progress</span>
                                <strong><?php echo $completionRate; ?>%</strong>
                                <small><?php echo htmlspecialchars($progressStatSummary); ?></small>
                            </div>
                            <div class="project-details-stat">
                                <span>Client</span>
                                <strong><?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?></strong>
                                <small>Assigned client</small>
                            </div>
                            <div class="project-details-stat">
                                <span>Assigned Team</span>
                                <strong><?php echo htmlspecialchars($assignedEngineerNames !== '' ? $assignedEngineerNames : 'Not assigned'); ?></strong>
                                <small>Current assigned team</small>
                            </div>
                            <div class="project-details-stat">
                                <span>Project Start Date</span>
                                <strong><?php echo htmlspecialchars(pm_format_date($project['project_start_date'] ?? null)); ?></strong>
                                <small>Planned work commencement</small>
                            </div>
                            <div class="project-details-stat project-details-stat--payment">
                                <span>Customer Payment</span>
                                <strong><?php echo htmlspecialchars($paymentStatus['label']); ?></strong>
                                <small><?php echo htmlspecialchars($paymentStatusDetail); ?></small>
                            </div>
                            <div class="project-details-stat project-details-stat--payment">
                                <span>Balance To Collect</span>
                                <strong><?php echo htmlspecialchars(pm_format_money($remainingBalance)); ?></strong>
                                <small><?php echo $paymentEntryCount > 0 ? htmlspecialchars($paymentEntryCount . ' payment record' . ($paymentEntryCount > 1 ? 's' : '')) : 'No payment entries yet'; ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="project-details-glance">
                        <div><strong>Project Code:</strong> <?php echo htmlspecialchars($projectCode !== '' ? $projectCode : 'Not set'); ?></div>
                        <div><strong>Client Contact Person:</strong> <?php echo htmlspecialchars($projectContactPerson !== '' ? $projectContactPerson : 'Not set'); ?></div>
                        <div><strong>Client Contact Number:</strong> <?php echo htmlspecialchars($projectContactNumber !== '' ? $projectContactNumber : 'Not set'); ?></div>
                        <div><strong>P.O Number:</strong> <?php echo htmlspecialchars($projectPoNumber !== '' ? $projectPoNumber : 'Not set'); ?></div>
                        <div><strong>P.O Date:</strong> <?php echo htmlspecialchars(pm_format_date($project['start_date'] ?? null)); ?></div>
                        <div><strong>Completed:</strong> <?php echo htmlspecialchars(pm_format_date($project['end_date'] ?? null)); ?></div>
                        <div><strong>Created:</strong> <?php echo htmlspecialchars($project['created_at'] ?? 'N/A'); ?></div>
                        <?php if ($projectEmail !== ''): ?>
                            <div><strong>Email Address:</strong> <?php echo htmlspecialchars($projectEmail); ?></div>
                        <?php endif; ?>
                        <?php if ($hasProjectAddressColumn): ?>
                            <?php if ($hasProjectSiteColumn): ?>
                                <div><strong>Project Site:</strong> <?php echo htmlspecialchars($project['project_site'] ?? 'Not set'); ?></div>
                            <?php endif; ?>
                            <div><strong>Address:</strong> <?php echo htmlspecialchars($project['project_address'] ?? 'Not set'); ?></div>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="project-details-sticky-bar">
                    <nav class="project-details-tabs" aria-label="Project detail sections">
                        <button type="button" class="project-details-tab is-active" data-project-tab="details">Edit Details</button>
                        <button type="button" class="project-details-tab" data-project-tab="finance">Budget / Cost / Payment</button>
                        <button type="button" class="project-details-tab" data-project-tab="status">Status</button>
                        <button type="button" class="project-details-tab" data-project-tab="tasks">Tasks</button>
                        <button type="button" class="project-details-tab" data-project-tab="inventory">Inventory</button>
                        <button type="button" class="project-details-tab" data-project-tab="history">History</button>
                    </nav>
                </div>

                <div class="project-details-panels">
                <section class="form-panel project-details-panel is-active" data-project-panel="details">
                    <div class="project-details-panel__header">
                        <h2 class="section-title-inline">Editable Project Details</h2>
                        <?php if (!$isCompleted): ?>
                            <div class="project-edit-actions">
                                <button type="button" class="btn-secondary project-edit-toggle project-edit-toggle--edit" data-project-edit-toggle>Edit</button>
                                <button type="submit" form="project-details-edit-form" class="btn-primary project-edit-toggle project-edit-toggle--update hidden" data-project-update-button>Update</button>
                                <button type="button" class="btn-secondary project-edit-toggle project-edit-toggle--cancel hidden" data-project-cancel-button>Cancel</button>
                            </div>
                        <?php else: ?>
                            <span class="project-readonly-badge">Read Only</span>
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" data-project-edit-form id="project-details-edit-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_project_details">
                        <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">

                        <div class="project-details-form-sections">
                            <section class="project-form-section">
                                <div class="project-form-section__header">
                                    <span class="project-form-section__eyebrow">Editable Info</span>
                                    <h3>Update contact and notes only</h3>
                                </div>
                                <div class="form-grid">
                                    <?php if ($hasContactPersonColumn): ?>
                                        <div class="input-group">
                                            <div class="field-label-row">
                                                <label for="contact_person">Client Contact Person <span class="required-indicator" aria-hidden="true">*</span></label>
                                                <button type="button" class="field-tip" aria-label="Client contact person help">
                                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                                    <span class="field-tip__bubble">Enter the main client representative for this project. Required unless the project stays in Draft.</span>
                                                </button>
                                            </div>
                                            <input type="text" id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($project['contact_person'] ?? ''); ?>" readonly data-project-editable>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($hasContactNumberColumn): ?>
                                        <div class="input-group">
                                            <div class="field-label-row">
                                                <label for="contact_number">Client Contact Number <span class="required-indicator" aria-hidden="true">*</span></label>
                                                <button type="button" class="field-tip" aria-label="Client contact number help">
                                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                                    <span class="field-tip__bubble">Enter the direct mobile or landline number for the client contact. Required unless the project stays in Draft.</span>
                                                </button>
                                            </div>
                                            <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($project['contact_number'] ?? ''); ?>" readonly data-project-editable>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($hasProjectEmailColumn): ?>
                                        <div class="input-group">
                                            <label for="project_email">Email Address <span class="optional-indicator">(Optional)</span></label>
                                            <input type="email" id="project_email" name="project_email" value="<?php echo htmlspecialchars($project['project_email'] ?? ''); ?>" readonly data-project-editable>
                                        </div>
                                    <?php endif; ?>

                                    <div class="input-group input-group-wide input-group-spaced">
                                        <label for="description">Comment <span class="optional-indicator">(Optional)</span></label>
                                        <textarea id="description" name="description" readonly data-project-editable><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </section>

                            <section class="project-form-section">
                                <div class="project-form-section__header">
                                    <span class="project-form-section__eyebrow">Timeline</span>
                                    <h3>Key project dates</h3>
                                </div>
                                <div class="form-grid">
                                    <?php if ($isCompleted): ?>
                                        <div class="input-group">
                                            <label>Completion Date</label>
                                            <div class="project-form-static-field"><?php echo htmlspecialchars(pm_format_date($project['end_date'] ?? null)); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <div class="input-group">
                                            <div class="field-label-row">
                                                <label>Project Start Date <span class="required-indicator" aria-hidden="true">*</span></label>
                                                <button type="button" class="field-tip" aria-label="Project start date help">
                                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                                    <span class="field-tip__bubble">Set the planned date when project work should begin.</span>
                                                </button>
                                            </div>
                                            <div class="project-form-static-field"><?php echo htmlspecialchars(pm_format_date($project['project_start_date'] ?? null)); ?></div>
                                            <input
                                                type="hidden"
                                                id="project_start_date"
                                                name="project_start_date"
                                                value="<?php echo htmlspecialchars($project['project_start_date'] ?? ''); ?>"
                                            >
                                        </div>
                                        <div class="input-group">
                                            <div class="field-label-row">
                                                <label for="estimated_completion_date">Estimated Completion Date <span class="required-indicator" aria-hidden="true">*</span></label>
                                                <button type="button" class="field-tip" aria-label="Estimated completion date help">
                                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                                    <span class="field-tip__bubble">Set the expected completion date. This cannot be earlier than the Project Start Date.</span>
                                                </button>
                                            </div>
                                            <input
                                                type="date"
                                                id="estimated_completion_date"
                                                name="estimated_completion_date"
                                                value="<?php echo htmlspecialchars($project['estimated_completion_date'] ?? ''); ?>"
                                                readonly
                                                data-project-editable
                                            >
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </section>

                            <section class="project-form-section">
                                <div class="project-form-section__header">
                                    <span class="project-form-section__eyebrow">Assignment</span>
                                    <h3>People responsible</h3>
                                </div>
                                <div class="form-grid">
                                    <div class="input-group input-group-wide">
                                        <div class="field-label-row">
                                            <label for="engineer_ids_picker">Assigned Team Member/s <span class="required-indicator" aria-hidden="true">*</span></label>
                                            <button type="button" class="field-tip" aria-label="Assigned team members help">
                                                <span class="field-tip__icon" aria-hidden="true">i</span>
                                                <span class="field-tip__bubble">Pick an engineer or foreman from the dropdown, then press the plus button to add. Press the same button again to remove the selected team member. Add one or more people depending on the project workload.</span>
                                            </button>
                                        </div>
                                        <div class="engineer-picker" data-engineer-picker>
                                            <div class="engineer-picker__controls">
                                                <select id="engineer_ids_picker" class="engineer-picker__select" data-engineer-select data-project-editable disabled>
                                                    <option value="">Select engineer or foreman</option>
                                                    <?php foreach ($engineers as $engineer): ?>
                                                        <option value="<?php echo (int)$engineer['id']; ?>"><?php echo htmlspecialchars($engineer['full_name'] . ' (' . ucfirst((string)($engineer['role'] ?? 'team')) . ')'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="engineer-picker__toggle" data-engineer-toggle data-project-editable-control aria-label="Add selected team member" disabled>
                                                    <span class="engineer-picker__toggle-icon" aria-hidden="true">+</span>
                                                    <span class="engineer-picker__toggle-text">Add</span>
                                                </button>
                                            </div>
                                            <div class="engineer-picker__selected" data-engineer-selected>
                                                <?php if ($assignedEngineerIds !== []): ?>
                                                    <?php foreach ($engineers as $engineer): ?>
                                                        <?php if (in_array((int)$engineer['id'],    $assignedEngineerIds, true)): ?>
                                                            <button
                                                                type="button"
                                                                class="engineer-chip"
                                                                data-engineer-chip
                                                                data-engineer-id="<?php echo (int)$engineer['id']; ?>"
                                                                data-engineer-name="<?php echo htmlspecialchars($engineer['full_name'], ENT_QUOTES); ?>"
                                                                aria-pressed="true"
                                                            >
                                                                <span><?php echo htmlspecialchars($engineer['full_name']); ?></span>
                                                                <span class="engineer-chip__remove" aria-hidden="true">&times;</span>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="engineer-picker__empty">No engineers added yet.</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="engineer-picker__inputs" data-engineer-inputs>
                                                <?php foreach ($assignedEngineerIds as $engineerId): ?>
                                                    <input type="hidden" name="engineer_ids[]" value="<?php echo (int)$engineerId; ?>" data-engineer-input>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>

                    </form>
                </section>

                <section class="form-panel project-details-panel" data-project-panel="finance">
                    <div class="project-details-panel__header">
                        <h2 class="section-title-inline">Budget / Cost / Payment Management</h2>
                    </div>


                  

                    <section class="budget-panel budget-panel--<?php echo htmlspecialchars($budgetHealth['status']); ?>">
                        <div class="budget-panel__header">
                            <div>
                                <h4>Budget Overview</h4>
                                <span class="budget-health budget-health--<?php echo htmlspecialchars($budgetHealth['status']); ?>">
                                    <?php echo htmlspecialchars($budgetHealth['label']); ?>
                                </span>
                            </div>
                            <strong><?php echo htmlspecialchars(pm_format_money($remainingBudget)); ?></strong>
                        </div>

                        <div class="budget-stats">
                            <div>
                                <span>Budget</span>
                                <strong><?php echo htmlspecialchars(pm_format_money($budgetAmount)); ?></strong>
                            </div>
                            <div>
                                <span>Actual</span>
                                <strong><?php echo htmlspecialchars(pm_format_money($totalCost)); ?></strong>
                            </div>
                            <div>
                                <span>Entries</span>
                                <strong><?php echo $costEntryCount; ?></strong>
                            </div>
                        </div>

                        <div class="budget-progress">
                            <div class="budget-progress__track">
                                <span class="budget-progress__fill budget-progress__fill--<?php echo htmlspecialchars($budgetHealth['status']); ?>" data-fill-width="<?php echo $budgetUsage; ?>"></span>
                            </div>
                            <small><?php echo $budgetAmount > 0 ? $budgetUsage . '% spent' : 'Budget is optional. Actual costs can be logged anytime.'; ?></small>
                        </div>

                        <?php if (!$hasBudget): ?>
                            <div class="alert alert-warning">
                                No budget is set for this project. You can still move it to Ongoing, log actual expenses, and add payments after costs exist.
                            </div>
                        <?php endif; ?>

                        <?php if ($budgetNotes !== ''): ?>
                            <div class="budget-notes"><?php echo nl2br(htmlspecialchars($budgetNotes)); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($projectCostEntries)): ?>
                            <div class="budget-ledger">
                                <?php foreach ($projectCostEntries as $costEntry): ?>
                                    <div class="budget-ledger__item">
                                        <div>
                                            <strong><?php echo htmlspecialchars($costEntry['cost_category'] ?? 'Cost'); ?></strong>
                                            <span><?php echo htmlspecialchars($costEntry['cost_date'] ?? ''); ?><?php echo !empty($costEntry['created_by_name']) ? ' - ' . htmlspecialchars($costEntry['created_by_name']) : ''; ?></span>
                                            <?php if (!empty($costEntry['description'])): ?>
                                                <small><?php echo htmlspecialchars($costEntry['description']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <strong><?php echo htmlspecialchars(pm_format_money((float)($costEntry['amount'] ?? 0))); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">No cost entries yet.</div>
                        <?php endif; ?>
                    </section>

                    <section class="budget-panel payment-panel payment-panel--<?php echo htmlspecialchars($paymentStatus['status']); ?>">
                        <div class="budget-panel__header">
                            <div>
                                <h4>Payment Summary</h4>
                                <span class="payment-status payment-status--<?php echo htmlspecialchars($paymentStatus['status']); ?>">
                                    <?php echo htmlspecialchars($paymentStatus['label']); ?>
                                </span>
                            </div>
                            <strong><?php echo htmlspecialchars(pm_format_money($remainingBalance)); ?></strong>
                        </div>

                        <div class="budget-stats">
                            <div>
                                <span>Total Cost</span>
                                <strong><?php echo htmlspecialchars(pm_format_money($totalCost)); ?></strong>
                            </div>
                            <div>
                                <span>Amount Paid</span>
                                <strong><?php echo htmlspecialchars(pm_format_money($amountPaid)); ?></strong>
                            </div>
                            <div>
                                <span>Remaining Balance</span>
                                <strong><?php echo htmlspecialchars(pm_format_money($remainingBalance)); ?></strong>
                            </div>
                            <div>
                                <span>Payments</span>
                                <strong><?php echo $paymentEntryCount; ?></strong>
                            </div>
                        </div>

                        <div class="budget-progress">
                            <div class="budget-progress__track">
                                <span class="budget-progress__fill payment-progress__fill payment-progress__fill--<?php echo htmlspecialchars($paymentStatus['status']); ?>" data-fill-width="<?php echo $paymentUsage; ?>"></span>
                            </div>
                            <small><?php echo $totalCost > 0 ? $paymentUsage . '% paid' : 'Add a project cost first to start tracking payments'; ?></small>
                        </div>

                        <?php if (!empty($projectPaymentEntries)): ?>
                            <div class="budget-ledger">
                                <?php foreach ($projectPaymentEntries as $paymentEntry): ?>
                                    <div class="budget-ledger__item">
                                        <div>
                                            <strong>Payment Received</strong>
                                            <span><?php echo htmlspecialchars($paymentEntry['payment_date'] ?? ''); ?><?php echo !empty($paymentEntry['created_by_name']) ? ' - ' . htmlspecialchars($paymentEntry['created_by_name']) : ''; ?></span>
                                            <?php if (!empty($paymentEntry['notes'])): ?>
                                                <small><?php echo htmlspecialchars($paymentEntry['notes']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <strong><?php echo htmlspecialchars(pm_format_money((float)($paymentEntry['amount'] ?? 0))); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">No payments recorded yet.</div>
                        <?php endif; ?>
                    </section>

                    <div class="project-finance-forms">
                        <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="mini-form project-finance-form" data-inline-edit-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_project_budget">
                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                            <div class="project-inline-form__header">
                                <h4 class="subheading">Update Budget</h4>
                                <div class="project-inline-form__actions">
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--edit" data-inline-edit>Edit</button>
                                    <button type="submit" class="btn-primary project-inline-action project-inline-action--update hidden" data-inline-update>Update</button>
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--cancel hidden" data-inline-cancel>Cancel</button>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="budget_amount">Budget Amount</label>
                                    <input type="number" id="budget_amount" name="budget_amount" min="0" step="0.01" value="<?php echo $hasBudget ? htmlspecialchars(number_format($budgetAmount, 2, '.', '')) : ''; ?>" placeholder="Optional" data-inline-editable>
                                </div>
                                <div class="input-group">
                                    <label for="budget_notes">Notes</label>
                                    <input type="text" id="budget_notes" name="budget_notes" value="<?php echo htmlspecialchars($budgetNotes); ?>" placeholder="Approved budget notes" data-inline-editable>
                                </div>
                            </div>
                        </form>

                        <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="mini-form project-finance-form" data-inline-edit-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_project_cost_entry">
                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                            <div class="project-inline-form__header">
                                <h4 class="subheading">Log Cost</h4>
                                <div class="project-inline-form__actions">
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--edit" data-inline-edit>Edit</button>
                                    <button type="submit" class="btn-primary project-inline-action project-inline-action--update hidden" data-inline-update>Update</button>
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--cancel hidden" data-inline-cancel>Cancel</button>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="cost_date">Date</label>
                                    <input type="date" id="cost_date" name="cost_date" value="<?php echo htmlspecialchars($todayDate); ?>" required data-inline-editable>
                                </div>
                                <div class="input-group">
                                    <label for="cost_category">Category</label>
                                    <input type="text" id="cost_category" name="cost_category" placeholder="Labor, Materials, Permit" required data-inline-editable>
                                </div>
                                <div class="input-group">
                                    <label for="cost_amount">Amount</label>
                                    <input type="number" id="cost_amount" name="cost_amount" min="0.01" step="0.01" placeholder="0.00" required data-inline-editable>
                                </div>
                                <div class="input-group">
                                    <label for="cost_description">Description</label>
                                    <input type="text" id="cost_description" name="cost_description" placeholder="Supplier, PO, or work package" data-inline-editable>
                                </div>
                            </div>
                        </form>

                        <?php if ($totalCost <= 0): ?>
                            <div class="empty-state">Add a project cost first before recording payments.</div>
                        <?php elseif ($remainingBalance <= 0): ?>
                            <div class="empty-state">This project is already fully paid.</div>
                        <?php else: ?>
                        <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="mini-form project-finance-form" data-inline-edit-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="add_project_payment">
                                <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                                <div class="project-inline-form__header">
                                    <h4 class="subheading">Add Payment</h4>
                                    <div class="project-inline-form__actions">
                                        <button type="button" class="btn-secondary project-inline-action project-inline-action--edit" data-inline-edit>Edit</button>
                                        <button type="submit" class="btn-primary project-inline-action project-inline-action--update hidden" data-inline-update>Update</button>
                                        <button type="button" class="btn-secondary project-inline-action project-inline-action--cancel hidden" data-inline-cancel>Cancel</button>
                                    </div>
                                </div>
                                <div class="form-grid">
                                    <div class="input-group">
                                        <label for="payment_date">Payment Date</label>
                                        <input type="date" id="payment_date" name="payment_date" value="<?php echo htmlspecialchars($todayDate); ?>" required data-inline-editable>
                                    </div>
                                    <div class="input-group">
                                        <label for="payment_amount">Amount</label>
                                        <input type="number" id="payment_amount" name="payment_amount" min="0.01" step="0.01" max="<?php echo htmlspecialchars(number_format($remainingBalance, 2, '.', '')); ?>" placeholder="0.00" required data-inline-editable>
                                    </div>
                                    <div class="input-group input-group-wide">
                                        <label for="payment_notes">Notes</label>
                                        <input type="text" id="payment_notes" name="payment_notes" placeholder="Receipt, reference, or short note" data-inline-editable>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="form-panel project-details-panel" data-project-panel="status">
                    <div class="project-details-panel__header">
                        <h2 class="section-title-inline">Update Status</h2>
                    </div>
                    <?php if (!$isCompleted): ?>
                    <?php endif; ?>
                    <?php if ($isCompleted): ?>
                        <div class="lock-note">This project is locked because it is already completed.</div>
                        <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="mini-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="reopen_project">
                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                            <div class="form-actions">
                                <button type="submit" class="btn-secondary">Reopen Project</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <?php if (!$hasBudget): ?>
                            <div class="alert alert-warning">Moving this project to Ongoing without a budget is allowed. Set a budget later if you want planned versus actual tracking.</div>
                        <?php endif; ?>
                        <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="mini-form" data-inline-edit-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="update_project_status">
                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                            <div class="project-inline-form__header">
                                <h4 class="subheading">Primary Status</h4>
                                <div class="project-inline-form__actions">
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--edit" data-inline-edit>Edit</button>
                                    <button type="submit" class="btn-primary project-inline-action project-inline-action--update hidden" data-inline-update>Update</button>
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--cancel hidden" data-inline-cancel>Cancel</button>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="status">Status</label>
                                    <select id="status" name="status" required data-inline-editable>
                                        <?php foreach ($statusOptions as $statusOption): ?>
                                            <?php if ($statusOption === 'pending' && !in_array(($project['status'] ?? ''), ['pending', 'draft'], true)) { continue; } ?>
                                            <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo $project['status'] === $statusOption ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(ucfirst($statusOption)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </form>

                        <?php if ($supportsCancelledStatus || $supportsArchivedStatus): ?>
                            <div class="status-quick-actions">
                                <?php if ($supportsCancelledStatus && ($project['status'] ?? '') !== 'cancelled'): ?>
                                    <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="inline-form" data-confirm-action="Cancel this project? This is for projects that will not continue.">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="update_project_status">
                                        <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                        <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                                        <input type="hidden" name="status" value="cancelled">
                                        <button type="submit" class="btn-secondary btn-status-danger">Mark as Cancelled</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($supportsArchivedStatus && ($project['status'] ?? '') !== 'archived'): ?>
                                    <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="inline-form" data-confirm-action="Archive this project? It will stay in history but should no longer be active.">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="update_project_status">
                                        <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                        <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                                        <input type="hidden" name="status" value="archived">
                                        <button type="submit" class="btn-secondary btn-status-neutral">Archive Project</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="form-panel project-details-panel" data-project-panel="tasks">
                    <div class="project-details-panel__header">
                        <h2 class="section-title-inline">Tasks</h2>
                    </div>

                    <?php if (empty($tasks)): ?>
                        <div class="empty-state">No tasks yet for this project.</div>
                    <?php else: ?>
                        <div class="task-list">
                            <?php foreach ($tasks as $task): ?>
                                <div class="task-item">
                                    <strong><?php echo htmlspecialchars($task['task_name']); ?></strong>
                                    <span>Status: <?php echo htmlspecialchars(ucfirst($task['status'])); ?></span>
                                    <span>Assigned To: <?php echo htmlspecialchars($task['assignee_name'] ?? 'N/A'); ?></span>
                                    <span>Deadline: <?php echo htmlspecialchars($task['deadline'] ?? 'No deadline'); ?></span>
                                    <span>Description: <?php echo htmlspecialchars($task['description'] ?: 'None'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isCompleted): ?>
                        <div class="empty-state">Task creation is disabled while this project is completed.</div>
                    <?php elseif ($isDraft): ?>
                        <div class="empty-state">Task creation is disabled while this project is still in draft.</div>
                    <?php else: ?>
                        <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="mini-form" data-inline-edit-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_task">
                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">

                            <div class="project-inline-form__header">
                                <h4 class="subheading">Add Task</h4>
                                <div class="project-inline-form__actions">
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--edit" data-inline-edit>Edit</button>
                                    <button type="submit" class="btn-primary project-inline-action project-inline-action--update hidden" data-inline-update>Update</button>
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--cancel hidden" data-inline-cancel>Cancel</button>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="task_name">Task Name</label>
                                    <input type="text" id="task_name" name="task_name" required data-inline-editable>
                                </div>

                                <div class="input-group">
                                    <label for="assigned_to">Assign To</label>
                                    <select id="assigned_to" name="assigned_to" required data-inline-editable>
                                        <option value="">Select engineer</option>
                                        <?php foreach ($engineers as $engineer): ?>
                                            <option value="<?php echo (int)$engineer['id']; ?>" <?php echo $defaultTaskEngineerId === (int)$engineer['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($engineer['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="input-group">
                                    <label for="deadline">Deadline</label>
                                    <input type="date" id="deadline" name="deadline" min="<?php echo htmlspecialchars($todayDate); ?>" data-inline-editable>
                                </div>
                            </div>

                            <div class="input-group">
                                <label for="task_description">Task Description</label>
                                <textarea id="task_description" name="task_description" placeholder="Task details" data-inline-editable></textarea>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="form-panel project-details-panel" data-project-panel="inventory">
                    <div class="project-details-panel__header">
                        <h2 class="section-title-inline">Deployed Inventory</h2>
                    </div>

                    <?php if (empty($activeDeployments)): ?>
                        <div class="empty-state">No inventory deployed to this project yet.</div>
                    <?php else: ?>
                        <div class="task-list">
                            <?php foreach ($activeDeployments as $deployment): ?>
                                <div class="task-item">
                                    <strong><?php echo htmlspecialchars($deployment['asset_name']); ?></strong>
                                    <span>Type: <?php echo htmlspecialchars($deployment['asset_type'] ?: 'N/A'); ?></span>
                                    <span>Serial: <?php echo htmlspecialchars($deployment['serial_number'] ?: 'N/A'); ?></span>
                                    <span>Deployed: <?php echo (int)$deployment['quantity']; ?></span>
                                    <span>Returned: <?php echo (int)$deployment['returned_quantity']; ?></span>
                                    <span>Remaining: <?php echo (int)$deployment['remaining_quantity']; ?></span>
                                    <span>Deployed By: <?php echo htmlspecialchars($deployment['deployed_by_name'] ?? 'N/A'); ?></span>
                                    <span>Unit Codes: <?php echo htmlspecialchars((string)($deployment['unit_codes'] ?? 'Auto-assigned')); ?></span>
                                    <span>Deployed At: <?php echo htmlspecialchars($deployment['deployed_at']); ?></span>
                                    <span>Notes: <?php echo htmlspecialchars($deployment['notes'] ?: 'None'); ?></span>
                                    <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="mini-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="return_project_inventory">
                                        <input type="hidden" name="deployment_id" value="<?php echo (int)$deployment['id']; ?>">
                                        <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                                        <div class="form-grid">
                                            <div class="input-group">
                                                <label for="return_quantity-<?php echo (int)$deployment['id']; ?>">Return Qty</label>
                                                <input type="number" id="return_quantity-<?php echo (int)$deployment['id']; ?>" name="return_quantity" min="1" max="<?php echo (int)$deployment['remaining_quantity']; ?>" required>
                                            </div>
                                            <div class="input-group">
                                                <label for="return_notes-<?php echo (int)$deployment['id']; ?>">Return Notes</label>
                                                <input type="text" id="return_notes-<?php echo (int)$deployment['id']; ?>" name="return_notes" placeholder="Optional note">
                                            </div>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" class="btn-secondary">Return Inventory</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isCompleted): ?>
                        <div class="empty-state">Inventory deployment is disabled while this project is completed.</div>
                    <?php elseif ($isDraft): ?>
                        <div class="empty-state">Inventory deployment is disabled while this project is still in draft.</div>
                    <?php elseif (empty($availableInventory)): ?>
                        <div class="empty-state">No available inventory to deploy right now.</div>
                    <?php else: ?>
                        <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="mini-form" data-inline-edit-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="deploy_inventory_to_project">
                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($detailsPath); ?>">
                            <div class="project-inline-form__header">
                                <h4 class="subheading">Deploy Inventory</h4>
                                <div class="project-inline-form__actions">
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--edit" data-inline-edit>Edit</button>
                                    <button type="submit" class="btn-primary project-inline-action project-inline-action--update hidden" data-inline-update>Update</button>
                                    <button type="button" class="btn-secondary project-inline-action project-inline-action--cancel hidden" data-inline-cancel>Cancel</button>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="inventory_id">Inventory Item</label>
                                    <select id="inventory_id" name="inventory_id" required data-inline-editable>
                                        <option value="">Select inventory item</option>
                                        <?php foreach ($availableInventory as $inventoryItem): ?>
                                            <option value="<?php echo (int)$inventoryItem['id']; ?>">
                                                <?php
                                                $inventoryLabel = $inventoryItem['asset_name'];
                                                if (!empty($inventoryItem['asset_type'])) {
                                                    $inventoryLabel .= ' - ' . $inventoryItem['asset_type'];
                                                }
                                                if (!empty($inventoryItem['serial_number'])) {
                                                    $inventoryLabel .= ' (SN: ' . $inventoryItem['serial_number'] . ')';
                                                }
                                                $inventoryLabel .= ' | Available: ' . (int)$inventoryItem['quantity'];
                                                echo htmlspecialchars($inventoryLabel);
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="input-group">
                                    <label for="deployment_quantity">Quantity</label>
                                    <input type="number" id="deployment_quantity" name="deployment_quantity" min="1" step="1" required data-inline-editable>
                                </div>

                                <div class="input-group">
                                    <label for="deployment_notes">Notes</label>
                                    <input type="text" id="deployment_notes" name="deployment_notes" placeholder="Optional deployment note" data-inline-editable>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="form-panel project-details-panel" data-project-panel="history">
                    <div class="project-details-panel__header">
                        <h2 class="section-title-inline">Deployment History</h2>
                        <span class="project-readonly-badge">Read Only</span>
                    </div>

                    <?php if (empty($deploymentHistory)): ?>
                        <div class="empty-state">No deployment history for this project yet.</div>
                    <?php else: ?>
                        <div class="task-list">
                            <?php foreach ($deploymentHistory as $historyItem): ?>
                                <div class="task-item">
                                    <strong><?php echo htmlspecialchars($historyItem['asset_name']); ?></strong>
                                    <span>Type: <?php echo htmlspecialchars($historyItem['asset_type'] ?: 'N/A'); ?></span>
                                    <span>Serial: <?php echo htmlspecialchars($historyItem['serial_number'] ?: 'N/A'); ?></span>
                                    <span>Deployed: <?php echo (int)$historyItem['quantity']; ?></span>
                                    <span>Returned: <?php echo (int)$historyItem['returned_quantity']; ?></span>
                                    <span>Remaining: <?php echo (int)$historyItem['remaining_quantity']; ?></span>
                                    <span>Status: <?php echo (int)$historyItem['remaining_quantity'] > 0 ? 'Active' : 'Closed'; ?></span>
                                    <span>Deployed By: <?php echo htmlspecialchars($historyItem['deployed_by_name'] ?? 'N/A'); ?></span>
                                    <span>Unit Codes: <?php echo htmlspecialchars((string)($historyItem['unit_codes'] ?? 'Auto-assigned')); ?></span>
                                    <span>Deployed At: <?php echo htmlspecialchars($historyItem['deployed_at']); ?></span>
                                    <span>Last Return: <?php echo htmlspecialchars($historyItem['last_returned_at'] ?? 'None yet'); ?></span>
                                    <span>Notes: <?php echo htmlspecialchars($historyItem['notes'] ?: 'None'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="../js/super_admin_dashboard.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-confirm-action]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const message = form.getAttribute('data-confirm-action') || 'Are you sure?';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    const tabs = Array.from(document.querySelectorAll('[data-project-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-project-panel]'));

    function setActiveProjectTab(tabName) {
        tabs.forEach(function (tab) {
            const isActive = tab.getAttribute('data-project-tab') === tabName;
            tab.classList.toggle('is-active', isActive);
        });

        panels.forEach(function (panel) {
            const isActive = panel.getAttribute('data-project-panel') === tabName;
            panel.classList.toggle('is-active', isActive);
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const tabName = tab.getAttribute('data-project-tab') || 'details';
            setActiveProjectTab(tabName);
        });
    });

    const editForm = document.querySelector('[data-project-edit-form]');
    const editToggle = document.querySelector('[data-project-edit-toggle]');
    const updateButton = document.querySelector('[data-project-update-button]');
    const cancelButton = document.querySelector('[data-project-cancel-button]');
    const editableFields = Array.from(document.querySelectorAll('[data-project-editable]'));
    const editableControls = Array.from(document.querySelectorAll('[data-project-editable-control]'));
    const overviewPanel = document.querySelector('[data-project-panel="details"]');

    if (editForm && editToggle && updateButton && cancelButton && editableFields.length > 0) {
        const fieldSnapshots = editableFields.map(function (field) {
            const snapshot = {
                field: field,
                value: field.value,
            };

            if (field.tagName === 'SELECT' && field.multiple) {
                snapshot.values = Array.from(field.selectedOptions).map(function (option) {
                    return option.value;
                });
            }

            return {
                field: snapshot.field,
                value: snapshot.value,
                values: snapshot.values || null,
            };
        });

        const setEditMode = function (isEditing) {
            editableFields.forEach(function (field) {
                if (field.tagName === 'SELECT') {
                    field.disabled = !isEditing;
                } else {
                    if (isEditing) {
                        field.removeAttribute('readonly');
                    } else {
                        field.setAttribute('readonly', 'readonly');
                    }
                }
            });

            editableControls.forEach(function (control) {
                control.disabled = !isEditing;
            });

            editToggle.classList.toggle('hidden', isEditing);
            updateButton.classList.toggle('hidden', !isEditing);
            cancelButton.classList.toggle('hidden', !isEditing);

            if (overviewPanel) {
                overviewPanel.classList.toggle('is-editing', isEditing);
            }
        };

        setEditMode(false);

        editToggle.addEventListener('click', function () {
            setEditMode(true);
            const firstField = editableFields.find(function (field) {
                return !field.disabled;
            });

            if (firstField && typeof firstField.focus === 'function') {
                firstField.focus();
            }
        });

        cancelButton.addEventListener('click', function () {
            fieldSnapshots.forEach(function (snapshot) {
                if (snapshot.field.tagName === 'SELECT' && snapshot.field.multiple && Array.isArray(snapshot.values)) {
                    Array.from(snapshot.field.options).forEach(function (option) {
                        option.selected = snapshot.values.indexOf(option.value) !== -1;
                    });
                    return;
                }

                snapshot.field.value = snapshot.value;
            });
            setEditMode(false);
        });
    }

    document.querySelectorAll('[data-engineer-picker]').forEach(function (picker) {
        const engineerSelect = picker.querySelector('[data-engineer-select]');
        const toggleButton = picker.querySelector('[data-engineer-toggle]');
        const toggleButtonIcon = picker.querySelector('.engineer-picker__toggle-icon');
        const toggleButtonText = picker.querySelector('.engineer-picker__toggle-text');
        const selectedContainer = picker.querySelector('[data-engineer-selected]');
        const inputsContainer = picker.querySelector('[data-engineer-inputs]');

        if (!engineerSelect || !toggleButton || !selectedContainer || !inputsContainer) {
            return;
        }

        function getSelectedEngineerIds() {
            return Array.from(inputsContainer.querySelectorAll('[data-engineer-input]')).map(function (input) {
                return String(input.value);
            });
        }

        function syncValidation() {
            if (getSelectedEngineerIds().length > 0) {
                engineerSelect.setCustomValidity('');
            } else {
                engineerSelect.setCustomValidity('Assigned engineer is required.');
            }
        }

        function syncToggleButton() {
            const selectedValue = engineerSelect.value;
            const hasSelectedValue = selectedValue !== '';
            const isAlreadyAdded = hasSelectedValue && getSelectedEngineerIds().includes(selectedValue);

            toggleButton.disabled = toggleButton.hasAttribute('data-project-editable-control') && toggleButton.closest('[data-project-panel="overview"]')?.classList.contains('is-editing') === false
                ? true
                : !hasSelectedValue;
            toggleButton.classList.toggle('is-remove', Boolean(isAlreadyAdded));
            toggleButton.setAttribute('aria-label', isAlreadyAdded ? 'Remove selected engineer' : 'Add selected engineer');
            toggleButtonIcon.textContent = isAlreadyAdded ? '\u2212' : '+';
            toggleButtonText.textContent = isAlreadyAdded ? 'Remove' : 'Add';
        }

        function renderEmptyState() {
            const hasChip = selectedContainer.querySelector('[data-engineer-chip]');
            selectedContainer.classList.toggle('is-empty', !hasChip);

            if (!hasChip) {
                selectedContainer.innerHTML = '<span class="engineer-picker__empty">No engineers added yet.</span>';
            }
        }

        function addEngineer(engineerId, engineerName) {
            const existingInput = inputsContainer.querySelector('[data-engineer-input][value="' + CSS.escape(engineerId) + '"]');

            if (existingInput) {
                return;
            }

            const emptyState = selectedContainer.querySelector('.engineer-picker__empty');
            if (emptyState) {
                emptyState.remove();
            }

            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'engineer-chip';
            chip.setAttribute('data-engineer-chip', '');
            chip.setAttribute('data-engineer-id', engineerId);
            chip.setAttribute('data-engineer-name', engineerName);
            chip.setAttribute('aria-pressed', 'true');
            chip.innerHTML = '<span></span><span class="engineer-chip__remove" aria-hidden="true">&times;</span>';
            chip.querySelector('span').textContent = engineerName;
            selectedContainer.appendChild(chip);

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'engineer_ids[]';
            hiddenInput.value = engineerId;
            hiddenInput.setAttribute('data-engineer-input', '');
            inputsContainer.appendChild(hiddenInput);

            syncValidation();
            syncToggleButton();
        }

        function removeEngineer(engineerId) {
            const hiddenInput = inputsContainer.querySelector('[data-engineer-input][value="' + CSS.escape(engineerId) + '"]');
            const chip = selectedContainer.querySelector('[data-engineer-chip][data-engineer-id="' + CSS.escape(engineerId) + '"]');

            if (hiddenInput) {
                hiddenInput.remove();
            }

            if (chip) {
                chip.remove();
            }

            renderEmptyState();
            syncValidation();
            syncToggleButton();
        }

        engineerSelect.addEventListener('change', function () {
            engineerSelect.setCustomValidity('');
            syncToggleButton();
        });

        toggleButton.addEventListener('click', function () {
            const engineerId = engineerSelect.value;

            if (engineerId === '') {
                engineerSelect.setCustomValidity('Select an engineer first.');
                engineerSelect.reportValidity();
                return;
            }

            const selectedOption = engineerSelect.options[engineerSelect.selectedIndex];
            if (!selectedOption) {
                return;
            }

            if (getSelectedEngineerIds().includes(engineerId)) {
                removeEngineer(engineerId);
            } else {
                addEngineer(engineerId, selectedOption.text);
            }
        });

        selectedContainer.addEventListener('click', function (event) {
            const chip = event.target.closest('[data-engineer-chip]');

            if (!chip) {
                return;
            }

            const engineerId = chip.getAttribute('data-engineer-id') || '';
            if (engineerId === '') {
                return;
            }

            engineerSelect.value = engineerId;
            removeEngineer(engineerId);
        });

        renderEmptyState();
        syncValidation();
        syncToggleButton();
    });

    document.querySelectorAll('[data-inline-edit-form]').forEach(function (form) {
        const editButton = form.querySelector('[data-inline-edit]');
        const updateInlineButton = form.querySelector('[data-inline-update]');
        const cancelInlineButton = form.querySelector('[data-inline-cancel]');
        const fields = Array.from(form.querySelectorAll('[data-inline-editable]'));

        if (!editButton || !updateInlineButton || !cancelInlineButton || fields.length === 0) {
            return;
        }

        const fieldSnapshots = fields.map(function (field) {
            return {
                field: field,
                value: field.value,
            };
        });

        const setInlineEditMode = function (isEditing) {
            fields.forEach(function (field) {
                field.disabled = !isEditing;
            });

            form.classList.toggle('is-editing', isEditing);
            editButton.classList.toggle('hidden', isEditing);
            updateInlineButton.classList.toggle('hidden', !isEditing);
            cancelInlineButton.classList.toggle('hidden', !isEditing);
        };

        setInlineEditMode(false);

        editButton.addEventListener('click', function () {
            setInlineEditMode(true);
            const firstField = fields.find(function (field) {
                return !field.disabled;
            });

            if (firstField && typeof firstField.focus === 'function') {
                firstField.focus();
            }
        });

        cancelInlineButton.addEventListener('click', function () {
            fieldSnapshots.forEach(function (snapshot) {
                snapshot.field.value = snapshot.value;
            });
            setInlineEditMode(false);
        });
    });
});
</script>
</body>
</html>
