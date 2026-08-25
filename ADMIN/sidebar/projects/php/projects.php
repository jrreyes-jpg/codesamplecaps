<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../../config/asset_unit_helpers.php';
require_once __DIR__ . '/../../../../config/project_progress.php';
require_once __DIR__ . '/../../../services/project_service.php';
require_once __DIR__ . '/../../../php/projects/project_search_support.php';

require_role('admin');


function fetch_user_for_trash(mysqli $conn, int $userId): ?array {
    $stmt = $conn->prepare(
        'SELECT id, full_name, email, phone, role, status, deleted_at
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function ensure_deleted_users_archive_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS deleted_users_archive (
            id INT(11) NOT NULL AUTO_INCREMENT,
            original_user_id INT(11) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            role VARCHAR(30) NOT NULL,
            status VARCHAR(20) NOT NULL,
            deleted_by INT(11) DEFAULT NULL,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payload_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_deleted_users_archive_original (original_user_id),
            KEY idx_deleted_users_archive_deleted_by (deleted_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function ensure_user_soft_delete_columns(mysqli $conn): void {
    if (!table_has_column($conn, 'users', 'deleted_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER status");
    }

    if (!table_has_column($conn, 'users', 'deleted_by')) {
        $conn->query("ALTER TABLE users ADD COLUMN deleted_by INT(11) DEFAULT NULL AFTER deleted_at");
    }

    if (!table_has_column($conn, 'users', 'restored_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN restored_at DATETIME DEFAULT NULL AFTER deleted_by");
    }

    if (!table_has_column($conn, 'users', 'restored_by')) {
        $conn->query("ALTER TABLE users ADD COLUMN restored_by INT(11) DEFAULT NULL AFTER restored_at");
    }

    $indexResult = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_deleted_at'");
    if (!$indexResult || (int)$indexResult->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD INDEX idx_users_deleted_at (deleted_at, role, status)");
    }
}

function ensure_project_inventory_deployments_table(mysqli $conn): void {
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

function ensure_project_inventory_return_logs_table(mysqli $conn): void {
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

function ensure_project_budget_profiles_table(mysqli $conn): void {
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

function ensure_project_cost_entries_table(mysqli $conn): void {
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

function ensure_project_payments_table(mysqli $conn): void {
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

function ensure_project_address_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'project_address')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_address TEXT DEFAULT NULL AFTER client_id");
    }
}

function ensure_project_site_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'project_site')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_site VARCHAR(190) DEFAULT NULL AFTER client_id");
    }
}

function ensure_project_email_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'project_email')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_email VARCHAR(190) DEFAULT NULL AFTER project_address");
    }
}

function ensure_project_additional_info_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'additional_info_json')) {
        $conn->query("ALTER TABLE projects ADD COLUMN additional_info_json LONGTEXT DEFAULT NULL AFTER project_email");
    }
}

function ensure_project_source_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'project_source')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_source VARCHAR(40) NOT NULL DEFAULT 'walk_in' AFTER project_code");
    }
}

function ensure_project_code_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'project_code')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_code VARCHAR(80) DEFAULT NULL AFTER project_email");
    }
}

function ensure_project_po_number_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'po_number')) {
        $conn->query("ALTER TABLE projects ADD COLUMN po_number VARCHAR(80) DEFAULT NULL AFTER project_code");
    }
}

function ensure_project_contact_person_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'contact_person')) {
        $conn->query("ALTER TABLE projects ADD COLUMN contact_person VARCHAR(190) DEFAULT NULL AFTER client_id");
    }
}

function ensure_project_contact_number_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'contact_number')) {
        $conn->query("ALTER TABLE projects ADD COLUMN contact_number VARCHAR(40) DEFAULT NULL AFTER contact_person");
    }
}

function ensure_project_start_date_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'project_start_date')) {
        $conn->query("ALTER TABLE projects ADD COLUMN project_start_date DATE DEFAULT NULL AFTER start_date");
    }
}

function ensure_estimated_completion_date_column(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'estimated_completion_date')) {
        $conn->query("ALTER TABLE projects ADD COLUMN estimated_completion_date DATE DEFAULT NULL AFTER project_start_date");
    }
}

function ensure_project_soft_delete_columns(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'deleted_at')) {
        $conn->query("ALTER TABLE projects ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER status");
    }

    if (!table_has_column($conn, 'projects', 'deleted_by')) {
        $conn->query("ALTER TABLE projects ADD COLUMN deleted_by INT(11) DEFAULT NULL AFTER deleted_at");
    }

    if (!table_has_column($conn, 'projects', 'delete_scheduled_at')) {
        $conn->query("ALTER TABLE projects ADD COLUMN delete_scheduled_at DATETIME DEFAULT NULL AFTER deleted_by");
    }

    if (!table_has_column($conn, 'projects', 'restored_at')) {
        $conn->query("ALTER TABLE projects ADD COLUMN restored_at DATETIME DEFAULT NULL AFTER delete_scheduled_at");
    }

    if (!table_has_column($conn, 'projects', 'restored_by')) {
        $conn->query("ALTER TABLE projects ADD COLUMN restored_by INT(11) DEFAULT NULL AFTER restored_at");
    }

    $indexResult = $conn->query("SHOW INDEX FROM projects WHERE Key_name = 'idx_projects_deleted_at'");
    if (!$indexResult || (int)$indexResult->num_rows === 0) {
        $conn->query("ALTER TABLE projects ADD INDEX idx_projects_deleted_at (deleted_at, delete_scheduled_at, status)");
    }
}

function purge_expired_deleted_projects(mysqli $conn): void {
    if (!table_has_column($conn, 'projects', 'deleted_at') || !table_has_column($conn, 'projects', 'delete_scheduled_at')) {
        return;
    }

    $purgeStmt = $conn->prepare(
        'DELETE FROM projects
         WHERE deleted_at IS NOT NULL
         AND delete_scheduled_at IS NOT NULL
         AND delete_scheduled_at <= NOW()'
    );

    if ($purgeStmt) {
        $purgeStmt->execute();
    }
}

/**
 * @param mixed $value
 */
function normalize_positive_int($value): int {
    $normalized = (int)$value;
    return $normalized > 0 ? $normalized : 0;
}

/**
 * @param mixed $value
 */
function normalize_money_or_null($value): ?float {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $normalized = str_ireplace(['PHP', '?'], '', $value);
    $normalized = str_replace([',', ' '], '', $normalized);
    if (!is_numeric($normalized)) {
        return null;
    }

    return round((float)$normalized, 2);
}

/**
 * @param mixed $value
 */
function format_money($value): string {
    return 'PHP ' . number_format((float)$value, 2);
}

function format_display_date(?string $value): string {
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

function format_display_datetime(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return 'N/A';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y g:ia');
    } catch (Throwable $exception) {
        return $value;
    }
}

function project_target_is_past_due(?string $targetDate, string $status): bool {
    $targetDate = trim((string)$targetDate);
    $status = strtolower(trim($status));

    if ($targetDate === '' || in_array($status, ['completed', 'cancelled', 'archived'], true)) {
        return false;
    }

    try {
        return (new DateTimeImmutable($targetDate))->format('Y-m-d') < (new DateTimeImmutable('today'))->format('Y-m-d');
    } catch (Throwable $exception) {
        return false;
    }
}

function project_requires_po_date(string $status): bool {
    return in_array($status, ['pending', 'ongoing'], true);
}

