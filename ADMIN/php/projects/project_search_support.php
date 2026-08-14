<?php

if (!function_exists('project_search_table_has_column')) {
    function project_search_table_has_column(mysqli $conn, string $tableName, string $columnName): bool {
        $stmt = $conn->prepare(
            'SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = ?
             LIMIT 1'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $result = $stmt->get_result();

        return (bool)($result && $result->fetch_assoc());
    }
}

if (!function_exists('project_search_index_exists')) {
    function project_search_index_exists(mysqli $conn, string $tableName, string $indexName): bool {
        $stmt = $conn->prepare(
            'SELECT 1
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND INDEX_NAME = ?
             LIMIT 1'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $tableName, $indexName);
        $stmt->execute();
        $result = $stmt->get_result();

        return (bool)($result && $result->fetch_assoc());
    }
}

if (!function_exists('ensure_project_search_indexes')) {
    function ensure_project_search_indexes(mysqli $conn, bool $hasProjectAddressColumn, bool $hasProjectSiteColumn): void {
        if (!project_search_index_exists($conn, 'projects', 'idx_projects_search_name_status_created')) {
            $conn->query(
                'ALTER TABLE projects
                 ADD INDEX idx_projects_search_name_status_created (project_name, status, created_at, id)'
            );
        }

        if ($hasProjectAddressColumn && !project_search_index_exists($conn, 'projects', 'idx_projects_search_address')) {
            $conn->query(
                'ALTER TABLE projects
                 ADD INDEX idx_projects_search_address (project_address(120))'
            );
        }

        if ($hasProjectSiteColumn && !project_search_index_exists($conn, 'projects', 'idx_projects_search_site')) {
            $conn->query(
                'ALTER TABLE projects
                 ADD INDEX idx_projects_search_site (project_site)'
            );
        }

        if (!project_search_index_exists($conn, 'users', 'idx_users_search_role_status_name')) {
            $conn->query(
                'ALTER TABLE users
                 ADD INDEX idx_users_search_role_status_name (role, status, full_name)'
            );
        }

        if (!project_search_index_exists($conn, 'project_assignments', 'idx_project_assignments_project_latest')) {
            $conn->query(
                'ALTER TABLE project_assignments
                 ADD INDEX idx_project_assignments_project_latest (project_id, id, engineer_id)'
            );
        }
    }
}

if (!function_exists('project_search_bind_params')) {
    function project_search_bind_params(mysqli_stmt $stmt, string $types, array $params): bool {
        if ($types === '' || empty($params)) {
            return true;
        }

        $references = [];
        foreach ($params as $index => $value) {
            $references[$index] = &$params[$index];
        }

        array_unshift($references, $types);

        return call_user_func_array([$stmt, 'bind_param'], $references);
    }
}

if (!function_exists('project_search_assignment_summary_sql')) {
    function project_search_assignment_summary_sql(): string {
        return "
            LEFT JOIN (
                SELECT
                    pa.project_id,
                    GROUP_CONCAT(DISTINCT pa.engineer_id ORDER BY pa.engineer_id SEPARATOR ',') AS engineer_ids_csv,
                    GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS engineer_names
                FROM project_assignments pa
                INNER JOIN users u ON u.id = pa.engineer_id
                GROUP BY pa.project_id
            ) assignment_summary ON assignment_summary.project_id = p.id
        ";
    }
}

if (!function_exists('project_search_build_filter')) {
    function project_search_build_filter(bool $hasProjectAddressColumn, bool $hasProjectEmailColumn, bool $hasProjectCodeColumn, bool $hasPoNumberColumn, bool $hasProjectSiteColumn, bool $hasContactPersonColumn, bool $hasContactNumberColumn, string $searchQuery, string $statusFilter, string $trashFilterSql = 'p.deleted_at IS NULL'): array {
        $conditions = [];
        $types = '';
        $params = [];

        if ($trashFilterSql !== '') {
            $conditions[] = $trashFilterSql;
        }

        if ($searchQuery !== '') {
            $likeValue = $searchQuery . '%';
            $searchConditions = [
                'p.project_name LIKE ?',
                'client.full_name LIKE ?',
                'assignment_summary.engineer_names LIKE ?',
                'p.status LIKE ?',
            ];
            $searchParams = [$likeValue, $likeValue, $likeValue, $likeValue];

            if ($hasProjectAddressColumn) {
                $searchConditions[] = 'p.project_address LIKE ?';
                $searchParams[] = $likeValue;
            }

            if ($hasProjectSiteColumn) {
                $searchConditions[] = 'p.project_site LIKE ?';
                $searchParams[] = $likeValue;
            }

            if ($hasProjectEmailColumn) {
                $searchConditions[] = 'p.project_email LIKE ?';
                $searchParams[] = $likeValue;
            }

            if ($hasContactPersonColumn) {
                $searchConditions[] = 'p.contact_person LIKE ?';
                $searchParams[] = $likeValue;
            }

            if ($hasContactNumberColumn) {
                $searchConditions[] = 'p.contact_number LIKE ?';
                $searchParams[] = $likeValue;
            }

            if ($hasProjectCodeColumn) {
                $searchConditions[] = 'p.project_code LIKE ?';
                $searchParams[] = $likeValue;
            }

            if ($hasPoNumberColumn) {
                $searchConditions[] = 'p.po_number LIKE ?';
                $searchParams[] = $likeValue;
            }

            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            $types .= str_repeat('s', count($searchParams));
            array_push($params, ...$searchParams);
        }

        if ($statusFilter !== '') {
            $conditions[] = 'p.status = ?';
            $types .= 's';
            $params[] = $statusFilter;
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$whereSql, $types, $params];
    }
}

if (!function_exists('project_search_fetch_count')) {
    function project_search_fetch_count(mysqli $conn, bool $hasProjectAddressColumn, bool $hasProjectEmailColumn, bool $hasProjectCodeColumn, bool $hasPoNumberColumn, bool $hasProjectSiteColumn, bool $hasContactPersonColumn, bool $hasContactNumberColumn, string $searchQuery, string $statusFilter, string $trashFilterSql = 'p.deleted_at IS NULL'): int {
        [$whereSql, $types, $params] = project_search_build_filter($hasProjectAddressColumn, $hasProjectEmailColumn, $hasProjectCodeColumn, $hasPoNumberColumn, $hasProjectSiteColumn, $hasContactPersonColumn, $hasContactNumberColumn, $searchQuery, $statusFilter, $trashFilterSql);

        $sql = "
            SELECT COUNT(*) AS total
            FROM projects p
            LEFT JOIN users client ON client.id = p.client_id
            " . project_search_assignment_summary_sql() . "
            {$whereSql}
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt || !project_search_bind_params($stmt, $types, $params) || !$stmt->execute()) {
            return 0;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;

        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('project_search_fetch_page')) {
    function project_search_fetch_page(
        mysqli $conn,
        bool $hasProjectAddressColumn,
        bool $hasProjectEmailColumn,
        bool $hasProjectCodeColumn,
        bool $hasPoNumberColumn,
        bool $hasProjectSiteColumn,
        bool $hasContactPersonColumn,
        bool $hasContactNumberColumn,
        string $searchQuery,
        string $statusFilter,
        int $limit,
        int $offset,
        string $trashFilterSql = 'p.deleted_at IS NULL'
    ): array {
        [$whereSql, $types, $params] = project_search_build_filter($hasProjectAddressColumn, $hasProjectEmailColumn, $hasProjectCodeColumn, $hasPoNumberColumn, $hasProjectSiteColumn, $hasContactPersonColumn, $hasContactNumberColumn, $searchQuery, $statusFilter, $trashFilterSql);
        $projectSiteSelect = $hasProjectSiteColumn ? 'p.project_site,' : 'NULL AS project_site,';
        $projectAddressSelect = $hasProjectAddressColumn ? 'p.project_address,' : 'NULL AS project_address,';
        $projectEmailSelect = $hasProjectEmailColumn ? 'p.project_email,' : 'NULL AS project_email,';
        $projectAdditionalInfoSelect = project_search_table_has_column($conn, 'projects', 'additional_info_json') ? 'p.additional_info_json,' : 'NULL AS additional_info_json,';
        $contactPersonSelect = $hasContactPersonColumn ? 'p.contact_person,' : 'NULL AS contact_person,';
        $contactNumberSelect = $hasContactNumberColumn ? 'p.contact_number,' : 'NULL AS contact_number,';
        $projectCodeSelect = $hasProjectCodeColumn ? 'p.project_code,' : 'NULL AS project_code,';
        $poNumberSelect = $hasPoNumberColumn ? 'p.po_number,' : 'NULL AS po_number,';

        $sql = "
            SELECT
                p.id,
                p.project_name,
                p.description,
                {$projectSiteSelect}
                {$projectAddressSelect}
                {$projectEmailSelect}
                {$projectAdditionalInfoSelect}
                {$contactPersonSelect}
                {$contactNumberSelect}
                {$projectCodeSelect}
                {$poNumberSelect}
                p.client_id,
                p.start_date,
                p.end_date,
                p.status,
                p.deleted_at,
                p.delete_scheduled_at,
                p.created_at,
                client.full_name AS client_name,
                client.email AS client_email,
                assignment_summary.engineer_ids_csv,
                assignment_summary.engineer_names,
                COALESCE(budget_profiles.budget_amount, 0) AS budget_amount,
                budget_profiles.budget_notes,
                COALESCE(cost_totals.total_cost, 0) AS total_cost,
                COALESCE(cost_totals.cost_entry_count, 0) AS cost_entry_count,
                cost_totals.last_cost_date,
                COALESCE(task_totals.total_tasks, 0) AS total_tasks,
                COALESCE(task_totals.completed_tasks, 0) AS completed_tasks
            FROM projects p
            LEFT JOIN users client ON client.id = p.client_id
            " . project_search_assignment_summary_sql() . "
            LEFT JOIN project_budget_profiles budget_profiles ON budget_profiles.project_id = p.id
            LEFT JOIN (
                SELECT
                    project_id,
                    SUM(amount) AS total_cost,
                    COUNT(*) AS cost_entry_count,
                    MAX(cost_date) AS last_cost_date
                FROM project_cost_entries
                GROUP BY project_id
            ) cost_totals ON cost_totals.project_id = p.id
            LEFT JOIN (
                SELECT
                    project_id,
                    COUNT(*) AS total_tasks,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks
                FROM tasks
                GROUP BY project_id
            ) task_totals ON task_totals.project_id = p.id
            {$whereSql}
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $typesWithPaging = $types . 'ii';
        $paramsWithPaging = array_merge($params, [$limit, $offset]);

        if (!project_search_bind_params($stmt, $typesWithPaging, $paramsWithPaging) || !$stmt->execute()) {
            return [];
        }

        $result = $stmt->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('project_search_fetch_suggestions')) {
    function project_search_fetch_suggestions(
        mysqli $conn,
        bool $hasProjectAddressColumn,
        bool $hasProjectEmailColumn,
        bool $hasProjectCodeColumn,
        bool $hasPoNumberColumn,
        bool $hasProjectSiteColumn,
        bool $hasContactPersonColumn,
        bool $hasContactNumberColumn,
        string $searchQuery,
        string $statusFilter,
        int $limit = 8,
        string $trashFilterSql = 'p.deleted_at IS NULL'
    ): array {
        [$whereSql, $types, $params] = project_search_build_filter($hasProjectAddressColumn, $hasProjectEmailColumn, $hasProjectCodeColumn, $hasPoNumberColumn, $hasProjectSiteColumn, $hasContactPersonColumn, $hasContactNumberColumn, $searchQuery, $statusFilter, $trashFilterSql);
        $projectSiteSelect = $hasProjectSiteColumn ? 'p.project_site,' : 'NULL AS project_site,';
        $projectAddressSelect = $hasProjectAddressColumn ? 'p.project_address,' : 'NULL AS project_address,';
        $projectEmailSelect = $hasProjectEmailColumn ? 'p.project_email,' : 'NULL AS project_email,';
        $contactPersonSelect = $hasContactPersonColumn ? 'p.contact_person,' : 'NULL AS contact_person,';
        $contactNumberSelect = $hasContactNumberColumn ? 'p.contact_number,' : 'NULL AS contact_number,';
        $projectCodeSelect = $hasProjectCodeColumn ? 'p.project_code,' : 'NULL AS project_code,';
        $poNumberSelect = $hasPoNumberColumn ? 'p.po_number,' : 'NULL AS po_number,';
        $relevanceLike = $searchQuery . '%';

        $sql = "
            SELECT
                p.id,
                p.project_name,
                p.status,
                {$projectSiteSelect}
                {$projectAddressSelect}
                {$projectEmailSelect}
                {$contactPersonSelect}
                {$contactNumberSelect}
                {$projectCodeSelect}
                {$poNumberSelect}
                client.full_name AS client_name,
                assignment_summary.engineer_names
            FROM projects p
            LEFT JOIN users client ON client.id = p.client_id
            " . project_search_assignment_summary_sql() . "
            {$whereSql}
            ORDER BY
                CASE
                    WHEN p.project_name LIKE ? THEN 0
                    WHEN client.full_name LIKE ? THEN 1
                    WHEN assignment_summary.engineer_names LIKE ? THEN 2
                    " . ($hasProjectSiteColumn ? "WHEN p.project_site LIKE ? THEN 3" : '') . "
                    " . ($hasProjectAddressColumn ? "WHEN p.project_address LIKE ? THEN 4" : '') . "
                    " . ($hasProjectEmailColumn ? "WHEN p.project_email LIKE ? THEN 5" : '') . "
                    " . ($hasContactPersonColumn ? "WHEN p.contact_person LIKE ? THEN 6" : '') . "
                    " . ($hasContactNumberColumn ? "WHEN p.contact_number LIKE ? THEN 7" : '') . "
                    " . ($hasProjectCodeColumn ? "WHEN p.project_code LIKE ? THEN 8" : '') . "
                    " . ($hasPoNumberColumn ? "WHEN p.po_number LIKE ? THEN 9" : '') . "
                    ELSE 9
                END,
                p.created_at DESC,
                p.id DESC
            LIMIT ?
        ";

        $relevanceParams = [$relevanceLike, $relevanceLike, $relevanceLike];
        if ($hasProjectSiteColumn) {
            $relevanceParams[] = $relevanceLike;
        }
        if ($hasProjectAddressColumn) {
            $relevanceParams[] = $relevanceLike;
        }
        if ($hasProjectEmailColumn) {
            $relevanceParams[] = $relevanceLike;
        }
        if ($hasContactPersonColumn) {
            $relevanceParams[] = $relevanceLike;
        }
        if ($hasContactNumberColumn) {
            $relevanceParams[] = $relevanceLike;
        }
        if ($hasProjectCodeColumn) {
            $relevanceParams[] = $relevanceLike;
        }
        if ($hasPoNumberColumn) {
            $relevanceParams[] = $relevanceLike;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $typesWithOrdering = $types . str_repeat('s', count($relevanceParams)) . 'i';
        $paramsWithOrdering = array_merge($params, $relevanceParams, [$limit]);

        if (!project_search_bind_params($stmt, $typesWithOrdering, $paramsWithOrdering) || !$stmt->execute()) {
            return [];
        }

        $result = $stmt->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
