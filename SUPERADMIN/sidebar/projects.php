<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/project_search_support.php';

require_role('super_admin');

function get_column_type(mysqli $conn, string $tableName, string $columnName): ?string {
    static $cache = [];
    $cacheKey = $tableName . '.' . $columnName;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $conn->prepare(
        'SELECT COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND COLUMN_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        $cache[$cacheKey] = null;
        return null;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $cache[$cacheKey] = $row['COLUMN_TYPE'] ?? null;

    return $cache[$cacheKey];
}

function table_has_column(mysqli $conn, string $tableName, string $columnName): bool {
    return get_column_type($conn, $tableName, $columnName) !== null;
}

function table_exists(mysqli $conn, string $tableName): bool {
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

    return $result !== false && $result->num_rows > 0;
}

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

function enum_supports_value(mysqli $conn, string $tableName, string $columnName, string $value): bool {
    $columnType = get_column_type($conn, $tableName, $columnName);

    return $columnType !== null && str_contains($columnType, "'" . $value . "'");
}

function normalize_text_or_null(?string $value): ?string {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function today_date(): string {
    return (new DateTimeImmutable('today'))->format('Y-m-d');
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

    $normalized = str_ireplace(['PHP', '₱'], '', $value);
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

    if ($redirectTo !== '' && str_starts_with($redirectTo, '/codesamplecaps/SUPERADMIN/sidebar/')) {
        return $redirectTo;
    }

    return '/codesamplecaps/SUPERADMIN/sidebar/projects.php';
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

        header('Location: /codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash');
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

        header('Location: /codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash');
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

        if ($hasProjectAddressColumn && $status !== 'draft' && $projectAddress === null) {
            set_projects_old_input($createProjectInput, 'project_address');
            set_projects_flash('error', 'Project address is required unless the project stays in Draft.');
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

        if (projectFieldValueExists($conn, 'project_code', $projectCode)) {
            set_projects_old_input($createProjectInput, 'project_code');
            set_projects_flash('error', 'Project code already exists.');
            redirect_projects_page();
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

        $conn->begin_transaction();

        try {
            if ($hasProjectAddressColumn) {
                if ($hasProjectEmailColumn) {
                    if ($hasProjectCodeColumn && $hasPoNumberColumn) {
                        $createProject = $conn->prepare(
                            'INSERT INTO projects (project_name, description, client_id, contact_person, contact_number, project_site, project_address, project_email, project_code, po_number, start_date, project_start_date, estimated_completion_date, end_date, status, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                    } else {
                        $createProject = $conn->prepare(
                            'INSERT INTO projects (project_name, description, client_id, contact_person, contact_number, project_site, project_address, project_email, start_date, project_start_date, estimated_completion_date, end_date, status, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                    }
                } else {
                    $createProject = $conn->prepare(
                        'INSERT INTO projects (project_name, description, client_id, contact_person, contact_number, project_site, project_address, start_date, project_start_date, estimated_completion_date, end_date, status, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                }
            } else {
                $createProject = $conn->prepare(
                    'INSERT INTO projects (project_name, description, client_id, contact_person, contact_number, start_date, project_start_date, estimated_completion_date, end_date, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
            }

            if (!$createProject) {
                throw new RuntimeException('Failed to prepare project creation.');
            }

            if ($hasProjectAddressColumn) {
                if ($hasProjectEmailColumn) {
                    if ($hasProjectCodeColumn && $hasPoNumberColumn) {
                        $createProject->bind_param(
                            'ssissssssssssssi',
                            $projectName,
                            $description,
                            $clientId,
                            $contactPerson,
                            $contactNumber,
                            $projectSite,
                            $projectAddress,
                            $projectEmail,
                            $projectCode,
                            $poNumber,
                            $startDate,
                            $projectStartDate,
                            $estimatedCompletionDate,
                            $endDate,
                            $status,
                            $createdBy
                        );
                    } else {
                        $createProject->bind_param(
                            'ssissssssssssi',
                            $projectName,
                            $description,
                            $clientId,
                            $contactPerson,
                            $contactNumber,
                            $projectSite,
                            $projectAddress,
                            $projectEmail,
                            $startDate,
                            $projectStartDate,
                            $estimatedCompletionDate,
                            $endDate,
                            $status,
                            $createdBy
                        );
                    }
                } else {
                    $createProject->bind_param(
                        'ssisssssssssi',
                        $projectName,
                        $description,
                        $clientId,
                        $contactPerson,
                        $contactNumber,
                        $projectSite,
                        $projectAddress,
                        $startDate,
                        $projectStartDate,
                        $estimatedCompletionDate,
                        $endDate,
                        $status,
                        $createdBy
                    );
                }
            } else {
                $createProject->bind_param(
                    'ssisssssssi',
                    $projectName,
                    $description,
                    $clientId,
                    $contactPerson,
                    $contactNumber,
                    $startDate,
                    $projectStartDate,
                    $estimatedCompletionDate,
                    $endDate,
                    $status,
                    $createdBy
                );
            }

            if (!$createProject->execute()) {
                throw new RuntimeException('Failed to create project.');
            }

            $projectId = (int)$createProject->insert_id;

            if ($hasProjectAdditionalInfoColumn && !save_project_additional_info_json($conn, $projectId, $additionalInfoJson)) {
                throw new RuntimeException('Failed to save project additional info.');
            }

            $assignEngineer = $conn->prepare(
                'INSERT INTO project_assignments (project_id, engineer_id, assigned_by)
                 VALUES (?, ?, ?)'
            );

            if (!$assignEngineer) {
                throw new RuntimeException('Failed to prepare project team assignment.');
            }

            foreach ($engineerIds as $engineerId) {
                $assignEngineer->bind_param('iii', $projectId, $engineerId, $createdBy);

                if (!$assignEngineer->execute()) {
                    throw new RuntimeException('Failed to assign team member to project.');
                }
            }

            if ($budgetAmount !== null || $budgetNotes !== null) {
                $saveBudget = $conn->prepare(
                    'INSERT INTO project_budget_profiles (project_id, budget_amount, budget_notes, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?)'
                );

                if (!$saveBudget) {
                    throw new RuntimeException('Failed to prepare project budget.');
                }

                $initialBudget = $budgetAmount ?? 0.00;
                if (
                    !$saveBudget->bind_param('idsii', $projectId, $initialBudget, $budgetNotes, $createdBy, $createdBy) ||
                    !$saveBudget->execute()
                ) {
                    throw new RuntimeException('Failed to save project budget.');
                }
            }

            $conn->commit();
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
            $conn->rollback();
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

        $saveBudget = $conn->prepare(
            'INSERT INTO project_budget_profiles (project_id, budget_amount, budget_notes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                budget_amount = VALUES(budget_amount),
                budget_notes = VALUES(budget_notes),
                updated_by = VALUES(updated_by)'
        );

        if (
            $saveBudget &&
            $saveBudget->bind_param('idsii', $projectId, $budgetAmount, $budgetNotes, $updatedBy, $updatedBy) &&
            $saveBudget->execute()
        ) {
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

        $insertCostEntry = $conn->prepare(
            'INSERT INTO project_cost_entries (project_id, cost_date, cost_category, description, amount, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        if (
            $insertCostEntry &&
            $insertCostEntry->bind_param('isssdi', $projectId, $costDate, $costCategory, $costDescription, $costAmount, $createdBy) &&
            $insertCostEntry->execute()
        ) {
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

        $insertPayment = $conn->prepare(
            'INSERT INTO project_payments (project_id, payment_date, amount, notes, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );

        if (
            $insertPayment &&
            $insertPayment->bind_param('isdsi', $projectId, $paymentDate, $paymentAmount, $paymentNotes, $createdBy) &&
            $insertPayment->execute()
        ) {
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

        $updateStatus = $conn->prepare('UPDATE projects SET status = ?, end_date = ? WHERE id = ?');

        if ($updateStatus && $updateStatus->bind_param('ssi', $status, $completedAt, $projectId) && $updateStatus->execute()) {
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

        $conn->begin_transaction();

        try {
            if ($hasProjectAddressColumn) {
                if ($hasProjectEmailColumn) {
                    if ($hasProjectCodeColumn && $hasPoNumberColumn) {
                        $updateProject = $conn->prepare(
                            'UPDATE projects
                             SET project_name = ?, description = ?, client_id = ?, contact_person = ?, contact_number = ?, project_site = ?, project_address = ?, project_email = ?, project_code = ?, po_number = ?, start_date = ?, project_start_date = ?, estimated_completion_date = ?, end_date = ?
                             WHERE id = ?'
                        );
                    } else {
                        $updateProject = $conn->prepare(
                            'UPDATE projects
                             SET project_name = ?, description = ?, client_id = ?, contact_person = ?, contact_number = ?, project_site = ?, project_address = ?, project_email = ?, start_date = ?, project_start_date = ?, estimated_completion_date = ?, end_date = ?
                             WHERE id = ?'
                        );
                    }
                } else {
                    $updateProject = $conn->prepare(
                        'UPDATE projects
                         SET project_name = ?, description = ?, client_id = ?, contact_person = ?, contact_number = ?, project_site = ?, project_address = ?, start_date = ?, project_start_date = ?, estimated_completion_date = ?, end_date = ?
                         WHERE id = ?'
                    );
                }
            } else {
                $updateProject = $conn->prepare(
                    'UPDATE projects
                     SET project_name = ?, description = ?, client_id = ?, contact_person = ?, contact_number = ?, start_date = ?, project_start_date = ?, estimated_completion_date = ?, end_date = ?
                     WHERE id = ?'
                );
            }

            if (!$updateProject) {
                throw new RuntimeException('Failed to prepare project update.');
            }

            if ($hasProjectAddressColumn) {
                if ($hasProjectEmailColumn) {
                    if ($hasProjectCodeColumn && $hasPoNumberColumn) {
                        if (
                            !$updateProject->bind_param('ssisssssssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $projectEmail, $projectCode, $poNumber, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $projectId) ||
                            !$updateProject->execute()
                        ) {
                            throw new RuntimeException('Failed to update project details.');
                        }
                    } elseif (
                        !$updateProject->bind_param('ssisssssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $projectEmail, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $projectId) ||
                        !$updateProject->execute()
                    ) {
                        throw new RuntimeException('Failed to update project details.');
                    }
                } elseif (
                    !$updateProject->bind_param('ssissssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $projectId) ||
                    !$updateProject->execute()
                ) {
                    throw new RuntimeException('Failed to update project details.');
                }
            } else {
                if (
                    !$updateProject->bind_param('ssissssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $projectId) ||
                    !$updateProject->execute()
                ) {
                    throw new RuntimeException('Failed to update project details.');
                }
            }

            $clearAssignments = $conn->prepare('DELETE FROM project_assignments WHERE project_id = ?');

            if (!$clearAssignments) {
                throw new RuntimeException('Failed to prepare project team reassignment reset.');
            }

            if (
                !$clearAssignments->bind_param('i', $projectId) ||
                !$clearAssignments->execute()
            ) {
                throw new RuntimeException('Failed to reset current project team assignments.');
            }

            $reassignEngineer = $conn->prepare(
                'INSERT INTO project_assignments (project_id, engineer_id, assigned_by)
                 VALUES (?, ?, ?)'
            );

            if (!$reassignEngineer) {
                throw new RuntimeException('Failed to prepare project team reassignment.');
            }

            foreach ($engineerIds as $engineerId) {
                if (
                    !$reassignEngineer->bind_param('iii', $projectId, $engineerId, $updatedBy) ||
                    !$reassignEngineer->execute()
                ) {
                    throw new RuntimeException('Failed to update project team assignment.');
                }
            }

            if ($hasProjectAdditionalInfoColumn && !save_project_additional_info_json($conn, $projectId, $additionalInfoJson)) {
                throw new RuntimeException('Failed to update project additional info.');
            }

            $conn->commit();
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
            $conn->rollback();
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

        $insertTask = $conn->prepare(
            'INSERT INTO tasks (project_id, assigned_to, task_name, description, deadline, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        if (
            $insertTask &&
            $insertTask->bind_param('iisssi', $projectId, $assignedTo, $taskName, $description, $deadline, $createdBy) &&
            $insertTask->execute()
        ) {
            audit_log_event(
                $conn,
                $createdBy,
                'add_task',
                'task',
                (int)$insertTask->insert_id,
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

        $reopenProject = $conn->prepare("UPDATE projects SET status = 'ongoing', end_date = NULL WHERE id = ?");

        if ($reopenProject && $reopenProject->bind_param('i', $projectId) && $reopenProject->execute()) {
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

        $deleteProject = $conn->prepare(
            'UPDATE projects
             SET deleted_at = NOW(),
                 deleted_by = ?,
                 delete_scheduled_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                 restored_at = NULL,
                 restored_by = NULL
             WHERE id = ?
             AND deleted_at IS NULL'
        );

        if ($deleteProject && $deleteProject->bind_param('ii', $deletedBy, $projectId) && $deleteProject->execute() && $deleteProject->affected_rows > 0) {
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

        $restoreProject = $conn->prepare(
            'UPDATE projects
             SET deleted_at = NULL,
                 deleted_by = NULL,
                 delete_scheduled_at = NULL,
                 restored_at = NOW(),
                 restored_by = ?
             WHERE id = ?
             AND deleted_at IS NOT NULL'
        );

        if ($restoreProject && $restoreProject->bind_param('ii', $restoredBy, $projectId) && $restoreProject->execute() && $restoreProject->affected_rows > 0) {
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

        $deleteForever = $conn->prepare(
            'DELETE FROM projects
             WHERE id = ?
             AND deleted_at IS NOT NULL'
        );

        if ($deleteForever && $deleteForever->bind_param('i', $projectId) && $deleteForever->execute() && $deleteForever->affected_rows > 0) {
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
$ongoingProjects = (int)($statusCounts['ongoing'] ?? 0);
$completedProjects = (int)($statusCounts['completed'] ?? 0);

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
        COALESCE(SUM(bp.budget_amount), 0) AS total_budget,
        COALESCE((SELECT SUM(amount) FROM project_cost_entries), 0) AS total_cost,
        COUNT(bp.project_id) AS projects_with_budget,
        (SELECT COUNT(*) FROM project_cost_entries) AS total_cost_entries
     FROM project_budget_profiles bp"
);
$financialSummary = $financialSummaryResult ? $financialSummaryResult->fetch_assoc() : [];
$totalBudgetAmount = (float)($financialSummary['total_budget'] ?? 0);
$totalTrackedCost = (float)($financialSummary['total_cost'] ?? 0);
$projectsWithBudget = (int)($financialSummary['projects_with_budget'] ?? 0);
$totalCostEntries = (int)($financialSummary['total_cost_entries'] ?? 0);
$budgetCoverageRate = $totalProjects > 0 ? round(($projectsWithBudget / $totalProjects) * 100) : 0;
$portfolioRemainingBudget = $totalBudgetAmount - $totalTrackedCost;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Management - Super Admin</title>
    <link rel="stylesheet" href="../css/super_admin_dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>

    <main class="main-content projects-content">
        <div class="page-stack">
            <?php if ($flash): ?>
                <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : (($flash['type'] ?? '') === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <section class="metrics-grid">
                <article class="metric-card">
                    <span>Total Budget</span>
                    <strong><?php echo htmlspecialchars(format_money($totalBudgetAmount)); ?></strong>
                </article>
                <article class="metric-card">
                    <span>Total Recorded Cost</span>
                    <strong><?php echo htmlspecialchars(format_money($totalTrackedCost)); ?></strong>
                </article>
                <article class="metric-card">
                    <span>Remaining Portfolio Budget</span>
                    <strong><?php echo htmlspecialchars(format_money($portfolioRemainingBudget)); ?></strong>
                </article>
                <article class="metric-card">
                    <span>Coverage / Entries</span>
                    <strong><?php echo $budgetCoverageRate; ?>% / <?php echo $totalCostEntries; ?></strong>
                </article>
            </section>

            <?php if (!$isTrashView): ?>
            <section class="form-panel">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px;">
                    <div>
                        <h6 class="section-title-inline" style="margin-bottom: 4px;">Create Project</h6>
                    </div>
                    <button type="button" class="btn-secondary" id="create-project-clear-details">Clear Details</button>
                </div>
                <form method="POST" id="create-project-form" class="project-create-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_project">

                    <div class="project-create-top-grid">
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

                        <div class="input-group">
                            <label for="project_name">Project Title <span class="required-indicator" aria-hidden="true">*</span></label>
                            <input type="text" id="project_name" name="project_name" value="<?php echo htmlspecialchars($createProjectValues['project_name']); ?>" required>
                        </div>

                        <?php if ($hasProjectCodeColumn): ?>
                            <div class="input-group">
                                <label for="project_code">Project Code <span class="required-indicator" aria-hidden="true">*</span></label>
                                <input type="text" id="project_code" name="project_code" value="<?php echo htmlspecialchars($createProjectValues['project_code']); ?>" placeholder="Enter project code" required>
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
                            <div style="position: relative;">
                                <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #5f6b7a; font-weight: 600; pointer-events: none;">PHP</span>
                                <input
                                    type="text"
                                    id="budget_amount"
                                    name="budget_amount"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    placeholder="0.00"
                                    value="<?php echo htmlspecialchars($createProjectValues['budget_amount']); ?>"
                                    data-currency-input="php"
                                    style="padding-left: 52px;"
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
            </section>
            <?php endif; ?>

            <section
                class="page-stack"
                id="projects-list-section"
                data-reset-url="/codesamplecaps/SUPERADMIN/sidebar/projects.php<?php
                    $resetParams = [];
                    if ($statusFilter !== '') {
                        $resetParams['status'] = $statusFilter;
                    }
                    if ($isTrashView) {
                        $resetParams['view'] = 'trash';
                    }
                    echo $resetParams ? '?' . http_build_query($resetParams) : '';
                ?>"
                data-search-endpoint="/codesamplecaps/SUPERADMIN/sidebar/project_search_api.php"
            >
                
                <div class="project-controls">
                    <?php if (!$isTrashView): ?>
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
                                $chipLink = '/codesamplecaps/SUPERADMIN/sidebar/projects.php' . ($chipParams ? '?' . http_build_query($chipParams) : '');
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
                    </form>
                </div>

                <?php if (empty($projects)): ?>
                    <div class="empty-state">
                        <?php
                        if ($isTrashView) {
                            if ($searchQuery !== '' || $statusFilter !== '') {
                                echo 'No matching trashed projects found.';
                            } elseif ($trashBinTotal > 0) {
                                echo 'No trashed projects found right now.';
                            } else {
                                echo 'Trash is empty.';
                            }
                        } else {
                            echo $searchQuery !== '' || $statusFilter !== '' ? 'No matching projects found.' : 'No projects yet. Create your first project above.';
                        }
                        ?>
                    </div>
                <?php else: ?>
                    <div class="project-results-meta">
                        <span>Showing <?php echo count($projects); ?> of <?php echo $filteredProjects; ?> matching <?php echo $isTrashView ? 'trashed projects' : 'projects'; ?></span>
                        <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                    </div>

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
                            $detailsPath = '/codesamplecaps/SUPERADMIN/sidebar/project_details.php?id=' . (int)$project['id'];
                            ?>
                            <article class="project-card<?php echo $isCompleted ? ' is-locked' : ''; ?><?php echo $isDraft ? ' is-draft' : ''; ?>" data-project-card data-status="<?php echo htmlspecialchars($project['status']); ?>" data-search="<?php echo htmlspecialchars($searchText); ?>" data-title="<?php echo htmlspecialchars($project['project_name']); ?>" data-link="<?php echo htmlspecialchars($detailsPath); ?>" data-client="<?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?>" data-engineer="<?php echo htmlspecialchars($assignedEngineerNames !== '' ? $assignedEngineerNames : 'Not assigned'); ?>">
                                <div class="card-split">
                                    <div>
                                        <div class="project-card__eyebrow-row">
                                            <span class="project-card__eyebrow">Project Title</span>
                                            <?php if ($projectCode !== ''): ?>
                                                <span class="project-card__reference"><?php echo htmlspecialchars($projectCode); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <h3><?php echo htmlspecialchars($project['project_name']); ?></h3>
                                        <div class="status-pill-wrap">
                                            <span class="status-pill status-<?php echo htmlspecialchars($project['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($project['status'])); ?>
                                            </span>
                                        </div>
                                    </div>

                                        <div class="project-meta">
                                            <div><strong>Client:</strong> <?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?></div>
                                            <div><strong>Client Contact Person:</strong> <?php echo htmlspecialchars($projectContactPerson !== '' ? $projectContactPerson : 'Not set'); ?></div>
                                            <div><strong>Client Contact Number:</strong> <?php echo htmlspecialchars($projectContactNumber !== '' ? $projectContactNumber : 'Not set'); ?></div>
                                            <div><strong>Project Code:</strong> <?php echo htmlspecialchars($projectCode !== '' ? $projectCode : 'Not set'); ?></div>
                                            <div><strong>P.O Number:</strong> <?php echo htmlspecialchars($projectPoNumber !== '' ? $projectPoNumber : 'Not set'); ?></div>
                                            <div><strong>P.O Date:</strong> <?php echo htmlspecialchars($project['start_date'] ?? 'N/A'); ?></div>
                                            <div><strong>Assigned Team:</strong> <?php echo htmlspecialchars($assignedEngineerNames !== '' ? $assignedEngineerNames : 'Not assigned'); ?></div>
                                            <?php if ($hasProjectSiteColumn): ?>
                                                <div><strong>Project Site:</strong> <?php echo htmlspecialchars($projectSite !== '' ? $projectSite : 'Not set'); ?></div>
                                            <?php endif; ?>
                                            <?php if ($hasProjectAddressColumn): ?>
                                            <div><strong>Address:</strong> <?php echo htmlspecialchars($project['project_address'] ?? 'Not set'); ?></div>
                                        <?php endif; ?>
                                        <div><strong>Completed:</strong> <?php echo htmlspecialchars($project['end_date'] ?? 'N/A'); ?></div>
                                        <?php $projectProgress = build_role_project_progress($project, 'super_admin'); ?>
                                        <div><strong><?php echo htmlspecialchars((string)$projectProgress['label']); ?>:</strong> <?php echo (int)$projectProgress['percent']; ?>%</div>
                                        <div><strong>Progress Summary:</strong> <?php echo htmlspecialchars((string)$projectProgress['summary']); ?></div>
                                        <div><strong>Admin Insight:</strong> <?php echo htmlspecialchars((string)$projectProgress['hint']); ?></div>
                                    </div>
                                </div>

                                <?php if (!empty($project['description'])): ?>
                                    <div class="empty-state empty-state-solid project-card__description"><?php echo nl2br(htmlspecialchars($project['description'])); ?></div>
                                <?php endif; ?>

                                <?php if ($projectAdditionalInfoRows !== []): ?>
                                    <section class="project-additional-info-panel">
                                        <div class="project-additional-info-panel__header">
                                            <h4>Additional Info</h4>
                                            <span><?php echo count($projectAdditionalInfoRows); ?> contact<?php echo count($projectAdditionalInfoRows) === 1 ? '' : 's'; ?></span>
                                        </div>
                                        <div class="project-additional-info-panel__grid">
                                            <?php foreach ($projectAdditionalInfoRows as $projectAdditionalInfoRow): ?>
                                                <article class="project-additional-info-card">
                                                    <?php if (trim((string)($projectAdditionalInfoRow['contact_name'] ?? '')) !== ''): ?>
                                                        <div><strong>Name:</strong> <?php echo htmlspecialchars((string)$projectAdditionalInfoRow['contact_name']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (trim((string)($projectAdditionalInfoRow['contact_number'] ?? '')) !== ''): ?>
                                                        <div><strong>Number:</strong> <?php echo htmlspecialchars((string)$projectAdditionalInfoRow['contact_number']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (trim((string)($projectAdditionalInfoRow['email_address'] ?? '')) !== ''): ?>
                                                        <div><strong>Email:</strong> <?php echo htmlspecialchars((string)$projectAdditionalInfoRow['email_address']); ?></div>
                                                    <?php endif; ?>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endif; ?>

                                <?php if ($isTrashView): ?>
                                    <section class="project-trash-panel">
                                        <div><strong>Deleted:</strong> <?php echo htmlspecialchars($deletedAt !== '' ? $deletedAt : 'N/A'); ?></div>
                                        <div><strong>Permanent delete:</strong> <?php echo htmlspecialchars($deleteScheduledAt !== '' ? $deleteScheduledAt : 'N/A'); ?></div>
                                        <div><strong>Days left:</strong> <?php echo $daysUntilPurge !== null ? $daysUntilPurge : 'N/A'; ?></div>
                                    </section>
                                <?php endif; ?>

                                <section class="budget-panel budget-panel--<?php echo htmlspecialchars($budgetHealth['status']); ?>">
                                    <div class="budget-panel__header">
                                        <div>
                                            <h4>Budget Overview</h4>
                                            <span class="budget-health budget-health--<?php echo htmlspecialchars($budgetHealth['status']); ?>">
                                                <?php echo htmlspecialchars($budgetHealth['label']); ?>
                                            </span>
                                        </div>
                                        <strong><?php echo htmlspecialchars(format_money($remainingBudget)); ?></strong>
                                    </div>
                                    <div class="budget-stats">
                                        <div>
                                            <span>Budget</span>
                                            <strong><?php echo htmlspecialchars(format_money($budgetAmount)); ?></strong>
                                        </div>
                                        <div>
                                            <span>Actual</span>
                                            <strong><?php echo htmlspecialchars(format_money($totalCost)); ?></strong>
                                        </div>
                                        <div>
                                            <span>Entries</span>
                                            <strong><?php echo (int)($project['cost_entry_count'] ?? 0); ?></strong>
                                        </div>
                                    </div>
                                    <div class="budget-progress">
                                        <div class="budget-progress__track">
                                            <span class="budget-progress__fill budget-progress__fill--<?php echo htmlspecialchars($budgetHealth['status']); ?>" style="width: <?php echo $budgetUsage; ?>%;"></span>
                                        </div>
                                        <small><?php echo $budgetAmount > 0 ? $budgetUsage . '% spent' : 'Budget is optional; actual costs can still be tracked.'; ?></small>
                                    </div>
                                    <?php if ($budgetNotes !== ''): ?>
                                        <div class="budget-notes"><?php echo nl2br(htmlspecialchars($budgetNotes)); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($projectRecentCosts)): ?>
                                        <div class="budget-ledger">
                                            <?php foreach ($projectRecentCosts as $costEntry): ?>
                                                <div class="budget-ledger__item">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($costEntry['cost_category'] ?? 'Cost'); ?></strong>
                                                        <span><?php echo htmlspecialchars($costEntry['cost_date'] ?? ''); ?><?php echo !empty($costEntry['created_by_name']) ? ' • ' . htmlspecialchars($costEntry['created_by_name']) : ''; ?></span>
                                                        <?php if (!empty($costEntry['description'])): ?>
                                                            <small><?php echo htmlspecialchars($costEntry['description']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <strong><?php echo htmlspecialchars(format_money((float)($costEntry['amount'] ?? 0))); ?></strong>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-state">No cost entries yet.</div>
                                    <?php endif; ?>
                                </section>

                                <div class="form-actions project-card__actions">
                                    <?php if ($isTrashView): ?>
                                        <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Restore this project from trash?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="restore_project">
                                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                            <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                            <button type="submit" class="btn-secondary">Restore</button>
                                        </form>
                                        <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Permanently delete this project? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="permanently_delete_project">
                                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                            <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                            <button type="submit" class="btn-danger">Delete Permanently</button>
                                        </form>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($detailsPath); ?>" class="btn-primary project-card__details-btn">View Details</a>
                                        <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Move this project to trash? It will be permanently deleted after 30 days.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_project">
                                            <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars('/codesamplecaps/SUPERADMIN/sidebar/projects.php' . ($statusFilter !== '' || $searchQuery !== '' ? '?' . http_build_query(array_filter(['q' => $searchQuery, 'status' => $statusFilter])) : '')); ?>">
                                            <button type="submit" class="btn-danger">Delete</button>
                                        </form>
                                    <?php endif; ?>
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
                                $pageLink = '/codesamplecaps/SUPERADMIN/sidebar/projects.php?' . http_build_query($pageParams);
                                ?>
                                <a href="<?php echo htmlspecialchars($pageLink); ?>" class="pagination-link<?php echo $page === $currentPage ? ' is-active' : ''; ?>">
                                    <?php echo $page; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($isTrashView): ?>
                    <section class="page-stack" style="margin-top: 24px;">
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
                                                <div><strong>Email:</strong> <?php echo htmlspecialchars((string)($trashedUser['email'] ?? 'Not set')); ?></div>
                                                <div><strong>Phone:</strong> <?php echo htmlspecialchars((string)($trashedUser['phone'] ?? 'Not set')); ?></div>
                                                <div><strong>Moved To Trash:</strong> <?php echo htmlspecialchars((string)($trashedUser['deleted_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <div class="form-actions project-card__actions">
                                            <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Restore this user from trash?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_user">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$trashedUser['id']; ?>">
                                                <button type="submit" class="btn-secondary">Restore</button>
                                            </form>
                                            <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Permanently delete this user? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="permanently_delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$trashedUser['id']; ?>">
                                                <button type="submit" class="btn-danger">Delete Permanently</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="page-stack" style="margin-top: 24px;">
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
                                                <div><strong>Project:</strong> <?php echo htmlspecialchars((string)($trashedRequest['project_name'] ?? 'N/A')); ?></div>
                                                <div><strong>Requested By:</strong> <?php echo htmlspecialchars((string)($trashedRequest['requested_by_name'] ?? 'N/A')); ?></div>
                                                <div><strong>Type:</strong> <?php echo htmlspecialchars(ucfirst((string)($trashedRequest['request_type'] ?? 'material'))); ?></div>
                                                <div><strong>Qty:</strong> <?php echo htmlspecialchars((string)($trashedRequest['quantity_requested'] ?? '0')); ?> <?php echo htmlspecialchars((string)($trashedRequest['unit'] ?? '')); ?></div>
                                                <div><strong>Needed Date:</strong> <?php echo htmlspecialchars((string)($trashedRequest['needed_date'] ?? 'Not set')); ?></div>
                                                <div><strong>Site:</strong> <?php echo htmlspecialchars((string)($trashedRequest['site_location'] ?? 'Not set')); ?></div>
                                                <div><strong>Moved To Trash:</strong> <?php echo htmlspecialchars((string)($trashedRequest['updated_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($trashedRequest['remarks'])): ?>
                                            <div class="lock-note"><strong>Remarks:</strong> <?php echo htmlspecialchars((string)$trashedRequest['remarks']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-actions project-card__actions">
                                            <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Restore this purchase request from trash?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_purchase_request">
                                                <input type="hidden" name="purchase_request_id" value="<?php echo (int)$trashedRequest['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                                <button type="submit" class="btn-secondary">Restore</button>
                                            </form>
                                            <?php if ((int)($trashedRequest['linked_purchase_orders'] ?? 0) > 0): ?>
                                                <div class="lock-note">Cannot permanently delete. This request already has a purchase order.</div>
                                            <?php else: ?>
                                                <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Permanently delete this purchase request? This cannot be undone.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="permanently_delete_purchase_request">
                                                    <input type="hidden" name="purchase_request_id" value="<?php echo (int)$trashedRequest['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                                    <button type="submit" class="btn-danger">Delete Permanently</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="page-stack" style="margin-top: 24px;">
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
                                                <div><strong>Address:</strong> <?php echo htmlspecialchars((string)($trashedSupplier['address'] ?? 'Not set')); ?></div>
                                                <div><strong>Moved To Trash:</strong> <?php echo htmlspecialchars((string)($trashedSupplier['updated_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($trashedSupplier['description'])): ?>
                                            <div class="lock-note"><strong>Description:</strong> <?php echo htmlspecialchars((string)$trashedSupplier['description']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-actions project-card__actions">
                                            <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Restore this supplier from trash?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_supplier">
                                                <input type="hidden" name="supplier_id" value="<?php echo (int)$trashedSupplier['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                                <button type="submit" class="btn-secondary">Restore</button>
                                            </form>
                                            <?php if ((int)($trashedSupplier['linked_purchase_orders'] ?? 0) > 0): ?>
                                                <div class="lock-note">Cannot permanently delete. This supplier is linked to purchase orders.</div>
                                            <?php else: ?>
                                                <form method="POST" class="project-card__inline-form" onsubmit="return confirm('Permanently delete this supplier? This cannot be undone.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="permanently_delete_supplier">
                                                    <input type="hidden" name="supplier_id" value="<?php echo (int)$trashedSupplier['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                                    <button type="submit" class="btn-danger">Delete Permanently</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="page-stack" style="margin-top: 24px;">
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
                                                <div><strong>Criticality:</strong> <?php echo htmlspecialchars(ucfirst((string)($trashedAsset['criticality'] ?? 'medium'))); ?></div>
                                                <div><strong>Available Qty:</strong> <?php echo (int)($trashedAsset['inventory_quantity'] ?? 0); ?></div>
                                                <div><strong>Min Stock:</strong> <?php echo $trashedAsset['inventory_min_stock'] !== null ? (int)$trashedAsset['inventory_min_stock'] : 'N/A'; ?></div>
                                                <div><strong>Units:</strong> Avail <?php echo (int)($trashedAsset['available_units'] ?? 0); ?> | In Use <?php echo (int)($trashedAsset['deployed_units'] ?? 0); ?> | Maint <?php echo (int)($trashedAsset['maintenance_units'] ?? 0); ?> | Lost <?php echo (int)($trashedAsset['lost_units'] ?? 0); ?></div>
                                                <div><strong>Moved To Trash:</strong> <?php echo htmlspecialchars((string)($trashedAsset['deleted_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <div class="form-actions project-card__actions">
                                            <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/assets.php" class="project-card__inline-form" onsubmit="return confirm('Restore this asset from trash?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore_asset">
                                                <input type="hidden" name="asset_id" value="<?php echo (int)$trashedAsset['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                                <button type="submit" class="btn-secondary">Restore</button>
                                            </form>
                                            <form method="POST" action="/codesamplecaps/SUPERADMIN/sidebar/assets.php" class="project-card__inline-form" onsubmit="return confirm('Permanently delete this asset? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="permanently_delete_asset">
                                                <input type="hidden" name="asset_id" value="<?php echo (int)$trashedAsset['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                                <button type="submit" class="btn-danger">Delete Permanently</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script src="../js/super_admin_dashboard.js"></script>
<script>
function initProjectSearchUI() {
    const section = document.getElementById('projects-list-section');
    const searchForm = document.getElementById('project-search-form');
    const searchInput = document.getElementById('project-search');
    const searchClear = document.getElementById('project-search-clear');
    const searchDropdown = document.getElementById('project-search-dropdown');
    const projectCards = Array.from(document.querySelectorAll('[data-project-card]'));
    const statusInput = searchForm?.querySelector('input[name="status"]');
    const viewInput = searchForm?.querySelector('input[name="view"]');
    let activeSuggestionIndex = -1;
    let searchDebounceId = null;
    const savedFocusState = window.__projectSearchFocusState || null;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function highlightMatch(text, query) {
        const lowerText = text.toLowerCase();
        const matchIndex = lowerText.indexOf(query);

        if (matchIndex === -1 || query === '') {
            return escapeHtml(text);
        }

        const before = escapeHtml(text.slice(0, matchIndex));
        const matched = escapeHtml(text.slice(matchIndex, matchIndex + query.length));
        const after = escapeHtml(text.slice(matchIndex + query.length));

        return before + '<mark>' + matched + '</mark>' + after;
    }

    function getSuggestionLinks() {
        return Array.from(searchDropdown?.querySelectorAll('.project-search-result') || []);
    }

    function syncSuggestionFocus() {
        const links = getSuggestionLinks();

        links.forEach(function (link, index) {
            link.classList.toggle('is-active', index === activeSuggestionIndex);
        });
    }

    function calculateSearchScore(card, query) {
        const title = (card.getAttribute('data-title') || '').toLowerCase();
        const client = (card.getAttribute('data-client') || '').toLowerCase();
        const engineer = (card.getAttribute('data-engineer') || '').toLowerCase();
        const status = (card.getAttribute('data-status') || '').toLowerCase();
        let score = 0;

        // Title match gets highest priority
        if (title.startsWith(query)) score += 100;
        else if (title.includes(query)) score += 80;

        // Status match
        if (status.startsWith(query)) score += 50;
        else if (status.includes(query)) score += 30;

        // Client match
        if (client.startsWith(query)) score += 40;
        else if (client.includes(query)) score += 20;

        // Engineer match
        if (engineer.startsWith(query)) score += 40;
        else if (engineer.includes(query)) score += 15;

        return score;
    }

    function updateSearchDropdown() {
        if (!searchInput || !searchDropdown) {
            return;
        }

        const query = searchInput.value.trim().toLowerCase();

        if (query.length < 1) {
            searchDropdown.hidden = true;
            searchDropdown.innerHTML = '';
            activeSuggestionIndex = -1;
            return;
        }

        const matches = projectCards
            .map(function (card) {
                return {
                    card: card,
                    score: calculateSearchScore(card, query)
                };
            })
            .filter(function (item) {
                return item.score > 0;
            })
            .sort(function (a, b) {
                return b.score - a.score;
            })
            .slice(0, 8)
            .map(function (item) {
                return item.card;
            });

        if (matches.length === 0) {
            searchDropdown.innerHTML = '<div class="project-search-empty">No matching projects yet.</div>';
            searchDropdown.hidden = false;
            activeSuggestionIndex = -1;
            return;
        }

        searchDropdown.innerHTML = matches.map(function (card) {
            const title = card.getAttribute('data-title') || 'Project';
            const status = card.getAttribute('data-status') || '';
            const link = card.getAttribute('data-link') || '#';
            const client = card.getAttribute('data-client') || 'N/A';
            const engineer = card.getAttribute('data-engineer') || 'Not assigned';
            const statusBadgeClass = 'search-status-badge status-' + escapeHtml(status);
            const statusLabel = status.charAt(0).toUpperCase() + status.slice(1);

            return '<a class="project-search-result" href="' + link + '">' +
                '<div class="search-result-header">' +
                '<strong>' + highlightMatch(title, query) + '</strong>' +
                '<span class="' + statusBadgeClass + '">' + escapeHtml(statusLabel) + '</span>' +
                '</div>' +
                '<div class="search-result-meta">' +
                '<small>👤 ' + escapeHtml(client) + ' · 👨‍💼 ' + escapeHtml(engineer) + '</small>' +
                '</div>' +
                '</a>';
        }).join('');
        searchDropdown.hidden = false;
        activeSuggestionIndex = -1;
        syncSuggestionFocus();
    }

    function updateClearVisibility() {
        if (!searchInput || !searchClear) {
            return;
        }

        searchClear.classList.toggle('is-visible', searchInput.value.trim() !== '');
    }

    function refreshProjectsSection(url) {
        if (!section) {
            window.location.href = url;
            return;
        }

        if (searchInput) {
            window.__projectSearchFocusState = {
                value: searchInput.value,
                selectionStart: searchInput.selectionStart ?? searchInput.value.length,
                selectionEnd: searchInput.selectionEnd ?? searchInput.value.length,
            };
        }

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function (response) {
                return response.text();
            })
            .then(function (html) {
                const parser = new DOMParser();
                const documentFromResponse = parser.parseFromString(html, 'text/html');
                const nextSection = documentFromResponse.getElementById('projects-list-section');

                if (!nextSection) {
                    window.location.href = url;
                    return;
                }

                section.replaceWith(nextSection);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', url);
                }
                initProjectSearchUI();
            })
            .catch(function () {
                window.location.href = url;
            });
    }

    function buildProjectsUrl() {
        const params = new URLSearchParams();
        const queryValue = searchInput ? searchInput.value.trim() : '';
        const statusValue = statusInput ? statusInput.value.trim() : '';

        if (queryValue !== '') {
            params.set('q', queryValue);
        }

        if (statusValue !== '') {
            params.set('status', statusValue);
        }

        if (viewInput && String(viewInput.value || '').trim() !== '') {
            params.set('view', String(viewInput.value || '').trim());
        }

        const queryString = params.toString();
        return '/codesamplecaps/SUPERADMIN/sidebar/projects.php' + (queryString ? '?' + queryString : '');
    }

    function triggerSearchRefresh(immediate) {
        if (!searchInput) {
            return;
        }

        if (searchDebounceId) {
            window.clearTimeout(searchDebounceId);
        }

        const runSearch = function () {
            refreshProjectsSection(buildProjectsUrl());
        };

        if (immediate) {
            runSearch();
            return;
        }

        searchDebounceId = window.setTimeout(runSearch, 3000);
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            updateClearVisibility();
            updateSearchDropdown();
            triggerSearchRefresh(false);
        });

        searchInput.addEventListener('focus', updateSearchDropdown);
        searchInput.addEventListener('keydown', function (event) {
            const links = getSuggestionLinks();

            if (searchDropdown.hidden || links.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeSuggestionIndex = (activeSuggestionIndex + 1) % links.length;
                syncSuggestionFocus();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeSuggestionIndex = activeSuggestionIndex <= 0 ? links.length - 1 : activeSuggestionIndex - 1;
                syncSuggestionFocus();
                return;
            }

            if (event.key === 'Enter' && activeSuggestionIndex >= 0 && links[activeSuggestionIndex]) {
                event.preventDefault();
                window.location.href = links[activeSuggestionIndex].href;
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                triggerSearchRefresh(true);
                return;
            }

            if (event.key === 'Escape') {
                searchDropdown.hidden = true;
                activeSuggestionIndex = -1;
            }
        });
    }

    if (searchForm) {
        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            triggerSearchRefresh(true);
        });
    }

    if (searchClear) {
        searchClear.addEventListener('click', function (event) {
            event.preventDefault();

            if (!searchInput) {
                return;
            }

            searchInput.value = '';
            updateClearVisibility();
            if (searchDropdown) {
                searchDropdown.hidden = true;
                searchDropdown.innerHTML = '';
            }

            if (searchDebounceId) {
                window.clearTimeout(searchDebounceId);
            }

            const resetUrl = section?.getAttribute('data-reset-url') || searchClear.getAttribute('href') || '/codesamplecaps/SUPERADMIN/sidebar/projects.php';
            refreshProjectsSection(resetUrl);
        });
    }

    if (!window.__projectSearchOutsideBound) {
        document.addEventListener('click', function (event) {
            const currentDropdown = document.getElementById('project-search-dropdown');
            const isInsideSearch = event.target.closest('.project-search-shell');

            if (!isInsideSearch && currentDropdown) {
                currentDropdown.hidden = true;
            }
        });

        window.__projectSearchOutsideBound = true;
    }

    updateClearVisibility();
    updateSearchDropdown();
    if (savedFocusState && searchInput) {
        const restoredValue = typeof savedFocusState.value === 'string' ? savedFocusState.value : searchInput.value;
        searchInput.value = restoredValue;
        searchInput.focus();
        const cursorStart = typeof savedFocusState.selectionStart === 'number' ? savedFocusState.selectionStart : restoredValue.length;
        const cursorEnd = typeof savedFocusState.selectionEnd === 'number' ? savedFocusState.selectionEnd : restoredValue.length;
        searchInput.setSelectionRange(cursorStart, cursorEnd);
        window.__projectSearchFocusState = null;
    }
}

function initCreateProjectForm() {
    const createProjectForm = document.getElementById('create-project-form');
    const focusFieldName = <?php echo json_encode($createProjectFocusField, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    if (!createProjectForm) {
        return;
    }

    createProjectForm.addEventListener('submit', function () {
        const firstInvalidField = createProjectForm.querySelector(':invalid');

        if (firstInvalidField && typeof firstInvalidField.focus === 'function') {
            firstInvalidField.focus();
        }
    });

    const projectStartDateField = createProjectForm.elements.namedItem('project_start_date');
    const estimatedCompletionDateField = createProjectForm.elements.namedItem('estimated_completion_date');
    const projectDurationField = createProjectForm.elements.namedItem('estimated_duration_days');
    const poDateField = createProjectForm.elements.namedItem('start_date');

    function calculateDurationDays(startDate, endDate) {
        if (!startDate || !endDate) {
            return '';
        }

        const start = new Date(startDate + 'T00:00:00');
        const end = new Date(endDate + 'T00:00:00');
        const diff = end.getTime() - start.getTime();

        if (Number.isNaN(diff) || diff < 0) {
            return '';
        }

        return String(Math.floor(diff / 86400000) + 1);
    }

    function calculateEstimatedCompletionDate(startDate, durationDays) {
        if (!startDate || !durationDays) {
            return '';
        }

        const normalizedDuration = Number(durationDays);
        if (!Number.isFinite(normalizedDuration) || normalizedDuration <= 0) {
            return '';
        }

        const start = new Date(startDate + 'T00:00:00');
        if (Number.isNaN(start.getTime())) {
            return '';
        }

        start.setDate(start.getDate() + normalizedDuration - 1);
        const year = start.getFullYear();
        const month = String(start.getMonth() + 1).padStart(2, '0');
        const day = String(start.getDate()).padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    function syncProjectTimelineValidation(source) {
        if (!projectStartDateField || !estimatedCompletionDateField) {
            return;
        }

        const poDate = poDateField ? String(poDateField.value || '') : '';
        const projectStartDate = String(projectStartDateField.value || '');
        if (poDateField) {
            projectStartDateField.min = poDate;
        }
        estimatedCompletionDateField.min = projectStartDate;

        if (source === 'duration' && projectDurationField) {
            const nextCompletionDate = calculateEstimatedCompletionDate(projectStartDate, String(projectDurationField.value || ''));
            if (nextCompletionDate !== '') {
                estimatedCompletionDateField.value = nextCompletionDate;
            }
        }

        if (source === 'completion' && projectDurationField) {
            projectDurationField.value = calculateDurationDays(projectStartDate, String(estimatedCompletionDateField.value || ''));
        }

        if (poDate !== '' && projectStartDate !== '' && projectStartDate < poDate) {
            projectStartDateField.setCustomValidity('Project Start Date must be the same as or later than P.O Date.');
        } else {
            projectStartDateField.setCustomValidity('');
        }

        if (projectStartDate !== '' && String(estimatedCompletionDateField.value || '') !== '' && estimatedCompletionDateField.value < projectStartDate) {
            estimatedCompletionDateField.setCustomValidity('Estimated Completion Date must be the same as or later than Project Start Date.');
        } else {
            estimatedCompletionDateField.setCustomValidity('');
        }

        if (projectDurationField && source !== 'completion') {
            projectDurationField.value = calculateDurationDays(projectStartDate, String(estimatedCompletionDateField.value || ''));
        }
    }

    if (projectStartDateField && estimatedCompletionDateField) {
        if (poDateField) {
            poDateField.addEventListener('input', function () {
                syncProjectTimelineValidation('po');
            });
        }
        projectStartDateField.addEventListener('input', function () {
            syncProjectTimelineValidation('start');
        });
        estimatedCompletionDateField.addEventListener('input', function () {
            syncProjectTimelineValidation('completion');
        });
        if (projectDurationField) {
            projectDurationField.addEventListener('input', function () {
                syncProjectTimelineValidation('duration');
            });
        }
        syncProjectTimelineValidation('init');
    }

    if (focusFieldName !== '') {
        const targetField = createProjectForm.elements.namedItem(focusFieldName) || document.getElementById(focusFieldName);

        if (targetField && typeof targetField.focus === 'function') {
            window.setTimeout(function () {
                targetField.focus();
                if (typeof targetField.scrollIntoView === 'function') {
                    targetField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 80);
        }
    }
}

function initCreateProjectClientAutofill() {
    const createProjectForm = document.getElementById('create-project-form');

    if (!createProjectForm) {
        return;
    }

    const clientField = createProjectForm.elements.namedItem('client_id');
    const contactPersonField = createProjectForm.elements.namedItem('contact_person');
    const contactNumberField = createProjectForm.elements.namedItem('contact_number');

    if (!clientField) {
        return;
    }

    const getSelectedClient = function () {
        const selectedOption = clientField.options[clientField.selectedIndex];
        if (!selectedOption || selectedOption.value === '') {
            return null;
        }

        return {
            name: String(selectedOption.getAttribute('data-client-name') || '').trim(),
            phone: String(selectedOption.getAttribute('data-client-phone') || '').trim(),
        };
    };

    const syncClientDetails = function (forceOverwrite) {
        const selectedClient = getSelectedClient();

        if (!selectedClient) {
            if (forceOverwrite) {
                if (contactPersonField) {
                    contactPersonField.value = '';
                }
                if (contactNumberField) {
                    contactNumberField.value = '';
                }
            }
            return;
        }

        if (contactPersonField && (forceOverwrite || String(contactPersonField.value || '').trim() === '')) {
            contactPersonField.value = selectedClient.name;
        }

        if (contactNumberField && (forceOverwrite || String(contactNumberField.value || '').trim() === '')) {
            contactNumberField.value = selectedClient.phone;
        }
    };

    clientField.addEventListener('change', function () {
        syncClientDetails(true);
        createProjectForm.dispatchEvent(new Event('input', { bubbles: true }));
        createProjectForm.dispatchEvent(new Event('change', { bubbles: true }));
    });

    if (String(clientField.value || '').trim() !== '') {
        syncClientDetails(false);
    }
}

function initProjectAdditionalInfoRows() {
    const createProjectForm = document.getElementById('create-project-form');
    const section = createProjectForm?.querySelector('[data-additional-info-section]');
    const list = section?.querySelector('[data-additional-info-list]');
    const addButton = section?.querySelector('[data-additional-info-add]');
    const template = section?.querySelector('[data-additional-info-template]');

    if (!createProjectForm || !section || !list || !addButton || !template) {
        return;
    }

    let nextIndex = Number(list.getAttribute('data-next-index') || '0');
    if (!Number.isFinite(nextIndex) || nextIndex < 0) {
        nextIndex = list.querySelectorAll('[data-additional-info-item]').length;
    }

    function buildRow(values) {
        const rowMarkup = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
        const fragment = document.createRange().createContextualFragment(rowMarkup);
        const row = fragment.querySelector('[data-additional-info-item]');

        if (!row) {
            return null;
        }

        row.querySelector('[data-additional-info-name]').value = String(values?.contact_name || '');
        row.querySelector('[data-additional-info-number]').value = String(values?.contact_number || '');
        row.querySelector('[data-additional-info-email]').value = String(values?.email_address || '');

        return row;
    }

    function addRow(values = {}, shouldFocus = false) {
        const row = buildRow(values);
        if (!row) {
            return;
        }

        list.appendChild(row);

        if (shouldFocus) {
            const firstField = row.querySelector('[data-additional-info-name]');
            if (firstField && typeof firstField.focus === 'function') {
                firstField.focus();
            }
        }

        syncRemoveButtons();
    }

    function ensureAtLeastOneRow() {
        if (list.querySelector('[data-additional-info-item]')) {
            return;
        }

        addRow();
    }

    function syncRemoveButtons() {
        const rows = Array.from(list.querySelectorAll('[data-additional-info-item]'));

        rows.forEach(function (row, index) {
            const removeButton = row.querySelector('[data-additional-info-remove]');
            if (!removeButton) {
                return;
            }

            const isBaseRow = index === 0;
            removeButton.hidden = isBaseRow;
            removeButton.disabled = isBaseRow;
        });
    }

    function collectRows() {
        return Array.from(list.querySelectorAll('[data-additional-info-item]')).map(function (row) {
            return {
                contact_name: String(row.querySelector('[data-additional-info-name]')?.value || ''),
                contact_number: String(row.querySelector('[data-additional-info-number]')?.value || ''),
                email_address: String(row.querySelector('[data-additional-info-email]')?.value || ''),
            };
        });
    }

    function setRows(rows) {
        list.innerHTML = '';
        nextIndex = 0;

        if (Array.isArray(rows) && rows.length > 0) {
            rows.forEach(function (row) {
                addRow(row);
            });
        } else {
            addRow();
        }
    }

    addButton.addEventListener('click', function () {
        addRow({}, true);
        createProjectForm.dispatchEvent(new Event('input', { bubbles: true }));
    });

    list.addEventListener('click', function (event) {
        const removeButton = event.target.closest('[data-additional-info-remove]');
        if (!removeButton) {
            return;
        }

        const row = removeButton.closest('[data-additional-info-item]');
        if (!row) {
            return;
        }

        row.remove();
        ensureAtLeastOneRow();
        syncRemoveButtons();
        createProjectForm.dispatchEvent(new Event('input', { bubbles: true }));
    });

    ensureAtLeastOneRow();
    syncRemoveButtons();

    window.__projectAdditionalInfoManager = {
        getRows: collectRows,
        setRows: setRows,
    };
}

function initCreateProjectDraft() {
    const createProjectForm = document.getElementById('create-project-form');
    const clearButton = document.getElementById('create-project-clear-details');
    const hasServerDraft = <?php echo $hasCreateProjectServerInput ? 'true' : 'false'; ?>;
    const shouldClearStoredDraft = <?php echo $shouldClearCreateProjectDraft ? 'true' : 'false'; ?>;
    const defaultDraft = {
        project_name: '',
        project_code: '',
        po_number: '',
        client_id: '',
        contact_person: '',
        contact_number: '',
        engineer_ids: [],
        status: 'pending',
        start_date: <?php echo json_encode($todayDate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
        project_start_date: '',
        estimated_completion_date: '',
        project_site: '',
        budget_amount: '',
        budget_notes: '',
        description: '',
        project_address: '',
        additional_info: [<?php echo json_encode(blank_project_additional_info_row(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>],
    };
    const draftStorageKey = 'codesamplecaps.superadmin.projects.createProjectDraft.v1';

    if (!createProjectForm) {
        return;
    }

    function buildEngineerChip(engineerId, engineerName) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'engineer-chip';
        chip.setAttribute('data-engineer-chip', '');
        chip.setAttribute('data-engineer-id', engineerId);
        chip.setAttribute('data-engineer-name', engineerName);
        chip.setAttribute('aria-pressed', 'true');
        chip.innerHTML = '<span></span><span class="engineer-chip__remove" aria-hidden="true">&times;</span>';
        chip.querySelector('span').textContent = engineerName;
        return chip;
    }

    function setFieldValue(name, value) {
        const field = createProjectForm.elements.namedItem(name);

        if (!field) {
            return;
        }

        if (field instanceof RadioNodeList) {
            Array.from(field).forEach(function (option) {
                option.checked = option.value === value;
            });
            return;
        }

        field.value = value;
    }

    function setEngineerIds(engineerIds) {
        const selectedContainer = createProjectForm.querySelector('[data-engineer-selected]');
        const inputsContainer = createProjectForm.querySelector('[data-engineer-inputs]');
        const engineerSelect = createProjectForm.querySelector('[data-engineer-select]');

        if (!selectedContainer || !inputsContainer || !engineerSelect) {
            return;
        }

        selectedContainer.innerHTML = '';
        inputsContainer.innerHTML = '';
        engineerSelect.value = '';

        engineerIds.forEach(function (engineerId) {
            const option = Array.from(engineerSelect.options).find(function (candidate) {
                return candidate.value === String(engineerId);
            });

            if (!option || option.value === '') {
                return;
            }

            selectedContainer.appendChild(buildEngineerChip(option.value, option.text));

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'engineer_ids[]';
            hiddenInput.value = option.value;
            hiddenInput.setAttribute('data-engineer-input', '');
            inputsContainer.appendChild(hiddenInput);
        });
    }

    function getEngineerIds() {
        return Array.from(createProjectForm.querySelectorAll('[data-engineer-input]')).map(function (input) {
            return String(input.value);
        });
    }

    function collectDraft() {
        return {
            project_name: String(createProjectForm.elements.namedItem('project_name')?.value || ''),
            project_code: String(createProjectForm.elements.namedItem('project_code')?.value || ''),
            po_number: String(createProjectForm.elements.namedItem('po_number')?.value || ''),
            client_id: String(createProjectForm.elements.namedItem('client_id')?.value || ''),
            contact_person: String(createProjectForm.elements.namedItem('contact_person')?.value || ''),
            contact_number: String(createProjectForm.elements.namedItem('contact_number')?.value || ''),
            engineer_ids: getEngineerIds(),
            status: String(createProjectForm.elements.namedItem('status')?.value || defaultDraft.status),
            start_date: String(createProjectForm.elements.namedItem('start_date')?.value || ''),
            project_start_date: String(createProjectForm.elements.namedItem('project_start_date')?.value || ''),
            estimated_completion_date: String(createProjectForm.elements.namedItem('estimated_completion_date')?.value || ''),
            project_site: String(createProjectForm.elements.namedItem('project_site')?.value || ''),
            budget_amount: String(createProjectForm.elements.namedItem('budget_amount')?.value || ''),
            budget_notes: String(createProjectForm.elements.namedItem('budget_notes')?.value || ''),
            description: String(createProjectForm.elements.namedItem('description')?.value || ''),
            project_address: String(createProjectForm.elements.namedItem('project_address')?.value || ''),
            additional_info: window.__projectAdditionalInfoManager
                ? window.__projectAdditionalInfoManager.getRows()
                : defaultDraft.additional_info,
        };
    }

    function saveDraft() {
        try {
            window.localStorage.setItem(draftStorageKey, JSON.stringify(collectDraft()));
        } catch (error) {
        }
    }

    function loadStoredDraft() {
        try {
            const rawDraft = window.localStorage.getItem(draftStorageKey);
            if (!rawDraft) {
                return null;
            }

            const parsedDraft = JSON.parse(rawDraft);
            return parsedDraft && typeof parsedDraft === 'object' ? parsedDraft : null;
        } catch (error) {
            return null;
        }
    }

    function clearStoredDraft() {
        try {
            window.localStorage.removeItem(draftStorageKey);
        } catch (error) {
        }
    }

    function applyDraft(draft) {
        if (!draft || typeof draft !== 'object') {
            return;
        }

        Object.keys(defaultDraft).forEach(function (fieldName) {
            if (fieldName === 'engineer_ids' || fieldName === 'additional_info') {
                return;
            }

            const nextValue = Object.prototype.hasOwnProperty.call(draft, fieldName)
                ? String(draft[fieldName] ?? '')
                : String(defaultDraft[fieldName] ?? '');

            setFieldValue(fieldName, nextValue);
        });

        setEngineerIds(Array.isArray(draft.engineer_ids) ? draft.engineer_ids.map(String) : []);

        if (window.__projectAdditionalInfoManager) {
            const nextRows = Array.isArray(draft.additional_info) ? draft.additional_info : defaultDraft.additional_info;
            window.__projectAdditionalInfoManager.setRows(nextRows);
        }
    }

    if (shouldClearStoredDraft) {
        clearStoredDraft();
    } else if (!hasServerDraft) {
        applyDraft(loadStoredDraft());
    }

    createProjectForm.addEventListener('input', saveDraft);
    createProjectForm.addEventListener('change', saveDraft);

    const engineerInputsContainer = createProjectForm.querySelector('[data-engineer-inputs]');
    if (engineerInputsContainer) {
        const observer = new MutationObserver(saveDraft);
        observer.observe(engineerInputsContainer, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });
    }

    if (clearButton) {
        clearButton.addEventListener('click', function () {
            if (!window.confirm('Clear all saved project details?')) {
                return;
            }

            applyDraft(defaultDraft);

            const currencyInput = createProjectForm.querySelector('[data-currency-input="php"]');
            if (currencyInput) {
                currencyInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            clearStoredDraft();
        });
    }
}

function initCurrencyInputs() {
    const currencyInputs = Array.from(document.querySelectorAll('[data-currency-input="php"]'));

    if (currencyInputs.length === 0) {
        return;
    }

    function sanitizeCurrencyValue(value) {
        let normalized = String(value || '').replace(/php/gi, '').replace(/[₱,\s]/g, '');
        normalized = normalized.replace(/[^0-9.]/g, '');

        const firstDotIndex = normalized.indexOf('.');
        if (firstDotIndex !== -1) {
            normalized = normalized.slice(0, firstDotIndex + 1) + normalized.slice(firstDotIndex + 1).replace(/\./g, '');
        }

        const parts = normalized.split('.');
        const wholePart = (parts[0] || '').replace(/^0+(?=\d)/, '');
        const decimalPart = parts.length > 1 ? parts[1].slice(0, 2) : '';

        return {
            wholePart: wholePart === '' ? '0' : wholePart,
            hasDecimal: firstDotIndex !== -1,
            decimalPart: decimalPart,
            isEmpty: normalized === '',
        };
    }

    function formatCurrencyValue(value, forceTwoDecimals) {
        const sanitized = sanitizeCurrencyValue(value);

        if (sanitized.isEmpty) {
            return '';
        }

        const withCommas = sanitized.wholePart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        if (forceTwoDecimals) {
            return withCommas + '.' + sanitized.decimalPart.padEnd(2, '0');
        }

        if (sanitized.hasDecimal) {
            return withCommas + '.' + sanitized.decimalPart;
        }

        return withCommas;
    }

    currencyInputs.forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = formatCurrencyValue(input.value, false);
        });

        input.addEventListener('blur', function () {
            input.value = formatCurrencyValue(input.value, true);
        });

        input.value = formatCurrencyValue(input.value, false);
    });
}

function initEngineerAssignmentPicker() {
    const picker = document.querySelector('[data-engineer-picker]');

    if (!picker || picker.dataset.toggleBound === 'true') {
        return;
    }

    const engineerSelect = picker.querySelector('[data-engineer-select]');
    const toggleButton = picker.querySelector('[data-engineer-toggle]');
    const toggleButtonIcon = picker.querySelector('.engineer-picker__toggle-icon');
    const toggleButtonText = picker.querySelector('.engineer-picker__toggle-text');
    const selectedContainer = picker.querySelector('[data-engineer-selected]');
    const inputsContainer = picker.querySelector('[data-engineer-inputs]');
    const createProjectForm = document.getElementById('create-project-form');

    if (!engineerSelect || !toggleButton || !selectedContainer || !inputsContainer || !createProjectForm) {
        return;
    }

    function getSelectedEngineerIds() {
        return Array.from(inputsContainer.querySelectorAll('[data-engineer-input]')).map(function (input) {
            return String(input.value);
        });
    }

    function syncValidation() {
        const hasSelectedEngineers = getSelectedEngineerIds().length > 0;
        if (hasSelectedEngineers) {
            engineerSelect.setCustomValidity('');
        }
    }

    function syncToggleButton() {
        const selectedValue = engineerSelect.value;
        const hasSelectedValue = selectedValue !== '';
        const isAlreadyAdded = hasSelectedValue && getSelectedEngineerIds().includes(selectedValue);

        toggleButton.disabled = !hasSelectedValue;
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
        const engineerName = engineerSelect.options[engineerSelect.selectedIndex]?.text || '';

        if (engineerId === '') {
            engineerSelect.setCustomValidity('Assigned engineer is required.');
            engineerSelect.reportValidity();
            return;
        }

        if (getSelectedEngineerIds().includes(engineerId)) {
            removeEngineer(engineerId);
        } else {
            addEngineer(engineerId, engineerName);
        }

        engineerSelect.setCustomValidity('');
        engineerSelect.focus();
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
        engineerSelect.focus();
    });

    createProjectForm.addEventListener('submit', function (event) {
        syncValidation();

        if (getSelectedEngineerIds().length === 0) {
            event.preventDefault();
            engineerSelect.setCustomValidity('Assigned engineer is required.');
            engineerSelect.reportValidity();
            engineerSelect.focus();
            return;
        }

        engineerSelect.setCustomValidity('');
    });

    renderEmptyState();
    syncValidation();
    syncToggleButton();
    picker.dataset.toggleBound = 'true';
}

document.addEventListener('DOMContentLoaded', initProjectSearchUI);
document.addEventListener('DOMContentLoaded', initCreateProjectForm);
document.addEventListener('DOMContentLoaded', initCreateProjectClientAutofill);
document.addEventListener('DOMContentLoaded', initProjectAdditionalInfoRows);
document.addEventListener('DOMContentLoaded', initCreateProjectDraft);
document.addEventListener('DOMContentLoaded', initCurrencyInputs);
document.addEventListener('DOMContentLoaded', initEngineerAssignmentPicker);
</script>
</body>
</html>