function build_budget_health(float $budgetAmount, float $totalCost): array {
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

function determine_payment_status(float $totalCost, float $amountPaid): array {
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

function project_has_budget(?array $projectFinancials): bool {
    return $projectFinancials !== null && (float)($projectFinancials['budget_amount'] ?? 0) > 0;
}

function determine_inventory_status(int $quantity, ?int $minStock): string {
    if ($quantity <= 0) {
        return 'out-of-stock';
    }

    if ($minStock !== null && $quantity <= $minStock) {
        return 'low-stock';
    }

    return 'available';
}

$supportsDraftStatus = enum_supports_value($conn, 'projects', 'status', 'draft');
$supportsCancelledStatus = enum_supports_value($conn, 'projects', 'status', 'cancelled');
$supportsArchivedStatus = enum_supports_value($conn, 'projects', 'status', 'archived');
ensure_project_address_column($conn);
ensure_project_site_column($conn);
ensure_project_email_column($conn);
ensure_project_additional_info_column($conn);
ensure_project_code_column($conn);
ensure_project_source_column($conn);
ensure_project_po_number_column($conn);
ensure_project_contact_person_column($conn);
ensure_project_contact_number_column($conn);
ensure_project_start_date_column($conn);
ensure_estimated_completion_date_column($conn);
ensure_project_soft_delete_columns($conn);
ensure_user_soft_delete_columns($conn);
$hasProjectSiteColumn = table_has_column($conn, 'projects', 'project_site');
$hasProjectAddressColumn = table_has_column($conn, 'projects', 'project_address');
$hasProjectEmailColumn = table_has_column($conn, 'projects', 'project_email');
$hasProjectAdditionalInfoColumn = table_has_column($conn, 'projects', 'additional_info_json');
$hasProjectCodeColumn = table_has_column($conn, 'projects', 'project_code');
$hasPoNumberColumn = table_has_column($conn, 'projects', 'po_number');
$hasContactPersonColumn = table_has_column($conn, 'projects', 'contact_person');
$hasContactNumberColumn = table_has_column($conn, 'projects', 'contact_number');
$hasProjectStartDateColumn = table_has_column($conn, 'projects', 'project_start_date');
$hasEstimatedCompletionDateColumn = table_has_column($conn, 'projects', 'estimated_completion_date');
ensure_project_search_indexes($conn, $hasProjectAddressColumn, $hasProjectSiteColumn);
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
$initialStatusOptions = $supportsDraftStatus
    ? ['draft', 'pending']
    : ['pending', 'ongoing'];
$todayDate = today_date();
$csrfToken = auth_csrf_token('super_admin');

ensure_project_inventory_deployments_table($conn);
ensure_project_inventory_return_logs_table($conn);
ensure_asset_unit_tracking_schema($conn);
ensure_project_budget_profiles_table($conn);
ensure_project_cost_entries_table($conn);
ensure_project_payments_table($conn);
purge_expired_deleted_projects($conn);

function get_projects_redirect_target(): string {
    $redirectTo = $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '';
    $redirectTo = is_string($redirectTo) ? trim($redirectTo) : '';

    if ($redirectTo !== '' && str_starts_with($redirectTo, '/codesamplecaps/ADMIN/sidebar/')) {
        return $redirectTo;
    }

    return '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php';
}

function redirect_projects_page(): void {
    header('Location: ' . get_projects_redirect_target());
    exit();
}

function set_projects_flash(string $type, string $message): void {
    $_SESSION['projects_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function set_projects_old_input(array $input, ?string $focusField = null): void {
    $_SESSION['projects_old_input'] = [
        'project_name' => trim((string)($input['project_name'] ?? '')),
        'description' => trim((string)($input['description'] ?? '')),
        'contact_person' => trim((string)($input['contact_person'] ?? '')),
        'contact_number' => trim((string)($input['contact_number'] ?? '')),
        'project_site' => trim((string)($input['project_site'] ?? '')),
        'project_address' => trim((string)($input['project_address'] ?? '')),
        'project_email' => trim((string)($input['project_email'] ?? '')),
        'additional_info' => normalize_project_additional_info_input($input['additional_info'] ?? []),
        'project_code' => trim((string)($input['project_code'] ?? '')),
        'po_number' => trim((string)($input['po_number'] ?? '')),
        'client_id' => (string)($input['client_id'] ?? ''),
        'engineer_ids' => array_values(array_map('strval', is_array($input['engineer_ids'] ?? null) ? $input['engineer_ids'] : [])),
        'status' => trim((string)($input['status'] ?? '')),
        'start_date' => trim((string)($input['start_date'] ?? '')),
        'project_start_date' => trim((string)($input['project_start_date'] ?? '')),
        'estimated_completion_date' => trim((string)($input['estimated_completion_date'] ?? '')),
        'estimated_duration_days' => trim((string)($input['estimated_duration_days'] ?? '')),
        'budget_amount' => trim((string)($input['budget_amount'] ?? '')),
        'budget_notes' => trim((string)($input['budget_notes'] ?? '')),
        'focus_field' => $focusField,
    ];
}

function clear_projects_old_input(): void {
    unset($_SESSION['projects_old_input']);
}

function normalize_text(?string $value): string {
    return trim((string)$value);
}

function normalize_date_or_null(?string $value): ?string {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

/**
 * @param mixed $value
 */
function normalize_positive_int_or_null($value): ?int {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $normalized = (int)$value;
    return $normalized > 0 ? $normalized : null;
}

function calculate_estimated_completion_date(?string $projectStartDate, ?int $estimatedDurationDays): ?string {
    if ($projectStartDate === null || $estimatedDurationDays === null || $estimatedDurationDays <= 0) {
        return null;
    }

    try {
        $startDate = new DateTimeImmutable($projectStartDate);
        return $startDate->modify('+' . ($estimatedDurationDays - 1) . ' days')->format('Y-m-d');
    } catch (Throwable $exception) {
        return null;
    }
}

function calculate_project_duration_days(?string $projectStartDate, ?string $estimatedCompletionDate): ?int {
    if ($projectStartDate === null || $estimatedCompletionDate === null) {
        return null;
    }

    try {
        $startDate = new DateTimeImmutable($projectStartDate);
        $endDate = new DateTimeImmutable($estimatedCompletionDate);

        if ($endDate < $startDate) {
            return null;
        }

        return ((int)$startDate->diff($endDate)->format('%a')) + 1;
    } catch (Throwable $exception) {
        return null;
    }
}

function blank_project_additional_info_row(): array {
    return [
        'contact_name' => '',
        'contact_number' => '',
        'email_address' => '',
    ];
}

/**
 * @param mixed $rows
 */
function normalize_project_additional_info_input($rows): array {
    if (!is_array($rows)) {
        return [];
    }

    $normalizedRows = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $normalizedRow = [
            'contact_name' => trim((string)($row['contact_name'] ?? '')),
            'contact_number' => trim((string)($row['contact_number'] ?? '')),
            'email_address' => trim((string)($row['email_address'] ?? '')),
        ];

        if (
            $normalizedRow['contact_name'] === ''
            && $normalizedRow['contact_number'] === ''
            && $normalizedRow['email_address'] === ''
        ) {
            continue;
        }

        $normalizedRows[] = $normalizedRow;
    }

    return $normalizedRows;
}

/**
 * @param mixed $rawValue
 */
function decode_project_additional_info($rawValue): array {
    if (is_array($rawValue)) {
        return normalize_project_additional_info_input($rawValue);
    }

    $rawValue = trim((string)$rawValue);
    if ($rawValue === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return [];
    }

    return normalize_project_additional_info_input($decoded);
}

function encode_project_additional_info(array $rows): ?string {
    $normalizedRows = normalize_project_additional_info_input($rows);
    if ($normalizedRows === []) {
        return null;
    }

    $encoded = json_encode($normalizedRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? null : $encoded;
}

/**
 * @param mixed $rows
 */
function project_additional_info_rows_for_form($rows): array {
    $normalizedRows = is_array($rows) ? normalize_project_additional_info_input($rows) : [];
    return $normalizedRows === [] ? [blank_project_additional_info_row()] : array_values($normalizedRows);
}

function project_additional_info_search_text(array $rows): string {
    $chunks = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $chunks[] = trim(implode(' ', array_filter([
            trim((string)($row['contact_name'] ?? '')),
            trim((string)($row['contact_number'] ?? '')),
            trim((string)($row['email_address'] ?? '')),
        ])));
    }

    return trim(implode(' ', array_filter($chunks)));
}

function save_project_additional_info_json(mysqli $conn, int $projectId, ?string $additionalInfoJson): bool {
    if ($projectId <= 0) {
        return false;
    }

    if ($additionalInfoJson === null) {
        $stmt = $conn->prepare('UPDATE projects SET additional_info_json = NULL WHERE id = ?');
        return $stmt && $stmt->bind_param('i', $projectId) && $stmt->execute();
    }

    $stmt = $conn->prepare('UPDATE projects SET additional_info_json = ? WHERE id = ?');
    return $stmt && $stmt->bind_param('si', $additionalInfoJson, $projectId) && $stmt->execute();
}

function getProjectFinancialSnapshot(mysqli $conn, int $projectId): ?array {
    $stmt = $conn->prepare(
        'SELECT
            p.id,
            p.project_name,
            p.status,
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

function getProjectPaymentSnapshot(mysqli $conn, int $projectId): ?array {
    $stmt = $conn->prepare(
        'SELECT
            p.id,
            p.project_name,
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

function fetchRecentProjectCostEntries(mysqli $conn, array $projectIds, int $limitPerProject = 3): array {
    $projectIds = array_values(array_filter(array_map('intval', $projectIds)));
    if ($projectIds === []) {
        return [];
    }

    $inList = implode(',', $projectIds);
    $result = $conn->query(
        "SELECT
            pce.project_id,
            pce.cost_date,
            pce.cost_category,
            pce.description,
            pce.amount,
            u.full_name AS created_by_name
         FROM project_cost_entries pce
         LEFT JOIN users u ON u.id = pce.created_by
         WHERE pce.project_id IN ({$inList})
         ORDER BY pce.project_id ASC, pce.cost_date DESC, pce.id DESC"
    );

    if (!$result) {
        return [];
    }

    $groupedEntries = [];
    while ($row = $result->fetch_assoc()) {
        $projectId = (int)($row['project_id'] ?? 0);
        if (!isset($groupedEntries[$projectId])) {
            $groupedEntries[$projectId] = [];
        }

        if (count($groupedEntries[$projectId]) >= $limitPerProject) {
            continue;
        }

        $groupedEntries[$projectId][] = $row;
    }

    return $groupedEntries;
}

function getProjectSnapshot(mysqli $conn, int $projectId): ?array {
    $stmt = $conn->prepare(
        'SELECT
            p.id,
            p.project_name,
            p.description,
            p.client_id,
            p.contact_person,
            p.contact_number,
            p.project_site,
            p.project_address,
            p.project_email,
            p.additional_info_json,
            p.project_code,
            p.po_number,
            p.start_date,
            p.end_date,
            p.status,
            (
                SELECT GROUP_CONCAT(pa.engineer_id ORDER BY pa.engineer_id SEPARATOR ",")
                FROM project_assignments pa
                WHERE pa.project_id = p.id
            ) AS engineer_ids_csv
         FROM projects p
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

function getDeletedProjectSnapshot(mysqli $conn, int $projectId): ?array {
    $stmt = $conn->prepare(
        'SELECT
            p.id,
            p.project_name,
            p.status,
            p.deleted_at,
            p.delete_scheduled_at,
            (
                SELECT COUNT(*)
                FROM tasks t
                WHERE t.project_id = p.id
                AND t.status IN ("pending", "ongoing", "delayed")
            ) AS open_tasks
         FROM projects p
         WHERE p.id = ?
         AND p.deleted_at IS NOT NULL
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

function projectNameExists(mysqli $conn, string $projectName, ?int $excludeProjectId = null): bool {
    $normalizedName = trim(mb_strtolower($projectName));

    if ($normalizedName === '') {
        return false;
    }

    if ($excludeProjectId !== null && $excludeProjectId > 0) {
        $stmt = $conn->prepare(
            'SELECT id
             FROM projects
             WHERE LOWER(TRIM(project_name)) = ?
             AND deleted_at IS NULL
             AND id <> ?
             LIMIT 1'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $normalizedName, $excludeProjectId);
    } else {
        $stmt = $conn->prepare(
            'SELECT id
             FROM projects
             WHERE LOWER(TRIM(project_name)) = ?
             AND deleted_at IS NULL
             LIMIT 1'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $normalizedName);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

function projectFieldValueExists(mysqli $conn, string $columnName, string $value, ?int $excludeProjectId = null): bool {
    $normalizedValue = trim(mb_strtolower($value));

    if ($normalizedValue === '' || !table_has_column($conn, 'projects', $columnName)) {
        return false;
    }

    if ($excludeProjectId !== null && $excludeProjectId > 0) {
        $stmt = $conn->prepare(
            "SELECT id
             FROM projects
             WHERE LOWER(TRIM({$columnName})) = ?
             AND deleted_at IS NULL
             AND id <> ?
             LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $normalizedValue, $excludeProjectId);
    } else {
        $stmt = $conn->prepare(
            "SELECT id
             FROM projects
             WHERE LOWER(TRIM({$columnName})) = ?
             AND deleted_at IS NULL
             LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $normalizedValue);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

function generate_next_project_code(mysqli $conn): string {
    $year = date('Y');

    for ($number = 1; $number <= 9999; $number++) {
        $code = 'EDGE-' . $year . '-' . str_pad((string)$number, 4, '0', STR_PAD_LEFT);

        if (!projectFieldValueExists($conn, 'project_code', $code)) {
            return $code;
        }
    }

    return 'EDGE-' . $year . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function build_manual_project_title(array $input): string {
    $site = trim((string)($input['project_site'] ?? ''));
    $client = trim((string)($input['client_name'] ?? ''));

    if ($site !== '') {
        return 'Project - ' . $site;
    }

    if ($client !== '') {
        return 'Project - ' . $client;
    }

    return 'Project';
}

/**
 * @param mixed $value
 */
function normalize_engineer_ids($value): array {
    $rawValues = is_array($value) ? $value : [$value];
    $normalized = [];

    foreach ($rawValues as $rawValue) {
        $engineerId = (int)$rawValue;
        if ($engineerId > 0) {
            $normalized[$engineerId] = $engineerId;
        }
    }

    return array_values($normalized);
}

function countOpenProjectTasks(mysqli $conn, int $projectId): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM tasks
         WHERE project_id = ?
         AND status IN ('pending', 'ongoing', 'delayed')"
    );

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    return (int)($row['total'] ?? 0);
}

function countActiveProjectInventoryDeployments(mysqli $conn, int $projectId): int {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM (
             SELECT pid.id
             FROM project_inventory_deployments pid
             LEFT JOIN (
                 SELECT deployment_id, SUM(quantity) AS returned_quantity
                 FROM project_inventory_return_logs
                 GROUP BY deployment_id
             ) returns ON returns.deployment_id = pid.id
             WHERE pid.project_id = ?
             AND (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
         ) active_deployments'
    );

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    return (int)($row['total'] ?? 0);
}

function getActiveProjectInventoryDeployment(mysqli $conn, int $deploymentId): ?array {
    $stmt = $conn->prepare(
        'SELECT
            pid.id,
            pid.project_id,
            pid.inventory_id,
            pid.quantity,
            a.asset_name,
            COALESCE(returns.returned_quantity, 0) AS returned_quantity,
            (pid.quantity - COALESCE(returns.returned_quantity, 0)) AS remaining_quantity
         FROM project_inventory_deployments pid
         INNER JOIN inventory i ON i.id = pid.inventory_id
         INNER JOIN assets a ON a.id = i.asset_id
         LEFT JOIN (
             SELECT deployment_id, SUM(quantity) AS returned_quantity
             FROM project_inventory_return_logs
             GROUP BY deployment_id
         ) returns ON returns.deployment_id = pid.id
         WHERE pid.id = ?
         AND (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $deploymentId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
        set_projects_flash('error', 'Security check failed. Please try again.');
        redirect_projects_page();
    }

    if ($action === 'restore_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $restoredBy = (int)($_SESSION['user_id'] ?? 0);
        $user = $userId > 0 ? fetch_user_for_trash($conn, $userId) : null;

        if ($userId <= 0 || !$user || empty($user['deleted_at'])) {
            set_projects_flash('error', 'Trashed user not found.');
        } elseif ((string)($user['role'] ?? '') === 'super_admin') {
            set_projects_flash('error', 'Super admin accounts cannot be restored here.');
        } else {
            $restoreUser = $conn->prepare(
                'UPDATE users
                 SET deleted_at = NULL,
                     deleted_by = NULL,
                     restored_at = NOW(),
                     restored_by = ?
                 WHERE id = ?
                 AND deleted_at IS NOT NULL'
            );
            if ($restoreUser && $restoreUser->bind_param('ii', $restoredBy, $userId) && $restoreUser->execute() && $restoreUser->affected_rows > 0) {
                audit_log_event(
                    $conn,
                    $restoredBy,
                    'restore_user',
                    'user',
                    $userId,
                    ['deleted_at' => $user['deleted_at'] ?? null],
                    ['restored_at' => date('Y-m-d H:i:s')]
                );
                set_projects_flash('success', 'User restored from trash.');
            } else {
                set_projects_flash('error', 'Failed to restore user.');
            }
        }

        header('Location: /codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash');
        exit;
    }

    if ($action === 'permanently_delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? fetch_user_for_trash($conn, $userId) : null;

        if ($userId <= 0 || !$user || empty($user['deleted_at'])) {
            set_projects_flash('error', 'Trashed user not found.');
        } elseif ((string)($user['role'] ?? '') === 'super_admin') {
            set_projects_flash('error', 'Super admin accounts cannot be permanently deleted here.');
        } else {
            try {
                ensure_deleted_users_archive_table($conn);
                $conn->begin_transaction();

                $payloadJson = json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $archiveStmt = $conn->prepare(
                    'INSERT INTO deleted_users_archive (original_user_id, full_name, email, phone, role, status, deleted_by, payload_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $deletedBy = (int)($_SESSION['user_id'] ?? 0);
                $archiveStmt->bind_param(
                    'isssssis',
                    $userId,
                    $user['full_name'],
                    $user['email'],
                    $user['phone'],
                    $user['role'],
                    $user['status'],
                    $deletedBy,
                    $payloadJson
                );
                $archiveStmt->execute();

                $cleanupLoginAttempts = $conn->prepare('DELETE FROM login_attempts WHERE email = ?');
                $cleanupLoginAttempts->bind_param('s', $user['email']);
                $cleanupLoginAttempts->execute();

                $deleteUser = $conn->prepare('DELETE FROM users WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1');
                $deleteUser->bind_param('i', $userId);
                $deleteUser->execute();

                if ($deleteUser->affected_rows !== 1) {
                    throw new RuntimeException('User permanent delete failed.');
                }

                audit_log_event(
                    $conn,
                    $deletedBy,
                    'delete_user',
                    'user',
                    $userId,
                    [
                        'full_name' => $user['full_name'] ?? null,
                        'email' => $user['email'] ?? null,
                        'phone' => $user['phone'] ?? null,
                        'role' => $user['role'] ?? null,
                        'status' => $user['status'] ?? null,
                    ],
                    null
                );

                $conn->commit();
                set_projects_flash('success', 'User permanently deleted from trash bin.');
            } catch (Throwable $exception) {
                $conn->rollback();
                set_projects_flash('error', 'Failed to permanently delete user.');
            }
        }

        header('Location: /codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash');
        exit;
    }

    if ($action === 'create_project') {
        $projectName = normalize_text($_POST['project_name'] ?? '');
        $description = normalize_text($_POST['description'] ?? '');
        $contactPerson = $hasContactPersonColumn ? normalize_text_or_null($_POST['contact_person'] ?? null) : null;
        $contactNumber = $hasContactNumberColumn ? normalize_text_or_null($_POST['contact_number'] ?? null) : null;
        $projectSite = $hasProjectSiteColumn ? normalize_text_or_null($_POST['project_site'] ?? null) : null;
        $projectAddress = $hasProjectAddressColumn ? normalize_text_or_null($_POST['project_address'] ?? null) : null;
        $projectEmail = $hasProjectEmailColumn ? normalize_text_or_null($_POST['project_email'] ?? null) : null;
        $additionalInfoRows = normalize_project_additional_info_input($_POST['additional_info'] ?? []);
        $additionalInfoJson = encode_project_additional_info($additionalInfoRows);
        $projectCode = $hasProjectCodeColumn ? normalize_text_or_null($_POST['project_code'] ?? null) : null;
        $projectSource = normalize_text($_POST['project_source'] ?? 'walk_in');
        $allowedProjectSources = ['walk_in', 'returning_client'];
        if (!in_array($projectSource, $allowedProjectSources, true)) {
            $projectSource = 'walk_in';
        }
        if ($hasProjectCodeColumn && $projectCode === null) {
            // Auto project code para iwas duplicate at hindi na mano-mano si Admin.
            $projectCode = generate_next_project_code($conn);
            $_POST['project_code'] = $projectCode;
        }
        $poNumber = $hasPoNumberColumn ? normalize_text_or_null($_POST['po_number'] ?? null) : null;
        $clientId = (int)($_POST['client_id'] ?? 0);
        $engineerIds = normalize_engineer_ids($_POST['engineer_ids'] ?? []);
        $status = normalize_text($_POST['status'] ?? 'pending');
        $startDate = normalize_date_or_null($_POST['start_date'] ?? null);
        $projectStartDate = $hasProjectStartDateColumn ? normalize_date_or_null($_POST['project_start_date'] ?? null) : null;
        $estimatedCompletionDate = $hasEstimatedCompletionDateColumn ? normalize_date_or_null($_POST['estimated_completion_date'] ?? null) : null;
        $estimatedDurationDays = normalize_positive_int_or_null($_POST['estimated_duration_days'] ?? null);
        $endDate = null;
        $budgetAmount = normalize_money_or_null($_POST['budget_amount'] ?? null);
        $budgetNotes = normalize_text_or_null($_POST['budget_notes'] ?? null);
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $createProjectInput = [
            'project_name' => $_POST['project_name'] ?? '',
            'description' => $_POST['description'] ?? '',
            'contact_person' => $_POST['contact_person'] ?? '',
            'contact_number' => $_POST['contact_number'] ?? '',
            'project_site' => $_POST['project_site'] ?? '',
            'project_address' => $_POST['project_address'] ?? '',
            'project_email' => $_POST['project_email'] ?? '',
            'additional_info' => $_POST['additional_info'] ?? [],
            'project_code' => $_POST['project_code'] ?? '',
            'project_source' => $projectSource,
            'po_number' => $_POST['po_number'] ?? '',
            'client_id' => $_POST['client_id'] ?? '',
            'engineer_ids' => $_POST['engineer_ids'] ?? [],
            'status' => $_POST['status'] ?? 'pending',
            'start_date' => $_POST['start_date'] ?? '',
            'project_start_date' => $_POST['project_start_date'] ?? '',
            'estimated_completion_date' => $_POST['estimated_completion_date'] ?? '',
            'estimated_duration_days' => $_POST['estimated_duration_days'] ?? '',
            'budget_amount' => $_POST['budget_amount'] ?? '',
            'budget_notes' => $_POST['budget_notes'] ?? '',
        ];

        if ($projectName === '') {
            set_projects_old_input($createProjectInput, 'project_name');
            set_projects_flash('error', 'Project name is required.');
            redirect_projects_page();
        }

        if (!project_service_has_real_text($projectName)) {
            set_projects_old_input($createProjectInput, 'project_name');
            set_projects_flash('error', 'Project Title needs real text, not only numbers or symbols.');
            redirect_projects_page();
        }

        if ($clientId <= 0) {
            set_projects_old_input($createProjectInput, 'client_id');
            set_projects_flash('error', 'Client is required.');
            redirect_projects_page();
        }

        if ($engineerIds === []) {
            set_projects_old_input($createProjectInput, 'engineer_ids');
            set_projects_flash('error', 'Project title, client, and assigned team member/s are required.');
            redirect_projects_page();
        }

        if (projectNameExists($conn, $projectName)) {
            set_projects_old_input($createProjectInput, 'project_name');
            set_projects_flash('error', 'Project name already exists. Use a more specific name like site, phase, or year.');
            redirect_projects_page();
        }

        if (!in_array($status, $initialStatusOptions, true)) {
            set_projects_old_input($createProjectInput, 'status');
            set_projects_flash(
                'error',
                $supportsDraftStatus
                    ? 'Initial project status must be Draft, Pending, or Ongoing only.'
                    : 'Initial project status must be Pending or Ongoing only.'
            );
            redirect_projects_page();
        }

        if ($hasContactPersonColumn && $status !== 'draft' && $contactPerson === null) {
            set_projects_old_input($createProjectInput, 'contact_person');
            set_projects_flash('error', 'Client contact person is required unless the project stays in Draft.');
            redirect_projects_page();
        }

        if ($contactPerson !== null && !project_service_is_person_name($contactPerson)) {
            set_projects_old_input($createProjectInput, 'contact_person');
            set_projects_flash('error', 'Client Contact Person should use a valid name.');
            redirect_projects_page();
        }

        if ($hasContactNumberColumn && $status !== 'draft' && $contactNumber === null) {
            set_projects_old_input($createProjectInput, 'contact_number');
            set_projects_flash('error', 'Client contact number is required unless the project stays in Draft.');
            redirect_projects_page();
        }

        if ($hasProjectSiteColumn && $status !== 'draft' && $projectSite === null) {
            set_projects_old_input($createProjectInput, 'project_site');
            set_projects_flash('error', 'Project site is required unless the project stays in Draft.');
            redirect_projects_page();
        }

        if ($projectSite !== null && !project_service_has_real_text($projectSite)) {
            set_projects_old_input($createProjectInput, 'project_site');
            set_projects_flash('error', 'Project Site needs real text, not only numbers or symbols.');
            redirect_projects_page();
        }

        if ($hasProjectAddressColumn && $status !== 'draft' && $projectAddress === null) {
            set_projects_old_input($createProjectInput, 'project_address');
            set_projects_flash('error', 'Project address is required unless the project stays in Draft.');
            redirect_projects_page();
        }

        if ($projectAddress !== null && !project_service_has_real_text($projectAddress)) {
            set_projects_old_input($createProjectInput, 'project_address');
            set_projects_flash('error', 'Project Address needs real text, not only numbers or symbols.');
            redirect_projects_page();
        }

        if ($description !== '' && !project_service_has_real_text($description)) {
            set_projects_old_input($createProjectInput, 'description');
            set_projects_flash('error', 'Comment needs real text if you add one.');
            redirect_projects_page();
        }

        if ($projectEmail !== null && !filter_var($projectEmail, FILTER_VALIDATE_EMAIL)) {
            set_projects_old_input($createProjectInput, 'project_email');
            set_projects_flash('error', 'Project email must be a valid email address.');
            redirect_projects_page();
        }

        foreach ($additionalInfoRows as $additionalInfoRow) {
            $additionalInfoEmail = trim((string)($additionalInfoRow['email_address'] ?? ''));
            if ($additionalInfoEmail !== '' && !filter_var($additionalInfoEmail, FILTER_VALIDATE_EMAIL)) {
                set_projects_old_input($createProjectInput, 'additional_info');
                set_projects_flash('error', 'Each additional info email address must be valid.');
                redirect_projects_page();
            }
        }

        if ($projectCode === null) {
            set_projects_old_input($createProjectInput, 'project_code');
            set_projects_flash('error', 'Project code is required.');
            redirect_projects_page();
        }

        if (project_requires_po_date($status) && $poNumber === null) {
            set_projects_old_input($createProjectInput, 'po_number');
            set_projects_flash('error', 'P.O Number is required when the project starts as Pending or Ongoing.');
            redirect_projects_page();
        }

        if ($hasProjectCodeColumn && $projectCode !== null && projectFieldValueExists($conn, 'project_code', $projectCode)) {
            // Kapag lumang tab ang gamit, gumawa ulit ng fresh code bago mag-save.
            $projectCode = generate_next_project_code($conn);
            $createProjectInput['project_code'] = $projectCode;
        }

        if ($poNumber !== null && projectFieldValueExists($conn, 'po_number', $poNumber)) {
            set_projects_old_input($createProjectInput, 'po_number');
            set_projects_flash('error', 'P.O Number already exists.');
            redirect_projects_page();
        }

        if (project_requires_po_date($status) && $startDate === null) {
            set_projects_old_input($createProjectInput, 'start_date');
            set_projects_flash('error', 'P.O Date is required when the project starts as Pending or Ongoing.');
            redirect_projects_page();
        }

        if ($startDate !== null && $startDate > $todayDate) {
            set_projects_old_input($createProjectInput, 'start_date');
            set_projects_flash('error', 'P.O Date cannot be in the future.');
            redirect_projects_page();
        }

        if ($hasProjectStartDateColumn && $projectStartDate === null) {
            set_projects_old_input($createProjectInput, 'project_start_date');
            set_projects_flash('error', 'Project Start Date is required.');
            redirect_projects_page();
        }

        if ($startDate !== null && $projectStartDate !== null && $projectStartDate < $startDate) {
            set_projects_old_input($createProjectInput, 'project_start_date');
            set_projects_flash('error', 'Project Start Date must be the same as or later than P.O Date.');
            redirect_projects_page();
        }

        if (($_POST['estimated_duration_days'] ?? '') !== '' && $estimatedDurationDays === null) {
            set_projects_old_input($createProjectInput, 'estimated_duration_days');
            set_projects_flash('error', 'Estimated Duration Days must be greater than zero.');
            redirect_projects_page();
        }

        if ($projectStartDate !== null && $estimatedDurationDays !== null) {
            $estimatedCompletionDate = calculate_estimated_completion_date($projectStartDate, $estimatedDurationDays);
        }

        if ($hasEstimatedCompletionDateColumn && $estimatedCompletionDate === null) {
            set_projects_old_input($createProjectInput, 'estimated_completion_date');
            set_projects_flash('error', 'Estimated Completion Date is required.');
            redirect_projects_page();
        }

        if ($projectStartDate !== null && $estimatedCompletionDate !== null && $estimatedCompletionDate < $projectStartDate) {
            set_projects_old_input($createProjectInput, 'estimated_completion_date');
            set_projects_flash('error', 'Estimated Completion Date must be the same as or later than Project Start Date.');
            redirect_projects_page();
        }

        if (($_POST['budget_amount'] ?? '') !== '' && $budgetAmount === null) {
            set_projects_old_input($createProjectInput, 'budget_amount');
            set_projects_flash('error', 'Budget must be a valid amount.');
            redirect_projects_page();
        }

        if ($budgetAmount !== null && $budgetAmount < 0) {
            set_projects_old_input($createProjectInput, 'budget_amount');
            set_projects_flash('error', 'Budget cannot be negative.');
            redirect_projects_page();
        }

        try {
            $projectId = project_service_create_project($conn, [
                'has_project_address_column' => $hasProjectAddressColumn,
                'has_project_email_column' => $hasProjectEmailColumn,
                'has_project_code_column' => $hasProjectCodeColumn,
                'has_po_number_column' => $hasPoNumberColumn,
                'has_project_additional_info_column' => $hasProjectAdditionalInfoColumn,
                'project_name' => $projectName,
                'description' => $description,
                'client_id' => $clientId,
                'contact_person' => $contactPerson,
                'contact_number' => $contactNumber,
                'project_site' => $projectSite,
                'project_address' => $projectAddress,
                'project_email' => $projectEmail,
                'project_code' => $projectCode,
                'po_number' => $poNumber,
                'start_date' => $startDate,
                'project_start_date' => $projectStartDate,
                'estimated_completion_date' => $estimatedCompletionDate,
                'end_date' => $endDate,
                'status' => $status,
                'created_by' => $createdBy,
                'engineer_ids' => $engineerIds,
                'budget_amount' => $budgetAmount,
                'budget_notes' => $budgetNotes,
                'additional_info_json' => $additionalInfoJson,
            ]);

            $sourceUpdate = $conn->prepare('UPDATE projects SET project_source = ? WHERE id = ?');
            if ($sourceUpdate) {
                $sourceUpdate->bind_param('si', $projectSource, $projectId);
                $sourceUpdate->execute();
            }

            audit_log_event(
                $conn,
                $createdBy,
                'create_project',
                'project',
                $projectId,
                null,
                [
                    'project_name' => $projectName,
                    'status' => $status,
                    'client_id' => $clientId,
                    'engineer_ids' => $engineerIds,
                    'contact_person' => $contactPerson,
                    'contact_number' => $contactNumber,
                    'project_email' => $projectEmail,
                    'additional_info' => $additionalInfoRows,
                    'project_code' => $projectCode,
                    'project_source' => $projectSource,
                    'po_number' => $poNumber,
                    'budget_amount' => $budgetAmount,
                ]
            );
            clear_projects_old_input();
            if ($status === 'ongoing' && ($budgetAmount ?? 0) <= 0) {
                set_projects_flash('warning', 'Project created and moved to Ongoing without a budget. Actual expenses can still be tracked.');
            } else {
                set_projects_flash('success', 'Project created successfully.');
            }
        } catch (Throwable $exception) {
            set_projects_old_input($createProjectInput);
            set_projects_flash('error', $exception->getMessage());
        }

        redirect_projects_page();
    }

    if ($action === 'save_project_budget') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $budgetAmountInput = $_POST['budget_amount'] ?? '';
        $budgetAmount = normalize_money_or_null($budgetAmountInput);
        $budgetNotes = normalize_text_or_null($_POST['budget_notes'] ?? null);
        $updatedBy = (int)($_SESSION['user_id'] ?? 0);
        $projectFinancials = $projectId > 0 ? getProjectFinancialSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || !$projectFinancials) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if ($budgetAmountInput !== '' && $budgetAmount === null) {
            set_projects_flash('error', 'Budget must be a valid amount.');
            redirect_projects_page();
        }

        if ($budgetAmount !== null && $budgetAmount < 0) {
            set_projects_flash('error', 'Budget cannot be negative.');
            redirect_projects_page();
        }

        if ($budgetAmount === null) {
            $budgetAmount = 0.00;
        }

        if (project_service_save_project_budget($conn, $projectId, $budgetAmount, $budgetNotes, $updatedBy)) {
            audit_log_event(
                $conn,
                $updatedBy,
                'update_project_budget',
                'project',
                $projectId,
                [
                    'project_name' => $projectFinancials['project_name'] ?? null,
                    'budget_amount' => (float)($projectFinancials['budget_amount'] ?? 0),
                    'budget_notes' => $projectFinancials['budget_notes'] ?? null,
                ],
                [
                    'project_name' => $projectFinancials['project_name'] ?? null,
                    'budget_amount' => $budgetAmount,
                    'budget_notes' => $budgetNotes,
                ]
            );
            set_projects_flash('success', 'Project budget saved.');
        } else {
            set_projects_flash('error', 'Failed to save project budget.');
        }

        redirect_projects_page();
    }

    if ($action === 'add_project_cost_entry') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $costDate = normalize_date_or_null($_POST['cost_date'] ?? null);
        $costCategory = normalize_text($_POST['cost_category'] ?? '');
        $costDescription = normalize_text_or_null($_POST['cost_description'] ?? null);
        $amountInput = $_POST['cost_amount'] ?? '';
        $costAmount = normalize_money_or_null($amountInput);
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $projectFinancials = $projectId > 0 ? getProjectFinancialSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || !$projectFinancials) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if ($costDate === null || $costCategory === '') {
            set_projects_flash('error', 'Cost date and category are required.');
            redirect_projects_page();
        }

        $projectForCost = getProjectSnapshot($conn, $projectId);
        if (!$projectForCost || empty($projectForCost['start_date'])) {
            set_projects_flash('error', 'Set the P.O Date first before logging project costs.');
            redirect_projects_page();
        }

        if ($amountInput === '' || $costAmount === null || $costAmount <= 0) {
            set_projects_flash('error', 'Cost amount must be greater than zero.');
            redirect_projects_page();
        }

        if (project_service_add_cost_entry($conn, $projectId, $costDate, $costCategory, $costDescription, $costAmount, $createdBy)) {
            audit_log_event(
                $conn,
                $createdBy,
                'add_project_cost',
                'project',
                $projectId,
                null,
                [
                    'project_name' => $projectFinancials['project_name'] ?? null,
                    'cost_date' => $costDate,
                    'cost_category' => $costCategory,
                    'amount' => $costAmount,
                    'description' => $costDescription,
                ]
            );
            set_projects_flash('success', 'Project cost entry added.');
        } else {
            set_projects_flash('error', 'Failed to save project cost entry.');
        }

        redirect_projects_page();
    }

    if ($action === 'add_project_payment') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $paymentDate = normalize_date_or_null($_POST['payment_date'] ?? null);
        $paymentNotes = normalize_text_or_null($_POST['payment_notes'] ?? null);
        $amountInput = $_POST['payment_amount'] ?? '';
        $paymentAmount = normalize_money_or_null($amountInput);
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $paymentSnapshot = $projectId > 0 ? getProjectPaymentSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || !$paymentSnapshot) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if ($paymentDate === null) {
            set_projects_flash('error', 'Payment date is required.');
            redirect_projects_page();
        }

        if ($amountInput === '' || $paymentAmount === null || $paymentAmount <= 0) {
            set_projects_flash('error', 'Payment amount must be greater than zero.');
            redirect_projects_page();
        }

        $totalCost = (float)($paymentSnapshot['total_cost'] ?? 0);
        $amountPaid = (float)($paymentSnapshot['amount_paid'] ?? 0);
        $remainingBalance = max(0, $totalCost - $amountPaid);

        if ($totalCost <= 0) {
            set_projects_flash('error', 'Log a project cost first before recording payments.');
            redirect_projects_page();
        }

        if ($paymentAmount > $remainingBalance + 0.00001) {
            set_projects_flash('error', 'Payment amount cannot exceed the remaining balance.');
            redirect_projects_page();
        }

        if (project_service_add_payment($conn, $projectId, $paymentDate, $paymentAmount, $paymentNotes, $createdBy)) {
            $updatedAmountPaid = $amountPaid + $paymentAmount;
            $paymentStatus = determine_payment_status($totalCost, $updatedAmountPaid);

            audit_log_event(
                $conn,
                $createdBy,
                'add_project_payment',
                'project',
                $projectId,
                null,
                [
                    'project_name' => $paymentSnapshot['project_name'] ?? null,
                    'payment_date' => $paymentDate,
                    'payment_amount' => $paymentAmount,
                    'payment_notes' => $paymentNotes,
                    'amount_paid' => $updatedAmountPaid,
                    'remaining_balance' => max(0, $totalCost - $updatedAmountPaid),
                    'payment_status' => $paymentStatus['label'],
                ]
            );
            set_projects_flash('success', 'Project payment added.');
        } else {
            set_projects_flash('error', 'Failed to save project payment.');
        }

        redirect_projects_page();
    }

    if ($action === 'update_project_status') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $status = normalize_text($_POST['status'] ?? '');
        $project = $projectId > 0 ? getProjectSnapshot($conn, $projectId) : null;
        $completedAt = $status === 'completed' ? $todayDate : null;

        if ($projectId <= 0 || !in_array($status, $statusOptions, true)) {
            set_projects_flash('error', 'Invalid project status update.');
            redirect_projects_page();
        }

        if (!$project) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if (($project['status'] ?? '') === 'completed') {
            set_projects_flash('error', 'Completed projects are locked. Use Reopen first.');
            redirect_projects_page();
        }

        if (!in_array(($project['status'] ?? ''), ['pending', 'draft'], true) && $status === 'pending') {
            set_projects_flash('error', 'A started project cannot go back to Pending. Use On-hold instead.');
            redirect_projects_page();
        }

        if (!in_array(($project['status'] ?? ''), ['pending', 'draft'], true) && $status === 'draft') {
            set_projects_flash('error', 'Only projects that have not started yet can stay in Draft.');
            redirect_projects_page();
        }

        if (project_requires_po_date($status) && empty($project['start_date'])) {
            set_projects_flash('error', 'Set the P.O Date in Project Details first before moving this project to Pending or Ongoing.');
            redirect_projects_page();
        }

        if ($status === 'completed') {
            $openTasks = countOpenProjectTasks($conn, $projectId);
            $activeDeployments = countActiveProjectInventoryDeployments($conn, $projectId);

            if (in_array(($project['status'] ?? ''), ['pending', 'draft'], true)) {
                set_projects_flash('error', 'A pending or draft project cannot jump directly to Completed. Move it to Ongoing or On-hold first.');
                redirect_projects_page();
            }

            if ($openTasks > 0) {
                set_projects_flash('error', 'Complete all open tasks before marking this project as completed.');
                redirect_projects_page();
            }

            if ($activeDeployments > 0) {
                set_projects_flash('error', 'Return all deployed inventory before marking this project as completed.');
                redirect_projects_page();
            }
        }

        if (in_array($status, ['cancelled', 'archived'], true)) {
            $activeDeployments = countActiveProjectInventoryDeployments($conn, $projectId);

            if ($activeDeployments > 0) {
                set_projects_flash('error', 'Return all deployed inventory before cancelling or archiving this project.');
                redirect_projects_page();
            }
        }

        if (project_service_update_project_status($conn, $projectId, $status, $completedAt)) {
            audit_log_event(
                $conn,
                (int)($_SESSION['user_id'] ?? 0),
                'update_project_status',
                'project',
                $projectId,
                [
                    'project_name' => $project['project_name'] ?? null,
                    'status' => $project['status'] ?? null,
                ],
                [
                    'project_name' => $project['project_name'] ?? null,
                    'status' => $status,
                    'end_date' => $completedAt,
                ]
            );
            if ($status === 'ongoing' && !project_has_budget(getProjectFinancialSnapshot($conn, $projectId))) {
                set_projects_flash('warning', 'Project status updated. No budget is set yet, but actual expenses can still be tracked.');
            } else {
                set_projects_flash('success', 'Project status updated.');
            }
        } else {
            set_projects_flash('error', 'Failed to update project status.');
        }

        redirect_projects_page();
    }

    if ($action === 'update_project_details') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $projectName = normalize_text($_POST['project_name'] ?? '');
        $description = normalize_text($_POST['description'] ?? '');
        $clientId = (int)($_POST['client_id'] ?? 0);
        $contactPerson = $hasContactPersonColumn ? normalize_text_or_null($_POST['contact_person'] ?? null) : null;
        $contactNumber = $hasContactNumberColumn ? normalize_text_or_null($_POST['contact_number'] ?? null) : null;
        $projectSite = $hasProjectSiteColumn ? normalize_text_or_null($_POST['project_site'] ?? null) : null;
        $projectAddress = $hasProjectAddressColumn ? normalize_text_or_null($_POST['project_address'] ?? null) : null;
        $projectEmail = $hasProjectEmailColumn ? normalize_text_or_null($_POST['project_email'] ?? null) : null;
        $projectCode = $hasProjectCodeColumn ? normalize_text_or_null($_POST['project_code'] ?? null) : null;
        $poNumber = $hasPoNumberColumn ? normalize_text_or_null($_POST['po_number'] ?? null) : null;
        $engineerIds = normalize_engineer_ids($_POST['engineer_ids'] ?? []);
        $startDate = normalize_date_or_null($_POST['start_date'] ?? null);
        $projectStartDate = $hasProjectStartDateColumn ? normalize_date_or_null($_POST['project_start_date'] ?? null) : null;
        $estimatedCompletionDate = $hasEstimatedCompletionDateColumn ? normalize_date_or_null($_POST['estimated_completion_date'] ?? null) : null;
        $endDate = null;
        $project = $projectId > 0 ? getProjectSnapshot($conn, $projectId) : null;
        $hasPostedAdditionalInfo = array_key_exists('additional_info', $_POST);
        $additionalInfoRows = $hasPostedAdditionalInfo
            ? normalize_project_additional_info_input($_POST['additional_info'] ?? [])
            : decode_project_additional_info($project['additional_info_json'] ?? null);
        $additionalInfoJson = encode_project_additional_info($additionalInfoRows);
        $updatedBy = (int)($_SESSION['user_id'] ?? 0);

        if ($projectId <= 0 || !$project) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if (($project['status'] ?? '') === 'completed') {
            set_projects_flash('error', 'Completed projects are locked. Reopen first before editing details.');
            redirect_projects_page();
        }

        if ($projectName === '') {
            $projectName = normalize_text((string)($project['project_name'] ?? ''));
        }

        if ($clientId <= 0) {
            $clientId = (int)($project['client_id'] ?? 0);
        }

        if ($engineerIds === []) {
            $engineerIds = normalize_engineer_ids(explode(',', (string)($project['engineer_ids_csv'] ?? '')));
        }

        if ($projectSite === null) {
            $projectSite = $hasProjectSiteColumn ? normalize_text_or_null($project['project_site'] ?? null) : null;
        }

        if ($projectAddress === null) {
            $projectAddress = $hasProjectAddressColumn ? normalize_text_or_null($project['project_address'] ?? null) : null;
        }

        if ($projectCode === null) {
            $projectCode = $hasProjectCodeColumn ? normalize_text_or_null($project['project_code'] ?? null) : null;
        }

        if ($poNumber === null) {
            $poNumber = $hasPoNumberColumn ? normalize_text_or_null($project['po_number'] ?? null) : null;
        }

        if ($startDate === null) {
            $startDate = normalize_date_or_null($project['start_date'] ?? null);
        }

        if ($projectName === '' || $clientId <= 0 || $engineerIds === []) {
            set_projects_flash('error', 'Project title, client, and assigned team member/s are required.');
            redirect_projects_page();
        }

        if (projectNameExists($conn, $projectName, $projectId)) {
            set_projects_flash('error', 'Project name already exists. Use a more specific name like site, phase, or year.');
            redirect_projects_page();
        }

        if ($hasContactPersonColumn && ($project['status'] ?? '') !== 'draft' && $contactPerson === null) {
            set_projects_flash('error', 'Client contact person is required unless the project stays in Draft.');
            redirect_projects_page();
        }

        if ($hasContactNumberColumn && ($project['status'] ?? '') !== 'draft' && $contactNumber === null) {
            set_projects_flash('error', 'Client contact number is required unless the project stays in Draft.');
            redirect_projects_page();
        }

        if ($hasProjectSiteColumn && ($project['status'] ?? '') !== 'draft' && $projectSite === null) {
            set_projects_flash('error', 'Project site is required unless the project stays in Draft.');
            redirect_projects_page();
        }

        if ($projectEmail !== null && !filter_var($projectEmail, FILTER_VALIDATE_EMAIL)) {
            set_projects_flash('error', 'Project email must be a valid email address.');
            redirect_projects_page();
        }

        foreach ($additionalInfoRows as $additionalInfoRow) {
            $additionalInfoEmail = trim((string)($additionalInfoRow['email_address'] ?? ''));
            if ($additionalInfoEmail !== '' && !filter_var($additionalInfoEmail, FILTER_VALIDATE_EMAIL)) {
                set_projects_flash('error', 'Each additional info email address must be valid.');
                redirect_projects_page();
            }
        }

        if ($projectCode === null) {
            set_projects_flash('error', 'Project code is required.');
            redirect_projects_page();
        }

        if (project_requires_po_date((string)($project['status'] ?? '')) && $poNumber === null) {
            set_projects_flash('error', 'P.O Number is required while the project is Pending or Ongoing.');
            redirect_projects_page();
        }

        if (projectFieldValueExists($conn, 'project_code', $projectCode, $projectId)) {
            set_projects_flash('error', 'Project code already exists.');
            redirect_projects_page();
        }

        if ($poNumber !== null && projectFieldValueExists($conn, 'po_number', $poNumber, $projectId)) {
            set_projects_flash('error', 'P.O Number already exists.');
            redirect_projects_page();
        }

        if (project_requires_po_date((string)($project['status'] ?? '')) && $startDate === null) {
            set_projects_flash('error', 'P.O Date is required while the project is Pending or Ongoing.');
            redirect_projects_page();
        }

        if ($startDate !== null && $startDate > $todayDate) {
            set_projects_flash('error', 'P.O Date cannot be in the future.');
            redirect_projects_page();
        }

        if ($hasProjectStartDateColumn && $projectStartDate === null) {
            set_projects_flash('error', 'Project Start Date is required.');
            redirect_projects_page();
        }

        if ($startDate !== null && $projectStartDate !== null && $projectStartDate < $startDate) {
            set_projects_flash('error', 'Project Start Date must be the same as or later than P.O Date.');
            redirect_projects_page();
        }

        if ($hasEstimatedCompletionDateColumn && $estimatedCompletionDate === null) {
            set_projects_flash('error', 'Estimated Completion Date is required.');
            redirect_projects_page();
        }

        if ($projectStartDate !== null && $estimatedCompletionDate !== null && $estimatedCompletionDate < $projectStartDate) {
            set_projects_flash('error', 'Estimated Completion Date must be the same as or later than Project Start Date.');
            redirect_projects_page();
        }

        if ($hasProjectAddressColumn && ($project['status'] ?? '') !== 'draft' && $projectAddress === null) {
            set_projects_flash('error', 'Project address is required unless the project stays in Draft.');
            redirect_projects_page();
        }

        try {
            project_service_update_project_details($conn, [
                'has_project_address_column' => $hasProjectAddressColumn,
                'has_project_email_column' => $hasProjectEmailColumn,
                'has_project_code_column' => $hasProjectCodeColumn,
                'has_po_number_column' => $hasPoNumberColumn,
                'has_project_additional_info_column' => $hasProjectAdditionalInfoColumn,
                'project_id' => $projectId,
                'project_name' => $projectName,
                'description' => $description,
                'client_id' => $clientId,
                'contact_person' => $contactPerson,
                'contact_number' => $contactNumber,
                'project_site' => $projectSite,
                'project_address' => $projectAddress,
                'project_email' => $projectEmail,
                'project_code' => $projectCode,
                'po_number' => $poNumber,
                'start_date' => $startDate,
                'project_start_date' => $projectStartDate,
                'estimated_completion_date' => $estimatedCompletionDate,
                'end_date' => $endDate,
                'engineer_ids' => $engineerIds,
                'updated_by' => $updatedBy,
                'additional_info_json' => $additionalInfoJson,
            ]);

            audit_log_event(
                $conn,
                $updatedBy,
                'update_project_details',
                'project',
                $projectId,
                [
                    'project_name' => $project['project_name'] ?? null,
                    'status' => $project['status'] ?? null,
                ],
                [
                    'project_name' => $projectName,
                    'client_id' => $clientId,
                    'engineer_ids' => $engineerIds,
                    'contact_person' => $contactPerson,
                    'contact_number' => $contactNumber,
                    'project_email' => $projectEmail,
                    'additional_info' => $additionalInfoRows,
                    'project_code' => $projectCode,
                    'po_number' => $poNumber,
                    'start_date' => $startDate,
                    'project_start_date' => $projectStartDate,
                    'estimated_completion_date' => $estimatedCompletionDate,
                    'end_date' => $endDate,
                ]
            );
            set_projects_flash('success', 'Project details updated successfully.');
        } catch (Throwable $exception) {
            set_projects_flash('error', $exception->getMessage());
        }

        redirect_projects_page();
    }

    if ($action === 'add_task') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);
        $taskName = normalize_text($_POST['task_name'] ?? '');
        $description = normalize_text($_POST['task_description'] ?? '');
        $deadline = normalize_date_or_null($_POST['deadline'] ?? null);
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $project = $projectId > 0 ? getProjectSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || $assignedTo <= 0 || $taskName === '') {
            set_projects_flash('error', 'Task name and assigned engineer are required.');
            redirect_projects_page();
        }

        if (!$project) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if (($project['status'] ?? '') === 'completed') {
            set_projects_flash('error', 'Cannot add tasks to a completed project. Reopen it first.');
            redirect_projects_page();
        }

        if (($project['status'] ?? '') === 'draft') {
            set_projects_flash('error', 'Cannot add tasks to a draft project. Change its status to Pending or Ongoing first.');
            redirect_projects_page();
        }

        if (empty($project['start_date'])) {
            set_projects_flash('error', 'Set the P.O Date first before adding tasks.');
            redirect_projects_page();
        }

        if ($deadline !== null && $deadline < $todayDate) {
            set_projects_flash('error', 'Task deadline cannot be earlier than today.');
            redirect_projects_page();
        }

        $taskId = project_service_add_task($conn, $projectId, $assignedTo, $taskName, $description, $deadline, $createdBy);

        if ($taskId !== null) {
            audit_log_event(
                $conn,
                $createdBy,
                'add_task',
                'task',
                $taskId,
                null,
                [
                    'project_name' => $project['project_name'] ?? null,
                    'task_name' => $taskName,
                    'assigned_to' => $assignedTo,
                    'deadline' => $deadline,
                ]
            );
            set_projects_flash('success', 'Task added successfully.');
        } else {
            set_projects_flash('error', 'Failed to add task.');
        }

        redirect_projects_page();
    }

    if ($action === 'reopen_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $project = $projectId > 0 ? getProjectSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || !$project) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if (($project['status'] ?? '') !== 'completed') {
            set_projects_flash('error', 'Only completed projects can be reopened.');
            redirect_projects_page();
        }

        if (project_service_reopen_project($conn, $projectId)) {
            audit_log_event(
                $conn,
                (int)($_SESSION['user_id'] ?? 0),
                'update_project_status',
                'project',
                $projectId,
                [
                    'project_name' => $project['project_name'] ?? null,
                    'status' => $project['status'] ?? null,
                ],
                [
                    'project_name' => $project['project_name'] ?? null,
                    'status' => 'ongoing',
                    'end_date' => null,
                ]
            );
            set_projects_flash('success', 'Project reopened successfully.');
        } else {
            set_projects_flash('error', 'Failed to reopen project.');
        }

        redirect_projects_page();
    }

    if ($action === 'delete_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $deletedBy = (int)($_SESSION['user_id'] ?? 0);
        $project = $projectId > 0 ? getProjectSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || !$project) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if (project_service_move_project_to_trash($conn, $projectId, $deletedBy)) {
            audit_log_event(
                $conn,
                $deletedBy,
                'delete_project',
                'project',
                $projectId,
                [
                    'project_name' => $project['project_name'] ?? null,
                    'status' => $project['status'] ?? null,
                ],
                [
                    'deleted_at' => date('Y-m-d H:i:s'),
                    'delete_scheduled_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
                ]
            );
            set_projects_flash('success', 'Project moved to trash. It will be permanently deleted after 30 days.');
        } else {
            set_projects_flash('error', 'Failed to move project to trash.');
        }

        redirect_projects_page();
    }

    if ($action === 'restore_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $restoredBy = (int)($_SESSION['user_id'] ?? 0);
        $project = $projectId > 0 ? getDeletedProjectSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || !$project) {
            set_projects_flash('error', 'Trashed project not found.');
            redirect_projects_page();
        }

        if (project_service_restore_project($conn, $projectId, $restoredBy)) {
            audit_log_event(
                $conn,
                $restoredBy,
                'restore_project',
                'project',
                $projectId,
                [
                    'deleted_at' => $project['deleted_at'] ?? null,
                    'delete_scheduled_at' => $project['delete_scheduled_at'] ?? null,
                ],
                [
                    'project_name' => $project['project_name'] ?? null,
                    'status' => $project['status'] ?? null,
                    'restored_at' => date('Y-m-d H:i:s'),
                ]
            );
            set_projects_flash('success', 'Project restored from trash.');
        } else {
            set_projects_flash('error', 'Failed to restore project.');
        }

        redirect_projects_page();
    }

    if ($action === 'permanently_delete_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $deletedBy = (int)($_SESSION['user_id'] ?? 0);
        $project = $projectId > 0 ? getDeletedProjectSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || !$project) {
            set_projects_flash('error', 'Trashed project not found.');
            redirect_projects_page();
        }

        if (project_service_permanently_delete_project($conn, $projectId)) {
            audit_log_event(
                $conn,
                $deletedBy,
                'permanently_delete_project',
                'project',
                $projectId,
                [
                    'project_name' => $project['project_name'] ?? null,
                    'status' => $project['status'] ?? null,
                    'deleted_at' => $project['deleted_at'] ?? null,
                    'delete_scheduled_at' => $project['delete_scheduled_at'] ?? null,
                ],
                [
                    'deleted_forever_at' => date('Y-m-d H:i:s'),
                ]
            );
            set_projects_flash('success', 'Project permanently deleted.');
        } else {
            set_projects_flash('error', 'Failed to permanently delete project.');
        }

        redirect_projects_page();
    }

    if ($action === 'permanently_delete_supplier') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $deletedBy = (int)($_SESSION['user_id'] ?? 0);

        if ($supplierId <= 0 || !table_exists($conn, 'suppliers')) {
            set_projects_flash('error', 'Trashed supplier not found.');
            redirect_projects_page();
        }

        $supplierStmt = $conn->prepare(
            "SELECT id, supplier_code, supplier_name, contact_person, status
             FROM suppliers s
             WHERE id = ?
             AND status = 'inactive'
             LIMIT 1"
        );
        $supplier = null;
        if ($supplierStmt) {
            $supplierStmt->bind_param('i', $supplierId);
            $supplierStmt->execute();
            $supplierResult = $supplierStmt->get_result();
            $supplier = $supplierResult ? $supplierResult->fetch_assoc() : null;
        }

        if (!$supplier) {
            set_projects_flash('error', 'Trashed supplier not found.');
            redirect_projects_page();
        }

        $linkedOrders = 0;
        if (table_exists($conn, 'purchase_orders')) {
            $linkedOrderStmt = $conn->prepare('SELECT COUNT(*) AS total FROM purchase_orders WHERE supplier_id = ?');
            if ($linkedOrderStmt) {
                $linkedOrderStmt->bind_param('i', $supplierId);
                $linkedOrderStmt->execute();
                $linkedOrderResult = $linkedOrderStmt->get_result();
                $linkedOrders = (int)(($linkedOrderResult ? $linkedOrderResult->fetch_assoc() : [])['total'] ?? 0);
            }
        }

        if ($linkedOrders > 0) {
            set_projects_flash('error', 'Supplier cannot be permanently deleted because it is linked to purchase orders.');
            redirect_projects_page();
        }

        $deleteSupplier = $conn->prepare("DELETE FROM suppliers WHERE id = ? AND status = 'inactive'");
        if ($deleteSupplier && $deleteSupplier->bind_param('i', $supplierId) && $deleteSupplier->execute() && $deleteSupplier->affected_rows > 0) {
            audit_log_event(
                $conn,
                $deletedBy,
                'permanently_delete_supplier',
                'supplier',
                $supplierId,
                $supplier,
                ['deleted_forever_at' => date('Y-m-d H:i:s')]
            );
            set_projects_flash('success', 'Supplier permanently deleted from trash bin.');
        } else {
            set_projects_flash('error', 'Failed to permanently delete supplier.');
        }

        redirect_projects_page();
    }

    if ($action === 'restore_supplier') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $restoredBy = (int)($_SESSION['user_id'] ?? 0);

        if ($supplierId <= 0 || !table_exists($conn, 'suppliers')) {
            set_projects_flash('error', 'Trashed supplier not found.');
            redirect_projects_page();
        }

        $supplierStmt = $conn->prepare(
            "SELECT id, supplier_code, supplier_name, contact_person, status
             FROM suppliers
             WHERE id = ?
             AND status = 'inactive'
             LIMIT 1"
        );
        $supplier = null;
        if ($supplierStmt) {
            $supplierStmt->bind_param('i', $supplierId);
            $supplierStmt->execute();
            $supplierResult = $supplierStmt->get_result();
            $supplier = $supplierResult ? $supplierResult->fetch_assoc() : null;
        }

        if (!$supplier) {
            set_projects_flash('error', 'Trashed supplier not found.');
            redirect_projects_page();
        }

        $restoreSupplier = $conn->prepare("UPDATE suppliers SET status = 'active' WHERE id = ? AND status = 'inactive'");
        if ($restoreSupplier && $restoreSupplier->bind_param('i', $supplierId) && $restoreSupplier->execute() && $restoreSupplier->affected_rows > 0) {
            audit_log_event(
                $conn,
                $restoredBy,
                'restore_supplier',
                'supplier',
                $supplierId,
                $supplier,
                [
                    'supplier_code' => $supplier['supplier_code'] ?? null,
                    'supplier_name' => $supplier['supplier_name'] ?? null,
                    'status' => 'active',
                    'restored_at' => date('Y-m-d H:i:s'),
                ]
            );
            set_projects_flash('success', 'Supplier restored from trash.');
        } else {
            set_projects_flash('error', 'Failed to restore supplier.');
        }

        redirect_projects_page();
    }

    if ($action === 'permanently_delete_purchase_request') {
        $purchaseRequestId = (int)($_POST['purchase_request_id'] ?? 0);
        $deletedBy = (int)($_SESSION['user_id'] ?? 0);

        if ($purchaseRequestId <= 0 || !table_exists($conn, 'purchase_requests')) {
            set_projects_flash('error', 'Trashed purchase request not found.');
            redirect_projects_page();
        }

        $requestStmt = $conn->prepare(
            "SELECT id, request_no, status
             FROM purchase_requests
             WHERE id = ?
             AND status = 'cancelled'
             LIMIT 1"
        );
        $request = null;
        if ($requestStmt) {
            $requestStmt->bind_param('i', $purchaseRequestId);
            $requestStmt->execute();
            $requestResult = $requestStmt->get_result();
            $request = $requestResult ? $requestResult->fetch_assoc() : null;
        }

        if (!$request) {
            set_projects_flash('error', 'Trashed purchase request not found.');
            redirect_projects_page();
        }

        $linkedOrders = 0;
        if (table_exists($conn, 'purchase_orders')) {
            $linkedOrderStmt = $conn->prepare('SELECT COUNT(*) AS total FROM purchase_orders WHERE purchase_request_id = ?');
            if ($linkedOrderStmt) {
                $linkedOrderStmt->bind_param('i', $purchaseRequestId);
                $linkedOrderStmt->execute();
                $linkedOrderResult = $linkedOrderStmt->get_result();
                $linkedOrders = (int)(($linkedOrderResult ? $linkedOrderResult->fetch_assoc() : [])['total'] ?? 0);
            }
        }

        if ($linkedOrders > 0) {
            set_projects_flash('error', 'Purchase request cannot be permanently deleted because it already has a purchase order.');
            redirect_projects_page();
        }

        $deleteRequest = $conn->prepare("DELETE FROM purchase_requests WHERE id = ? AND status = 'cancelled'");
        if ($deleteRequest && $deleteRequest->bind_param('i', $purchaseRequestId) && $deleteRequest->execute() && $deleteRequest->affected_rows > 0) {
            audit_log_event(
                $conn,
                $deletedBy,
                'permanently_delete_purchase_request',
                'purchase_request',
                $purchaseRequestId,
                $request,
                ['deleted_forever_at' => date('Y-m-d H:i:s')]
            );
            set_projects_flash('success', 'Purchase request permanently deleted from trash bin.');
        } else {
            set_projects_flash('error', 'Failed to permanently delete purchase request.');
        }

        redirect_projects_page();
    }

    if ($action === 'restore_purchase_request') {
        $purchaseRequestId = (int)($_POST['purchase_request_id'] ?? 0);
        $restoredBy = (int)($_SESSION['user_id'] ?? 0);

        if ($purchaseRequestId <= 0 || !table_exists($conn, 'purchase_requests')) {
            set_projects_flash('error', 'Trashed purchase request not found.');
            redirect_projects_page();
        }

        $requestStmt = $conn->prepare(
            "SELECT id, request_no, status
             FROM purchase_requests
             WHERE id = ?
             AND status = 'cancelled'
             LIMIT 1"
        );
        $request = null;
        if ($requestStmt) {
            $requestStmt->bind_param('i', $purchaseRequestId);
            $requestStmt->execute();
            $requestResult = $requestStmt->get_result();
            $request = $requestResult ? $requestResult->fetch_assoc() : null;
        }

        if (!$request) {
            set_projects_flash('error', 'Trashed purchase request not found.');
            redirect_projects_page();
        }

        $restoreRequest = $conn->prepare("UPDATE purchase_requests SET status = 'submitted' WHERE id = ? AND status = 'cancelled'");
        if ($restoreRequest && $restoreRequest->bind_param('i', $purchaseRequestId) && $restoreRequest->execute() && $restoreRequest->affected_rows > 0) {
            audit_log_event(
                $conn,
                $restoredBy,
                'restore_purchase_request',
                'purchase_request',
                $purchaseRequestId,
                $request,
                [
                    'request_no' => $request['request_no'] ?? null,
                    'status' => 'submitted',
                    'restored_at' => date('Y-m-d H:i:s'),
                ]
            );
            set_projects_flash('success', 'Purchase request restored from trash.');
        } else {
            set_projects_flash('error', 'Failed to restore purchase request.');
        }

        redirect_projects_page();
    }

    if ($action === 'deploy_inventory_to_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $inventoryId = (int)($_POST['inventory_id'] ?? 0);
        $quantity = normalize_positive_int($_POST['deployment_quantity'] ?? 0);
        $notes = normalize_text_or_null($_POST['deployment_notes'] ?? null);
        $deployedBy = (int)($_SESSION['user_id'] ?? 0);
        $project = $projectId > 0 ? getProjectSnapshot($conn, $projectId) : null;

        if ($projectId <= 0 || $inventoryId <= 0 || $quantity <= 0) {
            set_projects_flash('error', 'Project, inventory item, and quantity are required for deployment.');
            redirect_projects_page();
        }

        if (!$project) {
            set_projects_flash('error', 'Project not found.');
            redirect_projects_page();
        }

        if (($project['status'] ?? '') === 'draft') {
            set_projects_flash('error', 'Cannot deploy assets to a draft project.');
            redirect_projects_page();
        }

        if (($project['status'] ?? '') === 'completed') {
            set_projects_flash('error', 'Cannot deploy assets to a completed project.');
            redirect_projects_page();
        }

        if (empty($project['start_date'])) {
            set_projects_flash('error', 'Set the P.O Date first before deploying inventory.');
            redirect_projects_page();
        }

        $inventoryStmt = $conn->prepare(
            'SELECT i.id, i.quantity, i.min_stock, a.asset_name
             FROM inventory i
             INNER JOIN assets a ON a.id = i.asset_id
             WHERE i.id = ?
             LIMIT 1'
        );

        if (!$inventoryStmt) {
            set_projects_flash('error', 'Failed to prepare inventory lookup.');
            redirect_projects_page();
        }

        $inventoryStmt->bind_param('i', $inventoryId);
        $inventoryStmt->execute();
        $inventoryResult = $inventoryStmt->get_result();
        $inventoryItem = $inventoryResult ? $inventoryResult->fetch_assoc() : null;

        if (!$inventoryItem) {
            set_projects_flash('error', 'Selected inventory item not found.');
            redirect_projects_page();
        }

        $availableQuantity = (int)($inventoryItem['quantity'] ?? 0);

        if ($availableQuantity < $quantity) {
            set_projects_flash('error', 'Not enough stock available for that deployment quantity.');
            redirect_projects_page();
        }

        $remainingQuantity = $availableQuantity - $quantity;
        $minStock = array_key_exists('min_stock', $inventoryItem) && $inventoryItem['min_stock'] !== null
            ? (int)$inventoryItem['min_stock']
            : null;
        $nextStatus = determine_inventory_status($remainingQuantity, $minStock);

        $conn->begin_transaction();

        try {
            asset_units_sync_for_inventory($conn, $inventoryId);

            $deployStmt = $conn->prepare(
                'INSERT INTO project_inventory_deployments (project_id, inventory_id, quantity, deployed_by, notes)
                 VALUES (?, ?, ?, ?, ?)'
            );

            if (
                !$deployStmt ||
                !$deployStmt->bind_param('iiiis', $projectId, $inventoryId, $quantity, $deployedBy, $notes) ||
                !$deployStmt->execute()
            ) {
                throw new RuntimeException('Failed to save project inventory deployment.');
            }

            $updateInventory = $conn->prepare(
                'UPDATE inventory
                 SET quantity = ?, status = ?
                 WHERE id = ?'
            );

            if (
                !$updateInventory ||
                !$updateInventory->bind_param('isi', $remainingQuantity, $nextStatus, $inventoryId) ||
                !$updateInventory->execute()
            ) {
                throw new RuntimeException('Failed to update inventory quantity after deployment.');
            }

            $assignedUnitCodes = asset_units_assign_available_to_deployment($conn, (int)$deployStmt->insert_id, $inventoryId, $quantity);

            $conn->commit();
            audit_log_event(
                $conn,
                $deployedBy,
                'deploy_inventory_to_project',
                'deployment',
                (int)$deployStmt->insert_id,
                null,
                [
                    'project_name' => $project['project_name'] ?? null,
                    'asset_name' => $inventoryItem['asset_name'] ?? null,
                    'quantity' => $quantity,
                    'remaining_quantity' => $remainingQuantity,
                    'unit_codes' => $assignedUnitCodes,
                ]
            );
            set_projects_flash('success', 'Inventory deployed to project successfully.');
        } catch (Throwable $exception) {
            $conn->rollback();
            set_projects_flash('error', $exception->getMessage());
        }

        redirect_projects_page();
    }

    if ($action === 'return_project_inventory') {
        $deploymentId = (int)($_POST['deployment_id'] ?? 0);
        $returnQuantity = normalize_positive_int($_POST['return_quantity'] ?? 0);
        $returnNotes = normalize_text_or_null($_POST['return_notes'] ?? null);
        $returnedBy = (int)($_SESSION['user_id'] ?? 0);
        $deployment = $deploymentId > 0 ? getActiveProjectInventoryDeployment($conn, $deploymentId) : null;

        if (!$deployment) {
            set_projects_flash('error', 'Active inventory deployment not found.');
            redirect_projects_page();
        }

        if ($returnQuantity <= 0) {
            set_projects_flash('error', 'Return quantity must be greater than zero.');
            redirect_projects_page();
        }

        $remainingQuantity = (int)($deployment['remaining_quantity'] ?? 0);

        if ($returnQuantity > $remainingQuantity) {
            set_projects_flash('error', 'Return quantity cannot be greater than the remaining deployed quantity.');
            redirect_projects_page();
        }

        $inventoryStmt = $conn->prepare(
            'SELECT id, quantity, min_stock
             FROM inventory
             WHERE id = ?
             LIMIT 1'
        );

        if (!$inventoryStmt) {
            set_projects_flash('error', 'Failed to prepare inventory lookup for return.');
            redirect_projects_page();
        }

        $inventoryId = (int)$deployment['inventory_id'];

        $inventoryStmt->bind_param('i', $inventoryId);
        $inventoryStmt->execute();
        $inventoryResult = $inventoryStmt->get_result();
        $inventoryItem = $inventoryResult ? $inventoryResult->fetch_assoc() : null;

        if (!$inventoryItem) {
            set_projects_flash('error', 'Inventory record not found for this deployment.');
            redirect_projects_page();
        }

        $nextQuantity = (int)$inventoryItem['quantity'] + $returnQuantity;
        $minStock = $inventoryItem['min_stock'] !== null ? (int)$inventoryItem['min_stock'] : null;
        $nextStatus = determine_inventory_status($nextQuantity, $minStock);
        $willBeFullyReturned = $returnQuantity === $remainingQuantity;

        $conn->begin_transaction();

        try {
            asset_units_sync_for_inventory($conn, $inventoryId);

            $logReturn = $conn->prepare(
                'INSERT INTO project_inventory_return_logs (deployment_id, quantity, returned_by, notes)
                 VALUES (?, ?, ?, ?)'
            );

            if (
                !$logReturn ||
                !$logReturn->bind_param('iiis', $deploymentId, $returnQuantity, $returnedBy, $returnNotes) ||
                !$logReturn->execute()
            ) {
                throw new RuntimeException('Failed to save inventory return log.');
            }

            $returnStmt = $conn->prepare(
                'UPDATE project_inventory_deployments
                 SET returned_at = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP ELSE returned_at END
                 WHERE id = ?
                 AND (returned_at IS NULL OR ? = 0)'
            );

            if (
                !$returnStmt ||
                !$returnStmt->bind_param('iii', $willBeFullyReturned, $deploymentId, $willBeFullyReturned) ||
                !$returnStmt->execute()
            ) {
                throw new RuntimeException('Failed to mark the deployment as returned.');
            }

            $updateInventory = $conn->prepare(
                'UPDATE inventory
                 SET quantity = ?, status = ?
                 WHERE id = ?'
            );

            if (
                !$updateInventory ||
                !$updateInventory->bind_param('isi', $nextQuantity, $nextStatus, $inventoryId) ||
                !$updateInventory->execute()
            ) {
                throw new RuntimeException('Failed to restore inventory quantity.');
            }

            $returnedUnitCodes = asset_units_release_from_deployment($conn, $deploymentId, $returnQuantity);

            $conn->commit();
            audit_log_event(
                $conn,
                $returnedBy,
                'return_project_inventory',
                'deployment',
                $deploymentId,
                [
                    'quantity' => $remainingQuantity,
                ],
                [
                    'asset_name' => $deployment['asset_name'] ?? null,
                    'quantity' => $returnQuantity,
                    'next_inventory_quantity' => $nextQuantity,
                    'unit_codes' => $returnedUnitCodes,
                ]
            );
            set_projects_flash('success', 'Inventory return saved successfully.');
        } catch (Throwable $exception) {
            $conn->rollback();
            set_projects_flash('error', $exception->getMessage());
        }

        redirect_projects_page();
    }
}

$flash = $_SESSION['projects_flash'] ?? null;
unset($_SESSION['projects_flash']);
$createProjectOldInput = $_SESSION['projects_old_input'] ?? [];
unset($_SESSION['projects_old_input']);
$createProjectFocusField = trim((string)($createProjectOldInput['focus_field'] ?? ''));
$hasCreateProjectServerInput = !empty($createProjectOldInput);
$shouldClearCreateProjectDraft = is_array($flash)
    && ($flash['type'] ?? '') === 'success'
    && ($flash['message'] ?? '') === 'Project created successfully.';

$createProjectValues = [
    'project_name' => (string)($createProjectOldInput['project_name'] ?? ''),
    'description' => (string)($createProjectOldInput['description'] ?? ''),
    'contact_person' => (string)($createProjectOldInput['contact_person'] ?? ''),
    'contact_number' => (string)($createProjectOldInput['contact_number'] ?? ''),
    'project_site' => (string)($createProjectOldInput['project_site'] ?? ''),
    'project_address' => (string)($createProjectOldInput['project_address'] ?? ''),
    'project_email' => (string)($createProjectOldInput['project_email'] ?? ''),
    'additional_info' => project_additional_info_rows_for_form($createProjectOldInput['additional_info'] ?? []),
    'project_code' => (string)($createProjectOldInput['project_code'] ?? ''),
    'project_source' => (string)($createProjectOldInput['project_source'] ?? 'walk_in'),
    'po_number' => (string)($createProjectOldInput['po_number'] ?? ''),
    'client_id' => (string)($createProjectOldInput['client_id'] ?? ''),
    'engineer_ids' => array_values(array_map('strval', is_array($createProjectOldInput['engineer_ids'] ?? null) ? $createProjectOldInput['engineer_ids'] : [])),
    'status' => (string)($createProjectOldInput['status'] ?? 'pending'),
    'start_date' => array_key_exists('start_date', $createProjectOldInput) ? (string)$createProjectOldInput['start_date'] : $todayDate,
    'project_start_date' => (string)($createProjectOldInput['project_start_date'] ?? ''),
    'estimated_completion_date' => (string)($createProjectOldInput['estimated_completion_date'] ?? ''),
    'estimated_duration_days' => (string)($createProjectOldInput['estimated_duration_days'] ?? ''),
    'budget_amount' => (string)($createProjectOldInput['budget_amount'] ?? ''),
    'budget_notes' => (string)($createProjectOldInput['budget_notes'] ?? ''),
];

$nextProjectCode = $hasProjectCodeColumn ? generate_next_project_code($conn) : '';
if ($hasProjectCodeColumn && $createProjectValues['project_code'] === '') {
    $createProjectValues['project_code'] = $nextProjectCode;
}

if ($createProjectValues['status'] === '' || !in_array($createProjectValues['status'], $initialStatusOptions, true)) {
    $createProjectValues['status'] = 'pending';
}

$clients = [];
$engineers = [];

$clientResult = $conn->query("SELECT id, full_name, email, phone FROM users WHERE role = 'client' AND status = 'active' ORDER BY full_name ASC");
if ($clientResult) {
    $clients = $clientResult->fetch_all(MYSQLI_ASSOC);
}

$engineerResult = $conn->query("SELECT id, full_name, role FROM users WHERE role IN ('engineer', 'foreman') AND status = 'active' ORDER BY full_name ASC");
if ($engineerResult) {
    $engineers = $engineerResult->fetch_all(MYSQLI_ASSOC);
}

$projects = [];
$searchQuery = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}
$view = trim((string)($_GET['view'] ?? ''));
$isTrashView = $view === 'trash';
$trashFilterSql = $isTrashView ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$offset = ($currentPage - 1) * $perPage;

