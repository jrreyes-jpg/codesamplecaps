<?php

require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/quotation_access.php';
require_once __DIR__ . '/quotation_schema.php';

if (!function_exists('quotation_module_table_exists')) {
    function quotation_module_table_exists(mysqli $conn, string $tableName): bool
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
}

if (!function_exists('quotation_module_tables_ready')) {
    function quotation_module_tables_ready(mysqli $conn): bool
    {
        foreach (quotation_module_required_tables() as $tableName) {
            if (!quotation_module_table_exists($conn, $tableName)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('quotation_module_bootstrap_tables')) {
    function quotation_module_bootstrap_tables(mysqli $conn): array
    {
        if (quotation_module_tables_ready($conn)) {
            return [
                'ready' => true,
                'created' => false,
                'errors' => [],
            ];
        }

        $result = quotation_module_ensure_schema($conn);

        return [
            'ready' => quotation_module_tables_ready($conn),
            'created' => (bool)($result['success'] ?? false),
            'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
        ];
    }
}

if (!function_exists('quotation_module_set_flash')) {
    function quotation_module_set_flash(string $type, string $message): void
    {
        auth_start_session();
        $_SESSION['quotation_module_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('quotation_module_consume_flash')) {
    function quotation_module_consume_flash(): ?array
    {
        auth_start_session();
        $flash = $_SESSION['quotation_module_flash'] ?? null;
        unset($_SESSION['quotation_module_flash']);

        return is_array($flash) ? $flash : null;
    }
}

if (!function_exists('quotation_module_csrf_token')) {
    function quotation_module_csrf_token(): string
    {
        return auth_csrf_token('quotation_module');
    }
}

if (!function_exists('quotation_module_is_valid_csrf')) {
    function quotation_module_is_valid_csrf(?string $token): bool
    {
        return auth_is_valid_csrf($token, 'quotation_module');
    }
}

if (!function_exists('quotation_module_normalize_text')) {
    function quotation_module_normalize_text(?string $value): string
    {
        return trim((string)$value);
    }
}

if (!function_exists('quotation_module_normalize_nullable_text')) {
    function quotation_module_normalize_nullable_text(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}

if (!function_exists('quotation_module_redirect')) {
    function quotation_module_redirect(string $path): void
    {
        header('Location: ' . $path);
        exit();
    }
}

if (!function_exists('quotation_module_format_currency')) {
    function quotation_module_format_currency($amount): string
    {
        return 'PHP ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('quotation_module_format_datetime')) {
    function quotation_module_format_datetime(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 'N/A';
        }

        try {
            return (new DateTimeImmutable($value))->format('M j, Y g:i A');
        } catch (Throwable $throwable) {
            return $value;
        }
    }
}

if (!function_exists('quotation_module_status_label')) {
    function quotation_module_status_label(string $status): string
    {
        $labels = [
            'draft' => 'Draft',
            'under_review' => 'Under Review',
            'for_approval' => 'For Approval',
            'approved' => 'Approved',
            'sent' => 'Sent',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
        ];

        return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('quotation_module_status_class')) {
    function quotation_module_status_class(string $status): string
    {
        $map = [
            'draft' => 'is-draft',
            'under_review' => 'is-review',
            'for_approval' => 'is-approval',
            'approved' => 'is-approved',
            'sent' => 'is-sent',
            'accepted' => 'is-accepted',
            'rejected' => 'is-rejected',
        ];

        return $map[$status] ?? 'is-draft';
    }
}

if (!function_exists('quotation_module_fetch_engineer_projects')) {
    function quotation_module_fetch_engineer_projects(mysqli $conn, int $engineerId): array
    {
        $stmt = $conn->prepare(
            "SELECT
                p.id,
                p.project_name,
                p.status,
                p.project_start_date,
                p.estimated_completion_date,
                client.full_name AS client_name,
                CASE
                    WHEN p.project_start_date IS NOT NULL
                    AND p.estimated_completion_date IS NOT NULL
                    AND p.estimated_completion_date >= p.project_start_date
                    THEN DATEDIFF(p.estimated_completion_date, p.project_start_date) + 1
                    ELSE NULL
                END AS project_duration_days
             FROM projects p
             INNER JOIN project_assignments pa ON pa.project_id = p.id
             INNER JOIN users client ON client.id = p.client_id
             WHERE pa.engineer_id = ?
             AND p.status <> 'draft'
             ORDER BY p.project_name ASC"
        );
        $stmt->bind_param('i', $engineerId);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('quotation_module_fetch_foremen')) {
    function quotation_module_fetch_foremen(mysqli $conn): array
    {
        $result = $conn->query(
            "SELECT id, full_name
             FROM users
             WHERE role = 'foreman' AND status = 'active'
             ORDER BY full_name ASC"
        );

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('quotation_module_fetch_inventory_options')) {
    function quotation_module_fetch_inventory_options(mysqli $conn): array
    {
        $result = $conn->query(
            "SELECT
                i.id,
                i.quantity,
                i.status,
                a.id AS asset_id,
                a.asset_name,
                a.asset_category,
                a.asset_type
             FROM inventory i
             INNER JOIN assets a ON a.id = i.asset_id
             ORDER BY a.asset_name ASC"
        );

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('quotation_module_fetch_asset_options')) {
    function quotation_module_fetch_asset_options(mysqli $conn): array
    {
        $result = $conn->query(
            "SELECT id, asset_name, asset_category, asset_type, asset_status
             FROM assets
             ORDER BY asset_name ASC"
        );

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('quotation_module_fetch_quotations')) {
    function quotation_module_fetch_quotations(mysqli $conn, string $role, int $userId): array
    {
        $whereSql = '1 = 1';
        $types = '';
        $params = [];

        if ($role === 'engineer') {
            $whereSql = 'q.engineer_id = ?';
            $types = 'i';
            $params[] = $userId;
        } elseif ($role === 'foreman') {
            $whereSql = 'q.foreman_reviewer_id = ?';
            $types = 'i';
            $params[] = $userId;
        } elseif ($role === 'client') {
            $whereSql = 'q.client_id = ?';
            $types = 'i';
            $params[] = $userId;
        }

        $sql = "SELECT
                    q.*,
                    p.project_name,
                    c.full_name AS client_name,
                    e.full_name AS engineer_name,
                    f.full_name AS foreman_name,
                    approver.full_name AS approver_name
                FROM quotations q
                INNER JOIN projects p ON p.id = q.project_id
                INNER JOIN users c ON c.id = q.client_id
                INNER JOIN users e ON e.id = q.engineer_id
                LEFT JOIN users f ON f.id = q.foreman_reviewer_id
                LEFT JOIN users approver ON approver.id = q.approved_by
                WHERE {$whereSql}
                ORDER BY q.updated_at DESC, q.id DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('quotation_module_fetch_quotation')) {
    function quotation_module_fetch_quotation(mysqli $conn, int $quotationId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT
                q.*,
                p.project_name,
                p.project_code,
                p.project_address,
                p.project_start_date,
                p.estimated_completion_date,
                CASE
                    WHEN p.project_start_date IS NOT NULL
                    AND p.estimated_completion_date IS NOT NULL
                    AND p.estimated_completion_date >= p.project_start_date
                    THEN DATEDIFF(p.estimated_completion_date, p.project_start_date) + 1
                    ELSE NULL
                END AS project_duration_days,
                c.full_name AS client_name,
                c.email AS client_email,
                e.full_name AS engineer_name,
                f.full_name AS foreman_name,
                approver.full_name AS approver_name
             FROM quotations q
             INNER JOIN projects p ON p.id = q.project_id
             INNER JOIN users c ON c.id = q.client_id
             INNER JOIN users e ON e.id = q.engineer_id
             LEFT JOIN users f ON f.id = q.foreman_reviewer_id
             LEFT JOIN users approver ON approver.id = q.approved_by
             WHERE q.id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $quotationId);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? ($result->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('quotation_module_fetch_quotation_items')) {
    function quotation_module_fetch_quotation_items(mysqli $conn, int $quotationId): array
    {
        $stmt = $conn->prepare(
            "SELECT *
             FROM quotation_items
             WHERE quotation_id = ?
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->bind_param('i', $quotationId);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('quotation_module_fetch_reviews')) {
    function quotation_module_fetch_reviews(mysqli $conn, int $quotationId): array
    {
        $stmt = $conn->prepare(
            "SELECT r.*, u.full_name
             FROM quotation_reviews r
             INNER JOIN users u ON u.id = r.reviewer_id
             WHERE r.quotation_id = ?
             ORDER BY r.created_at ASC, r.id ASC"
        );
        $stmt->bind_param('i', $quotationId);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('quotation_module_fetch_history')) {
    function quotation_module_fetch_history(mysqli $conn, int $quotationId): array
    {
        $stmt = $conn->prepare(
            "SELECT h.*, u.full_name
             FROM quotation_status_history h
             INNER JOIN users u ON u.id = h.acted_by
             WHERE h.quotation_id = ?
             ORDER BY h.created_at ASC, h.id ASC"
        );
        $stmt->bind_param('i', $quotationId);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('quotation_module_user_can_access')) {
    function quotation_module_user_can_access(array $quotation, string $role, int $userId): bool
    {
        if ($role === 'super_admin') {
            return true;
        }

        if ($role === 'engineer') {
            return (int)$quotation['engineer_id'] === $userId;
        }

        if ($role === 'foreman') {
            return (int)($quotation['foreman_reviewer_id'] ?? 0) === $userId;
        }

        if ($role === 'client') {
            return (int)$quotation['client_id'] === $userId;
        }

        return false;
    }
}

if (!function_exists('quotation_module_parse_items_from_post')) {
    function quotation_module_parse_items_from_post(array $post): array
    {
        $types = $post['item_type'] ?? [];
        $sourceTables = $post['source_table'] ?? [];
        $sourceIds = $post['source_id'] ?? [];
        $itemNames = $post['item_name'] ?? [];
        $descriptions = $post['item_description'] ?? [];
        $units = $post['unit'] ?? [];
        $quantities = $post['quantity'] ?? [];
        $rates = $post['rate'] ?? [];
        $hours = $post['hours'] ?? [];
        $days = $post['days'] ?? [];

        $items = [];
        $rowCount = is_array($itemNames) ? count($itemNames) : 0;

        for ($index = 0; $index < $rowCount; $index++) {
            $itemName = trim((string)($itemNames[$index] ?? ''));
            if ($itemName === '') {
                continue;
            }

            $items[] = [
                'item_type' => trim((string)($types[$index] ?? 'other')),
                'source_table' => quotation_module_normalize_nullable_text($sourceTables[$index] ?? null),
                'source_id' => quotation_module_normalize_nullable_text($sourceIds[$index] ?? null),
                'item_name' => $itemName,
                'description' => trim((string)($descriptions[$index] ?? '')),
                'unit' => trim((string)($units[$index] ?? 'unit')),
                'quantity' => (float)($quantities[$index] ?? 0),
                'rate' => (float)($rates[$index] ?? 0),
                'hours' => (float)($hours[$index] ?? 0),
                'days' => (float)($days[$index] ?? 0),
            ];
        }

        return $items;
    }
}

if (!function_exists('quotation_module_build_form_payload')) {
    function quotation_module_build_form_payload(mysqli $conn, int $engineerId, array $post): ?array
    {
        $projectId = (int)($post['project_id'] ?? 0);
        $title = quotation_module_normalize_text($post['title'] ?? '');

        if ($projectId <= 0 || $title === '') {
            return null;
        }

        $projectStmt = $conn->prepare(
            "SELECT
                p.id,
                p.client_id,
                CASE
                    WHEN p.project_start_date IS NOT NULL
                    AND p.estimated_completion_date IS NOT NULL
                    AND p.estimated_completion_date >= p.project_start_date
                    THEN DATEDIFF(p.estimated_completion_date, p.project_start_date) + 1
                    ELSE NULL
                END AS project_duration_days
             FROM projects p
             INNER JOIN project_assignments pa ON pa.project_id = p.id
             WHERE p.id = ?
             AND pa.engineer_id = ?
             LIMIT 1"
        );
        $projectStmt->bind_param('ii', $projectId, $engineerId);
        $projectStmt->execute();
        $projectResult = $projectStmt->get_result();
        $project = $projectResult ? $projectResult->fetch_assoc() : null;

        if (!$project) {
            return null;
        }

        return [
            'project_id' => $projectId,
            'client_id' => (int)$project['client_id'],
            'engineer_id' => $engineerId,
            'foreman_reviewer_id' => ($post['foreman_reviewer_id'] ?? '') !== '' ? (int)$post['foreman_reviewer_id'] : null,
            'title' => $title,
            'scope_summary' => quotation_module_normalize_nullable_text($post['scope_summary'] ?? null),
            'currency_code' => 'PHP',
            'estimated_duration_days' => isset($project['project_duration_days']) ? (int)$project['project_duration_days'] : null,
            'profit_margin_percent' => 0,
        ];
    }
}