$taskMetricsResult = $conn->query("SELECT COUNT(*) AS total_tasks FROM tasks");
$taskMetrics = $taskMetricsResult ? $taskMetricsResult->fetch_assoc() : [];
$totalTasks = (int)($taskMetrics['total_tasks'] ?? 0);
$statusCounts = array_fill_keys($statusOptions, 0);

$statusCountsResult = $conn->query("
    SELECT p.status, COUNT(*) AS total
    FROM projects p
    WHERE p.deleted_at IS NULL
    GROUP BY p.status
");
if ($statusCountsResult) {
    while ($statusRow = $statusCountsResult->fetch_assoc()) {
        $statusKey = (string)($statusRow['status'] ?? '');
        if (array_key_exists($statusKey, $statusCounts)) {
            $statusCounts[$statusKey] = (int)($statusRow['total'] ?? 0);
        }
    }
}
$totalProjects = array_sum($statusCounts);
$activeProjects = (int)($statusCounts['pending'] ?? 0)
    + (int)($statusCounts['ongoing'] ?? 0)
    + (int)($statusCounts['on-hold'] ?? 0);
$ongoingProjects = (int)($statusCounts['ongoing'] ?? 0);
$completedProjects = (int)($statusCounts['completed'] ?? 0);
$atRiskProjects = (int)($statusCounts['on-hold'] ?? 0);
$overdueProjectsResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM projects
    WHERE deleted_at IS NULL
    AND status IN ('pending', 'ongoing')
    AND estimated_completion_date IS NOT NULL
    AND estimated_completion_date < CURDATE()
");
if ($overdueProjectsResult) {
    $atRiskProjects += (int)(($overdueProjectsResult->fetch_assoc() ?: [])['total'] ?? 0);
}

$trashMetricsResult = $conn->query("
    SELECT COUNT(*) AS total_trashed
    FROM projects
    WHERE deleted_at IS NOT NULL
");
$trashMetrics = $trashMetricsResult ? $trashMetricsResult->fetch_assoc() : [];
$trashedProjects = (int)($trashMetrics['total_trashed'] ?? 0);
$trashedUsers = 0;
$trashedSuppliers = 0;
$trashedPurchaseRequests = 0;
$trashedAssets = 0;
$trashedUserRows = [];
$trashedAssetRows = [];
$trashedSupplierRows = [];
$trashedPurchaseRequestRows = [];

$trashedUserMetrics = $conn->query("
    SELECT COUNT(*) AS total_trashed
    FROM users
    WHERE deleted_at IS NOT NULL
    AND role IN ('engineer', 'foreman', 'foremen', 'client')
");
$trashedUsers = (int)(($trashedUserMetrics ? $trashedUserMetrics->fetch_assoc() : [])['total_trashed'] ?? 0);

if ($isTrashView) {
    $trashedUserResult = $conn->query(
        "SELECT
            id,
            full_name,
            email,
            phone,
            role,
            status,
            deleted_at
         FROM users
         WHERE deleted_at IS NOT NULL
         AND role IN ('engineer', 'foreman', 'foremen', 'client')
         ORDER BY deleted_at DESC, id DESC
         LIMIT 24"
    );

    if ($trashedUserResult) {
        $trashedUserRows = $trashedUserResult->fetch_all(MYSQLI_ASSOC);
    }
}

if (table_exists($conn, 'suppliers')) {
    $trashedSupplierMetrics = $conn->query("SELECT COUNT(*) AS total_trashed FROM suppliers WHERE status = 'inactive'");
    $trashedSuppliers = (int)(($trashedSupplierMetrics ? $trashedSupplierMetrics->fetch_assoc() : [])['total_trashed'] ?? 0);

    if ($isTrashView) {
        $trashedSupplierResult = $conn->query(
            "SELECT
                s.id,
                s.supplier_code,
                s.supplier_name,
                s.contact_person,
                s.contact_number,
                s.email,
                s.address,
                s.description,
                s.updated_at,
                (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS linked_purchase_orders
             FROM suppliers s
             WHERE s.status = 'inactive'
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT 24"
        );
        if ($trashedSupplierResult) {
            $trashedSupplierRows = $trashedSupplierResult->fetch_all(MYSQLI_ASSOC);
        }
    }
}

if (table_exists($conn, 'purchase_requests')) {
    $trashedRequestMetrics = $conn->query("SELECT COUNT(*) AS total_trashed FROM purchase_requests WHERE status = 'cancelled'");
    $trashedPurchaseRequests = (int)(($trashedRequestMetrics ? $trashedRequestMetrics->fetch_assoc() : [])['total_trashed'] ?? 0);

    if ($isTrashView) {
        $trashedRequestResult = $conn->query(
            "SELECT
                pr.id,
                pr.request_no,
                pr.request_type,
                pr.needed_date,
                pr.site_location,
                pr.remarks,
                pr.updated_at,
                p.project_name,
                u.full_name AS requested_by_name,
                pri.item_description,
                pri.unit,
                pri.quantity_requested,
                (SELECT COUNT(*) FROM purchase_orders po WHERE po.purchase_request_id = pr.id) AS linked_purchase_orders
             FROM purchase_requests pr
             INNER JOIN projects p ON p.id = pr.project_id
             INNER JOIN users u ON u.id = pr.requested_by
             LEFT JOIN purchase_request_items pri ON pri.purchase_request_id = pr.id
             WHERE pr.status = 'cancelled'
             ORDER BY pr.updated_at DESC, pri.id ASC
             LIMIT 24"
        );
        if ($trashedRequestResult) {
            $trashedPurchaseRequestRows = $trashedRequestResult->fetch_all(MYSQLI_ASSOC);
        }
    }
}

if (table_exists($conn, 'assets')) {
    $trashedAssetMetrics = $conn->query("SELECT COUNT(*) AS total_trashed FROM assets WHERE deleted_at IS NOT NULL");
    $trashedAssets = (int)(($trashedAssetMetrics ? $trashedAssetMetrics->fetch_assoc() : [])['total_trashed'] ?? 0);

    if ($isTrashView) {
        $trashedAssetResult = $conn->query(
            "SELECT
                a.id,
                a.asset_name,
                a.asset_category,
                a.asset_type,
                a.serial_number,
                a.asset_status,
                a.criticality,
                a.deleted_at,
                i.quantity AS inventory_quantity,
                i.min_stock AS inventory_min_stock,
                i.status AS inventory_status,
                COALESCE(unit_counts.available_units, 0) AS available_units,
                COALESCE(unit_counts.deployed_units, 0) AS deployed_units,
                COALESCE(unit_counts.maintenance_units, 0) AS maintenance_units,
                COALESCE(unit_counts.lost_units, 0) AS lost_units
             FROM assets a
             LEFT JOIN inventory i ON i.asset_id = a.id
             LEFT JOIN (
                SELECT
                    asset_id,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_units,
                    SUM(CASE WHEN status = 'deployed' THEN 1 ELSE 0 END) AS deployed_units,
                    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_units,
                    SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_units
                FROM asset_units
                WHERE status <> 'archived'
                GROUP BY asset_id
             ) unit_counts ON unit_counts.asset_id = a.id
             WHERE a.deleted_at IS NOT NULL
             ORDER BY a.deleted_at DESC, a.id DESC
             LIMIT 24"
        );
        if ($trashedAssetResult) {
            $trashedAssetRows = $trashedAssetResult->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$trashBinTotal = $trashedProjects + $trashedUsers + $trashedSuppliers + $trashedPurchaseRequests + $trashedAssets;

$filteredProjects = project_search_fetch_count($conn, $hasProjectAddressColumn, $hasProjectEmailColumn, $hasProjectCodeColumn, $hasPoNumberColumn, $hasProjectSiteColumn, $hasContactPersonColumn, $hasContactNumberColumn, $searchQuery, $statusFilter, $trashFilterSql);

$totalPages = max(1, (int)ceil($filteredProjects / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $perPage;
}

$projects = project_search_fetch_page($conn, $hasProjectAddressColumn, $hasProjectEmailColumn, $hasProjectCodeColumn, $hasPoNumberColumn, $hasProjectSiteColumn, $hasContactPersonColumn, $hasContactNumberColumn, $searchQuery, $statusFilter, $perPage, $offset, $trashFilterSql);
$projectIds = array_map(static fn(array $project): int => (int)($project['id'] ?? 0), $projects);
$recentProjectCosts = fetchRecentProjectCostEntries($conn, $projectIds);
$financialSummaryResult = $conn->query(
    "SELECT
        COALESCE(SUM(COALESCE(bp.budget_amount, 0)), 0) AS total_budget,
        COALESCE(SUM(COALESCE(cost_totals.total_cost, 0)), 0) AS total_cost,
        SUM(CASE WHEN COALESCE(bp.budget_amount, 0) > 0 THEN 1 ELSE 0 END) AS projects_with_budget,
        COALESCE(SUM(COALESCE(cost_totals.cost_entry_count, 0)), 0) AS total_cost_entries,
        COUNT(p.id) AS budget_eligible_projects
     FROM projects p
     LEFT JOIN project_budget_profiles bp ON bp.project_id = p.id
     LEFT JOIN (
        SELECT project_id, SUM(amount) AS total_cost, COUNT(*) AS cost_entry_count
        FROM project_cost_entries
        GROUP BY project_id
     ) cost_totals ON cost_totals.project_id = p.id
     WHERE p.deleted_at IS NULL
     AND p.status NOT IN ('draft', 'cancelled', 'archived')"
);
$financialSummary = $financialSummaryResult ? $financialSummaryResult->fetch_assoc() : [];
$totalBudgetAmount = (float)($financialSummary['total_budget'] ?? 0);
$totalTrackedCost = (float)($financialSummary['total_cost'] ?? 0);
$projectsWithBudget = (int)($financialSummary['projects_with_budget'] ?? 0);
$totalCostEntries = (int)($financialSummary['total_cost_entries'] ?? 0);
$budgetEligibleProjects = (int)($financialSummary['budget_eligible_projects'] ?? 0);
$budgetCoverageRate = $budgetEligibleProjects > 0 ? round(($projectsWithBudget / $budgetEligibleProjects) * 100) : 0;
$portfolioRemainingBudget = $totalBudgetAmount - $totalTrackedCost;
?>
<?php
$adminPageTitle = 'Project Management - Admin';

$adminCssFiles = [
    '/codesamplecaps/ADMIN/css/super_admin_dashboard.css',
    '/codesamplecaps/ADMIN/sidebar/projects/css/projects.css', 
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

    <main class="main-content projects-content">
        <div class="page-stack">
            <?php if ($flash): ?>
                <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : (($flash['type'] ?? '') === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (!$isTrashView): ?>
            <section class="metrics-grid">
                <article class="metric-card">
                    <span>Active Projects</span>
                    <strong><?php echo $activeProjects; ?></strong>
                </article>
                <article class="metric-card">
                    <span>Ongoing Projects</span>
                    <strong><?php echo $ongoingProjects; ?></strong>
                </article>
                <article class="metric-card">
                    <span>Delayed / At Risk</span>
                    <strong><?php echo $atRiskProjects; ?></strong>
                </article>
                <article class="metric-card">
                    <span>Completed Projects</span>
                    <strong><?php echo $completedProjects; ?></strong>
                </article>
            </section>
            <?php endif; ?>

            <?php if (!$isTrashView): ?>
            <section class="project-create-modal<?php echo $hasCreateProjectServerInput ? ' is-open' : ''; ?>" id="create-project" data-create-project-panel aria-hidden="<?php echo $hasCreateProjectServerInput ? 'false' : 'true'; ?>">
                <div class="form-panel project-create-panel" role="dialog" aria-modal="true" aria-labelledby="create-project-title">
                <div class="project-create-header">
                    <div>
                        <h6 class="section-title-inline project-create-title" id="create-project-title">Create Project</h6>
                    </div>
                    <div class="project-create-header-actions">
                        <button type="button" class="btn-secondary" id="create-project-clear-details">Clear Details</button>
                        <button type="button" class="project-modal-close" data-create-project-close aria-label="Close create project">&times;</button>
                    </div>
                </div>
                <form
                    method="POST"
                    id="create-project-form"
                    class="project-create-form"
                    data-focus-field="<?php echo htmlspecialchars($createProjectFocusField, ENT_QUOTES); ?>"
                    data-has-server-draft="<?php echo $hasCreateProjectServerInput ? 'true' : 'false'; ?>"
                    data-should-clear-stored-draft="<?php echo $shouldClearCreateProjectDraft ? 'true' : 'false'; ?>"
                    data-default-project-code="<?php echo htmlspecialchars($createProjectValues['project_code'], ENT_QUOTES); ?>"
                    data-today-date="<?php echo htmlspecialchars($todayDate, ENT_QUOTES); ?>"
                    data-blank-additional-info="<?php echo htmlspecialchars(json_encode(blank_project_additional_info_row(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES); ?>"
                >
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_project">
                    <input type="hidden" name="project_source" value="<?php echo htmlspecialchars((string)($createProjectValues['project_source'] ?? 'walk_in'), ENT_QUOTES); ?>" data-project-source-input>

                    <div class="project-create-card-grid">
                        <div class="project-create-card project-create-card--client">
                            <div class="project-create-section-heading">
                                <span>Client Information</span>
                            </div>
                        <div class="input-group">
                            <label for="client_id">Client <span class="required-indicator" aria-hidden="true">*</span></label>
                            <select id="client_id" name="client_id" required>
                                <option value="">Select client</option>
                                <?php foreach ($clients as $client): ?>
                                    <option
                                        value="<?php echo (int)$client['id']; ?>"
                                        data-client-name="<?php echo htmlspecialchars((string)$client['full_name'], ENT_QUOTES); ?>"
                                        data-client-email="<?php echo htmlspecialchars((string)($client['email'] ?? ''), ENT_QUOTES); ?>"
                                        data-client-phone="<?php echo htmlspecialchars((string)($client['phone'] ?? ''), ENT_QUOTES); ?>"
                                        <?php echo $createProjectValues['client_id'] === (string)$client['id'] ? 'selected' : ''; ?>
                                    ><?php echo htmlspecialchars($client['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($hasContactPersonColumn): ?>
                            <div class="input-group">
                                <div class="field-label-row">
                                    <label for="contact_person">Client Contact Person <span class="required-indicator" aria-hidden="true">*</span></label>
                                    <button type="button" class="field-tip" aria-label="Client contact person help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">Enter the main client representative for this project. Required unless the project stays in Draft.</span>
                                    </button>
                                </div>
                                <input type="text" id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($createProjectValues['contact_person']); ?>" placeholder="Primary client contact name">
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
                                <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($createProjectValues['contact_number']); ?>" placeholder="09xxxxxxxxx or landline">
                            </div>
                        <?php endif; ?>
                        </div>

                        <div class="project-create-card project-create-card--project">
                            <div class="project-create-section-heading">
                                <span>Project Details</span>
                            </div>
                        <div class="input-group">
                            <label for="project_name">Project Title <span class="required-indicator" aria-hidden="true">*</span></label>
                            <input type="text" id="project_name" name="project_name" value="<?php echo htmlspecialchars($createProjectValues['project_name']); ?>" required>
                        </div>

                        <?php if ($hasProjectCodeColumn): ?>
                            <div class="input-group">
                                <div class="field-label-row">
                                    <label for="project_code">Project Code <span class="required-indicator" aria-hidden="true">*</span></label>
                                    <button type="button" class="field-tip" aria-label="Project code help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">Auto-generated by the system.</span>
                                    </button>
                                </div>
                                <input type="text" id="project_code" name="project_code" value="<?php echo htmlspecialchars($createProjectValues['project_code']); ?>" placeholder="Auto-generated project code" required readonly>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasProjectSiteColumn): ?>
                            <div class="input-group">
                                <div class="field-label-row">
                                    <label for="project_site">Project Site <span class="required-indicator" aria-hidden="true">*</span></label>
                                    <button type="button" class="field-tip" aria-label="Project site help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">Enter the site, branch, building, or location code. Required unless the project stays in Draft.</span>
                                    </button>
                                </div>
                                <input type="text" id="project_site" name="project_site" value="<?php echo htmlspecialchars($createProjectValues['project_site']); ?>" placeholder="Site name, branch, building, or location code">
                            </div>
                        <?php endif; ?>

                        <?php if ($hasPoNumberColumn): ?>
                            <div class="input-group">
                                <div class="field-label-row">
                                    <label for="po_number">P.O Number</label>
                                    <button type="button" class="field-tip" aria-label="P.O number help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">Enter the purchase order reference number. Required when the project status is Pending or Ongoing.</span>
                                    </button>
                                </div>
                                <input type="text" id="po_number" name="po_number" value="<?php echo htmlspecialchars($createProjectValues['po_number']); ?>" placeholder="Enter P.O number">
                            </div>
                        <?php endif; ?>

                        <div class="input-group">
                            <div class="field-label-row">
                                <label for="start_date">P.O Date</label>
                                <button type="button" class="field-tip" aria-label="P.O date reminder">
                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                    <span class="field-tip__bubble">Use the purchase order date here. This stays editable while the project is not yet completed.</span>
                                </button>
                            </div>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($createProjectValues['start_date']); ?>" max="<?php echo htmlspecialchars($todayDate); ?>">
                        </div>

                    

                       

                        <div class="input-group">
                            <label for="budget_amount">Project Budget</label>
                            <div class="currency-input-shell">
                                <span class="currency-input-prefix">PHP</span>
                                <input
                                    type="text"
                                    id="budget_amount"
                                    name="budget_amount"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    placeholder="0.00"
                                    value="<?php echo htmlspecialchars($createProjectValues['budget_amount']); ?>"
                                    data-currency-input="php"
                                    class="currency-input-field"
                                >
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <div class="field-label-row">
                                <label for="project_start_date">Project Start Date <span class="required-indicator" aria-hidden="true">*</span></label>
                                <button type="button" class="field-tip" aria-label="Project start date help">
                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                    <span class="field-tip__bubble">Set the planned date when project work should begin. This must be the same as or later than the P.O Date.</span>
                                </button>
                            </div>
                            <input type="date" id="project_start_date" name="project_start_date" value="<?php echo htmlspecialchars($createProjectValues['project_start_date']); ?>" required>
                        </div>

                        <div class="input-group">
                            <div class="field-label-row">
                                <label for="estimated_duration_days">Estimated Duration Days <span class="required-indicator" aria-hidden="true">*</span></label>
                                <button type="button" class="field-tip" aria-label="Estimated duration days help">
                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                    <span class="field-tip__bubble">Enter the planned number of working days for this project. Once filled, the Estimated Completion Date updates automatically.</span>
                                </button>
                            </div>
                            <input
                                type="number"
                                id="estimated_duration_days"
                                name="estimated_duration_days"
                                min="1"
                                step="1"
                                value="<?php echo htmlspecialchars($createProjectValues['estimated_duration_days'] !== '' ? $createProjectValues['estimated_duration_days'] : (string)(calculate_project_duration_days(normalize_date_or_null($createProjectValues['project_start_date']), normalize_date_or_null($createProjectValues['estimated_completion_date'])) ?? '')); ?>"
                                required
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
                            <input type="date" id="estimated_completion_date" name="estimated_completion_date" value="<?php echo htmlspecialchars($createProjectValues['estimated_completion_date']); ?>" required>
                        </div>


                         <div class="input-group project-create-status-group">
                            <div class="field-label-row">
                                <label for="status">Initial Status <span class="required-indicator" aria-hidden="true">*</span></label>
                                <button type="button" class="field-tip" aria-label="Project status reminder">
                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                    <span class="field-tip__bubble">
                                        <?php if ($supportsDraftStatus): ?>
                                            Use Draft for incomplete or possibly wrong project entries. Use Pending for approved work, and choose Ongoing when work is already active.
                                        <?php else: ?>
                                            Use Pending for approved work. Choose Ongoing when work is already active.
                                        <?php endif; ?>
                                    </span>
                                </button>
                            </div>
                            <select id="status" name="status" required>
                                <?php foreach ($initialStatusOptions as $statusOption): ?>
                                    <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo $createProjectValues['status'] === $statusOption ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($statusOption)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="input-group project-create-engineers-group">
                            <div class="field-label-row">
                                <label for="engineer_ids">Assigned Team Member/s <span class="required-indicator" aria-hidden="true">*</span></label>
                                <button type="button" class="field-tip" aria-label="Assigned team members help">
                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                    <span class="field-tip__bubble">Pick an engineer or foreman from the dropdown, then press the plus button to add. Press the same button again to remove the selected team member. Add one or more people depending on the project workload.</span>
                                </button>
                            </div>
                            <div class="engineer-picker" data-engineer-picker>
                                <div class="engineer-picker__controls">
                                    <select id="engineer_ids" class="engineer-picker__select" data-engineer-select>
                                        <option value="">Select engineer or foreman</option>
                                        <?php foreach ($engineers as $engineer): ?>
                                            <option value="<?php echo (int)$engineer['id']; ?>"><?php echo htmlspecialchars($engineer['full_name'] . ' (' . ucfirst((string)($engineer['role'] ?? 'team')) . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="engineer-picker__toggle" data-engineer-toggle aria-label="Add selected team member">
                                        <span class="engineer-picker__toggle-icon" aria-hidden="true">+</span>
                                        <span class="engineer-picker__toggle-text">Add</span>
                                    </button>
                                </div>
                                <div class="engineer-picker__selected" data-engineer-selected>
                                    <?php foreach ($engineers as $engineer): ?>
                                        <?php if (in_array((string)$engineer['id'], $createProjectValues['engineer_ids'], true)): ?>
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
                                </div>
                                <div class="engineer-picker__inputs" data-engineer-inputs>
                                    <?php foreach ($createProjectValues['engineer_ids'] as $engineerId): ?>
                                        <input type="hidden" name="engineer_ids[]" value="<?php echo (int)$engineerId; ?>" data-engineer-input>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>

                    <div class="form-grid form-grid--project-create">
                        <?php if ($hasProjectAddressColumn): ?>
                            <div class="input-group input-group-wide">
                                <div class="field-label-row">
                                    <label for="project_address">Address <span class="required-indicator" aria-hidden="true">*</span></label>
                                    <button type="button" class="field-tip" aria-label="Project address help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">Enter the full project address for delivery or site reference. Required unless the project stays in Draft.</span>
                                    </button>
                                </div>
                                <textarea id="project_address" name="project_address" rows="3" placeholder="Full street address, barangay, city, landmark, or delivery address"><?php echo htmlspecialchars($createProjectValues['project_address']); ?></textarea>
                            </div>
                        <?php endif; ?>

                        <div class="input-group input-group-wide">
                            <label for="budget_notes">Budget Notes</label>
                            <textarea id="budget_notes" name="budget_notes" rows="2" placeholder="Approved ceiling, scope assumption, supplier cap, or payment notes"><?php echo htmlspecialchars($createProjectValues['budget_notes']); ?></textarea>
                        </div>
                    </div>

                        <div class="input-group input-group-spaced">
                            <label for="description">Comment <span class="optional-indicator">(Optional)</span></label>
                            <textarea id="description" name="description" placeholder="Project comment"><?php echo htmlspecialchars($createProjectValues['description']); ?></textarea>
                        </div>

                        <div class="input-group input-group-spaced input-group-wide additional-info-section" data-additional-info-section>
                            <div class="field-label-row">
                                <label for="additional_info_rows">Additional Info <span class="optional-indicator">(Optional)</span></label>
                                <button type="button" class="field-tip" aria-label="Additional info help">
                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                    <span class="field-tip__bubble">Add extra project contacts here, such as coordinators, alternate site contacts, or billing points of contact.</span>
                                </button>
                            </div>
                            <div class="additional-info-list" id="additional_info_rows" data-additional-info-list data-next-index="<?php echo count($createProjectValues['additional_info']); ?>">
                                <?php foreach ($createProjectValues['additional_info'] as $additionalInfoIndex => $additionalInfoRow): ?>
                                    <div class="additional-info-item" data-additional-info-item>
                                        <div class="additional-info-item__grid">
                                            <div class="input-group">
                                                <label>Contact Name</label>
                                                <input type="text" name="additional_info[<?php echo (int)$additionalInfoIndex; ?>][contact_name]" value="<?php echo htmlspecialchars((string)($additionalInfoRow['contact_name'] ?? '')); ?>" placeholder="Contact name" data-additional-info-name>
                                            </div>
                                            <div class="input-group">
                                                <label>Contact Number</label>
                                                <input type="text" name="additional_info[<?php echo (int)$additionalInfoIndex; ?>][contact_number]" value="<?php echo htmlspecialchars((string)($additionalInfoRow['contact_number'] ?? '')); ?>" placeholder="09xxxxxxxxx or landline" data-additional-info-number>
                                            </div>
                                            <div class="input-group">
                                                <label>Email Address <span class="optional-indicator">(Optional)</span></label>
                                                <input type="email" name="additional_info[<?php echo (int)$additionalInfoIndex; ?>][email_address]" value="<?php echo htmlspecialchars((string)($additionalInfoRow['email_address'] ?? '')); ?>" placeholder="contact@example.com" data-additional-info-email>
                                            </div>
                                        </div>
                                        <div class="additional-info-item__actions">
                                            <button type="button" class="btn-secondary additional-info-remove" data-additional-info-remove aria-label="Remove this additional info row">&times; Remove</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="additional-info-actions">
                                <button type="button" class="btn-secondary" data-additional-info-add>+ Add More</button>
                            </div>
                            <template data-additional-info-template>
                                <div class="additional-info-item" data-additional-info-item>
                                    <div class="additional-info-item__grid">
                                        <div class="input-group">
                                            <label>Contact Name</label>
                                            <input type="text" name="additional_info[__INDEX__][contact_name]" value="" placeholder="Contact name" data-additional-info-name>
                                        </div>
                                        <div class="input-group">
                                            <label>Contact Number</label>
                                            <input type="text" name="additional_info[__INDEX__][contact_number]" value="" placeholder="09xxxxxxxxx or landline" data-additional-info-number>
                                        </div>
                                        <div class="input-group">
                                            <label>Email Address <span class="optional-indicator">(Optional)</span></label>
                                            <input type="email" name="additional_info[__INDEX__][email_address]" value="" placeholder="contact@example.com" data-additional-info-email>
                                        </div>
                                    </div>
                                    <div class="additional-info-item__actions">
                                        <button type="button" class="btn-secondary additional-info-remove" data-additional-info-remove aria-label="Remove this additional info row">&times; Remove</button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary" <?php echo (count($clients) === 0 || count($engineers) === 0) ? 'disabled' : ''; ?>>Create Project</button>
                        </div>
                    </form>
                </div>
                </section>
            <?php endif; ?>

            <section
                class="page-stack"
                id="projects-list-section"
                data-reset-url="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php<?php
                    $resetParams = [];
                    if ($statusFilter !== '') {
                        $resetParams['status'] = $statusFilter;
                    }
                    if ($isTrashView) {
                        $resetParams['view'] = 'trash';
                    }
                    echo $resetParams ? '?' . http_build_query($resetParams) : '';
                ?>"
                data-search-endpoint="/codesamplecaps/ADMIN/php/projects/project_search_api.php"
            >
                
                <div class="project-controls">
                    <?php if (!$isTrashView): ?>
                        <div class="project-filter-row">
                            <div class="project-filter-chips">
                                <?php
                                $chipOptions = ['' => 'All'];
                                foreach ($statusOptions as $statusOption) {
                                    $chipOptions[$statusOption] = ucfirst($statusOption);
                                }
                                foreach ($chipOptions as $chipValue => $chipLabel):
                                    $chipParams = [];
                                    if ($searchQuery !== '') {
                                        $chipParams['q'] = $searchQuery;
                                    }
                                    if ($chipValue !== '') {
                                        $chipParams['status'] = $chipValue;
                                    }
                                    $chipLink = '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php' . ($chipParams ? '?' . http_build_query($chipParams) : '');
                                    $isActiveChip = $statusFilter === $chipValue;
                                    $chipTone = $chipValue === '' ? 'all' : str_replace('_', '-', $chipValue);
                                ?>
                                    <a href="<?php echo htmlspecialchars($chipLink); ?>" class="project-filter-chip project-filter-chip--<?php echo htmlspecialchars($chipTone); ?><?php echo $isActiveChip ? ' is-active' : ''; ?>">
                                        <?php
                                        $chipCount = $chipValue === '' ? $totalProjects : (int)($statusCounts[$chipValue] ?? 0);
                                        echo htmlspecialchars($chipLabel . ' (' . $chipCount . ')');
                                        ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="project-create-menu" data-project-create-menu>
                                <button type="button" class="btn-primary project-create-trigger" data-project-create-menu-toggle aria-expanded="false">
                                    + Create Project
                                </button>
                                <div class="project-create-menu__list" data-project-create-menu-list hidden>
                                    <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=For+Inspection" class="project-create-menu__item">
                                        <strong>From Inquiry / Appointment</strong>
                                        <span>Use approved inquiry or quotation</span>
                                    </a>
                                    <button type="button" class="project-create-menu__item" data-create-project-open data-create-project-mode="walk-in">
                                        <strong>Walk-in Client</strong>
                                        <span>Create client project from direct visit</span>
                                    </button>
                                    <button type="button" class="project-create-menu__item" data-create-project-open data-create-project-mode="returning-client">
                                        <strong>Returning Client</strong>
                                        <span>Search existing client then create project</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="GET" class="project-toolbar" id="project-search-form">
                        <?php if ($isTrashView): ?>
                            <input type="hidden" name="view" value="trash">
                        <?php endif; ?>
                        <?php if ($statusFilter !== ''): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <?php endif; ?>
                        <div class="project-search-shell">
                            <div class="project-search-input-row">
                                <span class="project-search-icon" aria-hidden="true">&#128269;</span>
                                <input
                                    type="text"
                                    id="project-search"
                                    name="q"
                                    value="<?php echo htmlspecialchars($searchQuery); ?>"
                                    placeholder="<?php echo $isTrashView ? 'Search trashed project, client, engineer, or site' : 'Search project, client, engineer, or site'; ?>"
                                    autocomplete="off"
                                    aria-autocomplete="list"
                                    aria-haspopup="listbox"
                                    aria-controls="project-search-dropdown"
                                    aria-expanded="false"
                                >
                                <button
                                    type="button"
                                    class="project-search-clear<?php echo $searchQuery !== '' ? ' is-visible' : ''; ?>"
                                    id="project-search-clear"
                                    aria-label="Clear search"
                                >
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="project-search-dropdown" id="project-search-dropdown" role="listbox" hidden></div>
                        </div>
                        <?php if (!$isTrashView): ?>
                            <div class="project-sort-shell">
                                <select id="project-sort-select" class="project-sort-select" aria-label="Sort projects by">
                                    <option value="updated">Sort: Recently Updated</option>
                                    <option value="title">Sort: Project Title</option>
                                    <option value="start">Sort: Start Date</option>
                                    <option value="progress">Sort: Progress</option>
                                </select>
                            </div>
                        <?php endif; ?>
                    </form>
                    <?php if ($isTrashView): ?>
                        <div class="archive-tabs" role="tablist" aria-label="Archive filters">
                            <button type="button" class="archive-tab-button is-active" data-archive-tab="projects">Projects (<?php echo $trashedProjects; ?>)</button>
                            <button type="button" class="archive-tab-button" data-archive-tab="users">Users (<?php echo $trashedUsers; ?>)</button>
                            <button type="button" class="archive-tab-button" data-archive-tab="assets">Assets (<?php echo $trashedAssets; ?>)</button>
                            <button type="button" class="archive-tab-button" data-archive-tab="purchase_requests">Purchase Requests (<?php echo $trashedPurchaseRequests; ?>)</button>
                            <button type="button" class="archive-tab-button" data-archive-tab="suppliers">Suppliers (<?php echo $trashedSuppliers; ?>)</button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($isTrashView): ?>
                    <section class="page-stack archive-section archive-section-spaced is-active" data-archive-section="projects">
                        <?php if (empty($projects)): ?>
                            <div class="empty-state">
                                <?php
                                if ($searchQuery !== '' || $statusFilter !== '') {
                                    echo 'No matching trashed projects found.';
                                } elseif ($trashBinTotal > 0) {
                                    echo 'No trashed projects found right now.';
                                } else {
                                    echo 'Trash is empty.';
                                }
                                ?>
                            </div>
                        <?php else: ?>
                            <h3 class="section-title-inline">Trashed Projects</h3>
                            <?php if ($searchQuery !== '' || $statusFilter !== '' || $totalPages > 1): ?>
                                <div class="project-results-meta">
                                    <?php if ($searchQuery !== '' || $statusFilter !== '' || $filteredProjects > count($projects)): ?>
                                        <span>Showing <?php echo count($projects); ?> of <?php echo $filteredProjects; ?> matching trashed projects</span>
                                    <?php endif; ?>
                                    <?php if ($totalPages > 1): ?>
                                        <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="projects-grid" id="projects-grid">
                                <?php foreach ($projects as $project): ?>
                                    <?php
                                    $isDraft = ($project['status'] ?? '') === 'draft';
                                    $isCompleted = ($project['status'] ?? '') === 'completed';
                                    $budgetAmount = (float)($project['budget_amount'] ?? 0);
                                    $budgetNotes = (string)($project['budget_notes'] ?? '');
                                    $totalCost = (float)($project['total_cost'] ?? 0);
                                    $remainingBudget = $budgetAmount - $totalCost;
                                    $budgetUsage = $budgetAmount > 0 ? min(100, round(($totalCost / $budgetAmount) * 100)) : 0;
                                    $budgetHealth = build_budget_health($budgetAmount, $totalCost);
                                    $projectRecentCosts = $recentProjectCosts[(int)($project['id'] ?? 0)] ?? [];
                                    $projectCode = trim((string)($project['project_code'] ?? ''));
                                    $projectPoNumber = trim((string)($project['po_number'] ?? ''));
                                    $projectContactPerson = trim((string)($project['contact_person'] ?? ''));
                                    $projectContactNumber = trim((string)($project['contact_number'] ?? ''));
                                    $projectSite = trim((string)($project['project_site'] ?? ''));
                                    $projectProgress = build_role_project_progress($project, 'super_admin');
                                    $projectProgressPercent = (int)($projectProgress['percent'] ?? 0);
                                    $targetDate = trim((string)($project['estimated_completion_date'] ?? ''));
                                    if ($targetDate === '') {
                                        $targetDate = trim((string)($project['end_date'] ?? ''));
                                    }
                                    if ($targetDate === '') {
                                        $targetDate = trim((string)($project['project_start_date'] ?? ''));
                                    }
                                    $isTargetPastDue = project_target_is_past_due($targetDate, (string)($project['status'] ?? ''));
                                    $projectRiskLabel = '';
                                    if ($isTargetPastDue) {
                                        $projectRiskLabel = 'OVERDUE';
                                    } elseif (($project['status'] ?? '') === 'on-hold') {
                                        $projectRiskLabel = 'AT RISK';
                                    }
                                    $projectAdditionalInfoRows = decode_project_additional_info($project['additional_info_json'] ?? null);
                                    $projectAdditionalInfoSearchText = project_additional_info_search_text($projectAdditionalInfoRows);
                                    $assignedEngineerNames = trim((string)($project['engineer_names'] ?? ''));
                                    $deletedAt = trim((string)($project['deleted_at'] ?? ''));
                                    $deleteScheduledAt = trim((string)($project['delete_scheduled_at'] ?? ''));
                                    $daysUntilPurge = null;
                                    if ($deleteScheduledAt !== '') {
                                        try {
                                            $purgeDate = new DateTimeImmutable($deleteScheduledAt);
                                            $today = new DateTimeImmutable('today');
                                            $daysUntilPurge = max(0, (int)$today->diff($purgeDate)->format('%r%a'));
                                        } catch (Throwable $exception) {
                                            $daysUntilPurge = null;
                                        }
                                    }
                                    $searchText = strtolower(trim(implode(' ', [
                                        $project['project_name'] ?? '',
                                        $projectCode,
                                        $projectPoNumber,
                                        $projectContactPerson,
                                        $projectContactNumber,
                                        $projectSite,
                                        $project['client_name'] ?? '',
                                        $assignedEngineerNames,
                                        $project['project_address'] ?? '',
                                        $projectAdditionalInfoSearchText,
                                        $project['status'] ?? '',
                                    ])));
                                    $detailsPath = '/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=' . (int)$project['id'];
                                    ?>
                                    <article
                                        class="project-card<?php echo $isCompleted ? ' is-locked' : ''; ?><?php echo $isDraft ? ' is-draft' : ''; ?>"
                                        data-project-card
                                        data-status="<?php echo htmlspecialchars($project['status']); ?>"
                                        data-search="<?php echo htmlspecialchars($searchText); ?>"
                                        data-title="<?php echo htmlspecialchars($project['project_name']); ?>"
                                        data-link="<?php echo htmlspecialchars($detailsPath); ?>"
                                        data-client="<?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?>"
                                        data-engineer="<?php echo htmlspecialchars($assignedEngineerNames !== '' ? $assignedEngineerNames : 'Not assigned'); ?>"
                                        data-updated="<?php echo htmlspecialchars((string)($project['updated_at'] ?? $project['created_at'] ?? '')); ?>"
                                        data-start="<?php echo htmlspecialchars((string)($project['project_start_date'] ?? $project['start_date'] ?? '')); ?>"
                                        data-progress="<?php echo $projectProgressPercent; ?>"
                                    >
                                        <div class="project-card__topline">
                                            <?php if ($projectCode !== ''): ?>
                                                <span class="project-card__reference"><?php echo htmlspecialchars($projectCode); ?></span>
                                            <?php else: ?>
                                                <span class="project-card__reference">PROJECT #<?php echo (int)$project['id']; ?></span>
                                            <?php endif; ?>
                                            <div class="project-card__badges">
                                                <span class="status-pill status-<?php echo htmlspecialchars($project['status']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($project['status'])); ?>
                                                </span>
                                                <?php if ($projectRiskLabel !== ''): ?>
                                                    <span class="project-risk-badge"><?php echo htmlspecialchars($projectRiskLabel); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="card-split">
                                            <div class="project-card__main">
                                                <h3><?php echo htmlspecialchars($project['project_name']); ?></h3>
                                                <div class="project-meta">
                                                    <div><strong>Client:</strong> <?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?></div>
                                                    <div><strong>Assigned Team:</strong> <?php echo htmlspecialchars($assignedEngineerNames !== '' ? $assignedEngineerNames : 'Not assigned'); ?></div>
                                                    <div><strong>Site:</strong> <?php echo htmlspecialchars($projectSite !== '' ? $projectSite : 'Not set'); ?></div>
                                                    <div class="project-progress">
                                                        <div class="project-progress__label">
                                                            <strong>Progress</strong>
                                                            <span><?php echo $projectProgressPercent; ?>%</span>
                                                        </div>
                                                        <div class="project-progress__track">
                                                            <span class="project-progress__fill" data-progress-width="<?php echo $projectProgressPercent; ?>"></span>
                                                        </div>
                                                    </div>
                                                    <div class="<?php echo $isTargetPastDue ? 'project-target-warning' : ''; ?>"><strong>Target:</strong> <?php echo htmlspecialchars(format_display_date($targetDate)); ?></div>
                                                    <div><strong>Deleted:</strong> <?php echo htmlspecialchars(format_display_datetime($deletedAt)); ?></div>
                                                    <div><strong>Auto delete:</strong> <?php echo htmlspecialchars(format_display_datetime($deleteScheduledAt)); ?></div>
                                                </div>
                                            </div>
                                            <div class="project-card__finance">
                                                <strong>Budget:</strong> <?php echo htmlspecialchars(format_money($budgetAmount)); ?>
                                                <span aria-hidden="true">&bull;</span>
                                                <strong>Spent:</strong> <?php echo htmlspecialchars(format_money($totalCost)); ?>
                                            </div>
                                        </div>
                                        <div class="form-actions project-card__actions project-card__actions--trash">
                                            <form method="POST" class="project-card__inline-form" data-confirm="Restore this project from trash?">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_project">
                                                <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash">
                                                <button type="submit" class="btn-secondary btn-restore">Restore</button>
                                            </form>
                                            <form method="POST" class="project-card__inline-form" data-confirm="Permanently delete this project? This cannot be undone.">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="permanently_delete_project">
                                                <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash">
                                                <button type="submit" class="btn-danger btn-permanent-delete">Delete Permanently</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <div class="pagination">
                                    <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                                        <?php
                                        $pageParams = [];
                                        if ($searchQuery !== '') {
                                            $pageParams['q'] = $searchQuery;
                                        }
                                        if ($statusFilter !== '') {
                                            $pageParams['status'] = $statusFilter;
                                        }
                                        if ($isTrashView) {
                                            $pageParams['view'] = 'trash';
                                        }
                                        $pageParams['page'] = $page;
                                        $pageLink = '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?' . http_build_query($pageParams);
                                        ?>
                                        <a href="<?php echo htmlspecialchars($pageLink); ?>" class="pagination-link<?php echo $page === $currentPage ? ' is-active' : ''; ?>">
                                            <?php echo $page; ?>
                                        </a>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>

                    <section class="page-stack archive-section archive-section-spaced" data-archive-section="users">
                        <h3 class="section-title-inline">Trashed Users</h3>
                        <?php if (empty($trashedUserRows)): ?>
                            <div class="empty-state">No trashed users.</div>
                        <?php else: ?>
                            <div class="projects-grid">
                                <?php foreach ($trashedUserRows as $trashedUser): ?>
                                    <article class="project-card">
                                        <div class="card-split">
                                            <div>
                                                <div class="project-card__eyebrow-row">
                                                    <span class="project-card__eyebrow">User</span>
                                                    <span class="project-card__reference"><?php echo htmlspecialchars((string)($trashedUser['email'] ?? '')); ?></span>
                                                </div>
                                                <h3><?php echo htmlspecialchars((string)($trashedUser['full_name'] ?? 'User')); ?></h3>
                                                <div class="status-pill-wrap">
                                                    <span class="status-pill status-inactive"><?php echo htmlspecialchars(ucfirst((string)($trashedUser['status'] ?? 'inactive'))); ?></span>
                                                </div>
                                            </div>
                                            <div class="project-meta">
                                                <div><strong>Role:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($trashedUser['role'] ?? 'user')))); ?></div>
                                                <div><strong>Phone:</strong> <?php echo htmlspecialchars((string)($trashedUser['phone'] ?? 'Not set')); ?></div>
                                                <div><strong>Moved To Trash:</strong> <?php echo htmlspecialchars((string)($trashedUser['deleted_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <div class="form-actions project-card__actions project-card__actions--trash">
                                            <form method="POST" class="project-card__inline-form" data-confirm="Restore this user from trash?">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_user">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$trashedUser['id']; ?>">
                                                <button type="submit" class="btn-secondary btn-restore">Restore</button>
                                            </form>
                                            <form method="POST" class="project-card__inline-form" data-confirm="Permanently delete this user? This cannot be undone.">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="permanently_delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$trashedUser['id']; ?>">
                                                <button type="submit" class="btn-danger btn-permanent-delete">Delete Permanently</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="page-stack archive-section archive-section-spaced" data-archive-section="purchase_requests">
                        <h3 class="section-title-inline">Trashed Purchase Requests</h3>
                        <?php if (empty($trashedPurchaseRequestRows)): ?>
                            <div class="empty-state">No trashed purchase requests.</div>
                        <?php else: ?>
                            <div class="projects-grid">
                                <?php foreach ($trashedPurchaseRequestRows as $trashedRequest): ?>
                                    <article class="project-card">
                                        <div class="card-split">
                                            <div>
                                                <div class="project-card__eyebrow-row">
                                                    <span class="project-card__eyebrow">Purchase Request</span>
                                                    <span class="project-card__reference"><?php echo htmlspecialchars((string)($trashedRequest['request_no'] ?? '')); ?></span>
                                                </div>
                                                <h3><?php echo htmlspecialchars((string)($trashedRequest['item_description'] ?? 'Request Item')); ?></h3>
                                                <div class="status-pill-wrap">
                                                    <span class="status-pill status-cancelled">Cancelled</span>
                                                </div>
                                            </div>
                                            <div class="project-meta">
                                                <div><strong>Requested By:</strong> <?php echo htmlspecialchars((string)($trashedRequest['requested_by_name'] ?? 'N/A')); ?></div>
                                                <div><strong>Qty:</strong> <?php echo htmlspecialchars((string)($trashedRequest['quantity_requested'] ?? '0')); ?> <?php echo htmlspecialchars((string)($trashedRequest['unit'] ?? '')); ?></div>
                                                <div><strong>Needed Date:</strong> <?php echo htmlspecialchars((string)($trashedRequest['needed_date'] ?? 'Not set')); ?></div>
                                                <div><strong>Moved To Trash:</strong> <?php echo htmlspecialchars((string)($trashedRequest['updated_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($trashedRequest['remarks'])): ?>
                                            <div class="lock-note"><strong>Remarks:</strong> <?php echo htmlspecialchars((string)$trashedRequest['remarks']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-actions project-card__actions project-card__actions--trash">
                                            <form method="POST" class="project-card__inline-form" data-confirm="Restore this purchase request from trash?">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_purchase_request">
                                                <input type="hidden" name="purchase_request_id" value="<?php echo (int)$trashedRequest['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash">
                                                <button type="submit" class="btn-secondary btn-restore">Restore</button>
                                            </form>
                                            <?php if ((int)($trashedRequest['linked_purchase_orders'] ?? 0) > 0): ?>
                                                <div class="lock-note">Cannot permanently delete. This request already has a purchase order.</div>
                                            <?php else: ?>
                                                <form method="POST" class="project-card__inline-form" data-confirm="Permanently delete this purchase request? This cannot be undone.">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="permanently_delete_purchase_request">
                                                    <input type="hidden" name="purchase_request_id" value="<?php echo (int)$trashedRequest['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash">
                                                    <button type="submit" class="btn-danger btn-permanent-delete">Delete Permanently</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="page-stack archive-section archive-section-spaced" data-archive-section="suppliers">
                        <h3 class="section-title-inline">Trashed Suppliers</h3>
                        <?php if (empty($trashedSupplierRows)): ?>
                            <div class="empty-state">No trashed suppliers.</div>
                        <?php else: ?>
                            <div class="projects-grid">
                                <?php foreach ($trashedSupplierRows as $trashedSupplier): ?>
                                    <article class="project-card">
                                        <div class="card-split">
                                            <div>
                                                <div class="project-card__eyebrow-row">
                                                    <span class="project-card__eyebrow">Supplier</span>
                                                    <span class="project-card__reference"><?php echo htmlspecialchars((string)($trashedSupplier['supplier_code'] ?? '')); ?></span>
                                                </div>
                                                <h3><?php echo htmlspecialchars((string)($trashedSupplier['supplier_name'] ?? 'Supplier')); ?></h3>
                                                <div class="status-pill-wrap">
                                                    <span class="status-pill status-inactive">Inactive</span>
                                                </div>
                                            </div>
                                            <div class="project-meta">
                                                <div><strong>Contact:</strong> <?php echo htmlspecialchars((string)($trashedSupplier['contact_person'] ?? 'Not set')); ?></div>
                                                <div><strong>Number:</strong> <?php echo htmlspecialchars((string)($trashedSupplier['contact_number'] ?? 'Not set')); ?></div>
                                                <div><strong>Email:</strong> <?php echo htmlspecialchars((string)($trashedSupplier['email'] ?? 'Not set')); ?></div>
                                                <div><strong>Moved To Trash:</strong> <?php echo htmlspecialchars((string)($trashedSupplier['updated_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($trashedSupplier['description'])): ?>
                                            <div class="lock-note"><strong>Description:</strong> <?php echo htmlspecialchars((string)$trashedSupplier['description']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-actions project-card__actions project-card__actions--trash">
                                            <form method="POST" class="project-card__inline-form" data-confirm="Restore this supplier from trash?">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_supplier">
                                                <input type="hidden" name="supplier_id" value="<?php echo (int)$trashedSupplier['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash">
                                                <button type="submit" class="btn-secondary btn-restore">Restore</button>
                                            </form>
                                            <?php if ((int)($trashedSupplier['linked_purchase_orders'] ?? 0) > 0): ?>
                                                <div class="lock-note">Cannot permanently delete. This supplier is linked to purchase orders.</div>
                                            <?php else: ?>
                                                <form method="POST" class="project-card__inline-form" data-confirm="Permanently delete this supplier? This cannot be undone.">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="permanently_delete_supplier">
                                                    <input type="hidden" name="supplier_id" value="<?php echo (int)$trashedSupplier['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash">
                                                    <button type="submit" class="btn-danger btn-permanent-delete">Delete Permanently</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="page-stack archive-section archive-section-spaced" data-archive-section="assets">
                        <h3 class="section-title-inline">Trashed Assets</h3>
                        <?php if (empty($trashedAssetRows)): ?>
                            <div class="empty-state">No trashed assets.</div>
                        <?php else: ?>
                            <div class="projects-grid">
                                <?php foreach ($trashedAssetRows as $trashedAsset): ?>
                                    <article class="project-card">
                                        <div class="card-split">
                                            <div>
                                                <div class="project-card__eyebrow-row">
                                                    <span class="project-card__eyebrow">Asset</span>
                                                    <span class="project-card__reference"><?php echo htmlspecialchars((string)($trashedAsset['serial_number'] ?? '')); ?></span>
                                                </div>
                                                <h3><?php echo htmlspecialchars((string)($trashedAsset['asset_name'] ?? 'Asset')); ?></h3>
                                                <div class="status-pill-wrap">
                                                    <span class="status-pill status-inactive"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($trashedAsset['asset_status'] ?? 'available')))); ?></span>
                                                </div>
                                            </div>
                                            <div class="project-meta">
                                                <div><strong>Category:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($trashedAsset['asset_category'] ?? 'uncategorized')))); ?></div>
                                                <div><strong>Type:</strong> <?php echo htmlspecialchars((string)($trashedAsset['asset_type'] ?: 'Type not set')); ?></div>
                                                <div><strong>Moved To Trash:</strong> <?php echo htmlspecialchars((string)($trashedAsset['deleted_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <div class="form-actions project-card__actions project-card__actions--trash">
                                            <form method="POST" action="/codesamplecaps/ADMIN/sidebar/assets/php/assets.php" class="project-card__inline-form" data-confirm="Restore this asset from trash?">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_asset">
                                                <input type="hidden" name="asset_id" value="<?php echo (int)$trashedAsset['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash">
                                                <button type="submit" class="btn-secondary btn-restore">Restore</button>
                                            </form>
                                            <form method="POST" action="/codesamplecaps/ADMIN/sidebar/assets/php/assets.php" class="project-card__inline-form" data-confirm="Permanently delete this asset? This cannot be undone.">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="permanently_delete_asset">
                                                <input type="hidden" name="asset_id" value="<?php echo (int)$trashedAsset['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash">
                                                <button type="submit" class="btn-danger btn-permanent-delete">Delete Permanently</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <?php if (empty($projects)): ?>
                        <div class="empty-state">
                            <?php echo ($searchQuery !== '' || $statusFilter !== '') ? 'No matching projects found.' : 'No active projects found right now.'; ?>
                        </div>
                    <?php else: ?>
                        <?php if ($searchQuery !== '' || $statusFilter !== '' || $totalPages > 1): ?>
                            <div class="project-results-meta">
                                <?php if ($searchQuery !== '' || $statusFilter !== '' || $filteredProjects > count($projects)): ?>
                                    <span>Showing <?php echo count($projects); ?> of <?php echo $filteredProjects; ?> matching projects</span>
                                <?php endif; ?>
                                <?php if ($totalPages > 1): ?>
                                    <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="projects-grid" id="projects-grid">
                            <?php foreach ($projects as $project): ?>
                                <?php
                                $isDraft = ($project['status'] ?? '') === 'draft';
                                $isCompleted = ($project['status'] ?? '') === 'completed';
                                $budgetAmount = (float)($project['budget_amount'] ?? 0);
                                $totalCost = (float)($project['total_cost'] ?? 0);
                                $remainingBudget = $budgetAmount - $totalCost;
                                $projectCode = trim((string)($project['project_code'] ?? ''));
                                $projectPoNumber = trim((string)($project['po_number'] ?? ''));
                                $projectContactPerson = trim((string)($project['contact_person'] ?? ''));
                                $projectContactNumber = trim((string)($project['contact_number'] ?? ''));
                                $projectSite = trim((string)($project['project_site'] ?? ''));
                                $projectProgress = build_role_project_progress($project, 'super_admin');
                                $projectProgressPercent = (int)($projectProgress['percent'] ?? 0);
                                $targetDate = trim((string)($project['estimated_completion_date'] ?? ''));
                                if ($targetDate === '') {
                                    $targetDate = trim((string)($project['end_date'] ?? ''));
                                }
                                if ($targetDate === '') {
                                    $targetDate = trim((string)($project['project_start_date'] ?? ''));
                                }
                                $isTargetPastDue = project_target_is_past_due($targetDate, (string)($project['status'] ?? ''));
                                $projectRiskLabel = '';
                                if ($isTargetPastDue) {
                                    $projectRiskLabel = 'OVERDUE';
                                } elseif (($project['status'] ?? '') === 'on-hold') {
                                    $projectRiskLabel = 'AT RISK';
                                }
                                $projectAdditionalInfoRows = decode_project_additional_info($project['additional_info_json'] ?? null);
                                $projectAdditionalInfoSearchText = project_additional_info_search_text($projectAdditionalInfoRows);
                                $assignedEngineerNames = trim((string)($project['engineer_names'] ?? ''));
                                $searchText = strtolower(trim(implode(' ', [
                                    $project['project_name'] ?? '',
                                    $projectCode,
                                    $projectPoNumber,
                                    $projectContactPerson,
                                    $projectContactNumber,
                                    $projectSite,
                                    $project['client_name'] ?? '',
                                    $assignedEngineerNames,
                                    $project['project_address'] ?? '',
                                    $projectAdditionalInfoSearchText,
                                    $project['status'] ?? '',
                                ])));
                                $detailsPath = '/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=' . (int)$project['id'];
                                ?>
                                <article
                                    class="project-card<?php echo $isCompleted ? ' is-locked' : ''; ?><?php echo $isDraft ? ' is-draft' : ''; ?>"
                                    data-project-card
                                    data-status="<?php echo htmlspecialchars($project['status']); ?>"
                                    data-search="<?php echo htmlspecialchars($searchText); ?>"
                                    data-title="<?php echo htmlspecialchars($project['project_name']); ?>"
                                    data-link="<?php echo htmlspecialchars($detailsPath); ?>"
                                    data-client="<?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?>"
                                    data-engineer="<?php echo htmlspecialchars($assignedEngineerNames !== '' ? $assignedEngineerNames : 'Not assigned'); ?>"
                                    data-updated="<?php echo htmlspecialchars((string)($project['updated_at'] ?? $project['created_at'] ?? '')); ?>"
                                    data-start="<?php echo htmlspecialchars((string)($project['project_start_date'] ?? $project['start_date'] ?? '')); ?>"
                                    data-progress="<?php echo $projectProgressPercent; ?>"
                                >
                                    <div class="project-card__topline">
                                        <?php if ($projectCode !== ''): ?>
                                            <span class="project-card__reference"><?php echo htmlspecialchars($projectCode); ?></span>
                                        <?php else: ?>
                                            <span class="project-card__reference">PROJECT #<?php echo (int)$project['id']; ?></span>
                                        <?php endif; ?>
                                        <div class="project-card__badges">
                                            <span class="status-pill status-<?php echo htmlspecialchars($project['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst((string)$project['status'])); ?>
                                            </span>
                                            <?php if ($projectRiskLabel !== ''): ?>
                                                <span class="project-risk-badge"><?php echo htmlspecialchars($projectRiskLabel); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-split">
                                        <div class="project-card__main">
                                            <h3><?php echo htmlspecialchars($project['project_name']); ?></h3>
                                            <div class="project-meta">
                                                <div><strong>Client:</strong> <?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?></div>
                                                <div><strong>Assigned Team:</strong> <?php echo htmlspecialchars($assignedEngineerNames !== '' ? $assignedEngineerNames : 'Not assigned'); ?></div>
                                                <div><strong>Site:</strong> <?php echo htmlspecialchars($projectSite !== '' ? $projectSite : 'Not set'); ?></div>
                                                <div class="project-progress">
                                                    <div class="project-progress__label">
                                                        <strong>Progress</strong>
                                                        <span><?php echo $projectProgressPercent; ?>%</span>
                                                    </div>
                                                    <div class="project-progress__track">
                                                        <span class="project-progress__fill" data-progress-width="<?php echo $projectProgressPercent; ?>"></span>
                                                    </div>
                                                </div>
                                                <div class="<?php echo $isTargetPastDue ? 'project-target-warning' : ''; ?>"><strong>Target:</strong> <?php echo htmlspecialchars(format_display_date($targetDate)); ?></div>
                                            </div>
                                        </div>
                                        <div class="project-card__finance">
                                            <strong>Budget:</strong> <?php echo htmlspecialchars(format_money($budgetAmount)); ?>
                                            <span aria-hidden="true">&bull;</span>
                                            <strong>Spent:</strong> <?php echo htmlspecialchars(format_money($totalCost)); ?>
                                        </div>
                                    </div>
                                    <div class="form-actions project-card__actions">
                                        <a href="<?php echo htmlspecialchars($detailsPath); ?>" class="btn-primary project-card__details-btn">View Details</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                                    <?php
                                    $pageParams = [];
                                    if ($searchQuery !== '') {
                                        $pageParams['q'] = $searchQuery;
                                    }
                                    if ($statusFilter !== '') {
                                        $pageParams['status'] = $statusFilter;
                                    }
                                    $pageParams['page'] = $page;
                                    $pageLink = '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?' . http_build_query($pageParams);
                                    ?>
                                    <a href="<?php echo htmlspecialchars($pageLink); ?>" class="pagination-link<?php echo $page === $currentPage ? ' is-active' : ''; ?>">
                                        <?php echo $page; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js"></script>
<script src="/codesamplecaps/ADMIN/js/super_admin_dashboard.js"></script>

<script src="/codesamplecaps/ADMIN/sidebar/projects/js/projects.js"></script>
</body>
</html>




