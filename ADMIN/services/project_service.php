<?php

/*
|--------------------------------------------------------------------------
| PROJECT_SERVICE.PHP
|--------------------------------------------------------------------------
| Shared project helpers.
| Sakop nito ang reusable project/schema helpers na ginagamit ng projects page.
| Dito nilalagay ang common logic para hindi lumobo ang projects.php.
|--------------------------------------------------------------------------
*/

if (!function_exists('get_column_type')) {
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
}

if (!function_exists('table_has_column')) {
    function table_has_column(mysqli $conn, string $tableName, string $columnName): bool {
        return get_column_type($conn, $tableName, $columnName) !== null;
    }
}

if (!function_exists('table_exists')) {
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
}

if (!function_exists('enum_supports_value')) {
    function enum_supports_value(mysqli $conn, string $tableName, string $columnName, string $value): bool {
        $columnType = get_column_type($conn, $tableName, $columnName);

        return $columnType !== null && str_contains($columnType, "'" . $value . "'");
    }
}

if (!function_exists('normalize_text_or_null')) {
    function normalize_text_or_null(?string $value): ?string {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}

if (!function_exists('today_date')) {
    function today_date(): string {
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }
}

if (!function_exists('project_service_has_real_text')) {
    function project_service_has_real_text(?string $value): bool {
        return preg_match('/[\p{L}]{2,}/u', (string)$value) === 1;
    }
}

if (!function_exists('project_service_is_person_name')) {
    function project_service_is_person_name(?string $value): bool {
        $value = trim((string)$value);
        return $value !== '' &&
            preg_match('/^[\p{L} .\'-]+$/u', $value) === 1 &&
            project_service_has_real_text($value);
    }
}

if (!function_exists('project_service_create_project')) {
    function project_service_create_project(mysqli $conn, array $data): int {
        $conn->begin_transaction();

        try {
            $hasProjectAddressColumn = (bool)($data['has_project_address_column'] ?? false);
            $hasProjectEmailColumn = (bool)($data['has_project_email_column'] ?? false);
            $hasProjectCodeColumn = (bool)($data['has_project_code_column'] ?? false);
            $hasPoNumberColumn = (bool)($data['has_po_number_column'] ?? false);
            $hasProjectAdditionalInfoColumn = (bool)($data['has_project_additional_info_column'] ?? false);

            $projectName = $data['project_name'];
            $description = $data['description'];
            $clientId = $data['client_id'];
            $contactPerson = $data['contact_person'];
            $contactNumber = $data['contact_number'];
            $projectSite = $data['project_site'];
            $projectAddress = $data['project_address'];
            $projectEmail = $data['project_email'];
            $projectCode = $data['project_code'];
            $poNumber = $data['po_number'];
            $startDate = $data['start_date'];
            $projectStartDate = $data['project_start_date'];
            $estimatedCompletionDate = $data['estimated_completion_date'];
            $endDate = $data['end_date'];
            $status = $data['status'];
            $createdBy = $data['created_by'];
            $engineerIds = $data['engineer_ids'];
            $budgetAmount = $data['budget_amount'];
            $budgetNotes = $data['budget_notes'];
            $additionalInfoJson = $data['additional_info_json'];

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
                        $createProject->bind_param('ssissssssssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $projectEmail, $projectCode, $poNumber, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $status, $createdBy);
                    } else {
                        $createProject->bind_param('ssissssssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $projectEmail, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $status, $createdBy);
                    }
                } else {
                    $createProject->bind_param('ssisssssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $status, $createdBy);
                }
            } else {
                $createProject->bind_param('ssisssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $status, $createdBy);
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

            return $projectId;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('project_service_update_project_details')) {
    function project_service_update_project_details(mysqli $conn, array $data): void {
        $conn->begin_transaction();

        try {
            $hasProjectAddressColumn = (bool)($data['has_project_address_column'] ?? false);
            $hasProjectEmailColumn = (bool)($data['has_project_email_column'] ?? false);
            $hasProjectCodeColumn = (bool)($data['has_project_code_column'] ?? false);
            $hasPoNumberColumn = (bool)($data['has_po_number_column'] ?? false);
            $hasProjectAdditionalInfoColumn = (bool)($data['has_project_additional_info_column'] ?? false);

            $projectId = (int)$data['project_id'];
            $projectName = $data['project_name'];
            $description = $data['description'];
            $clientId = (int)$data['client_id'];
            $contactPerson = $data['contact_person'];
            $contactNumber = $data['contact_number'];
            $projectSite = $data['project_site'];
            $projectAddress = $data['project_address'];
            $projectEmail = $data['project_email'];
            $projectCode = $data['project_code'];
            $poNumber = $data['po_number'];
            $startDate = $data['start_date'];
            $projectStartDate = $data['project_start_date'];
            $estimatedCompletionDate = $data['estimated_completion_date'];
            $endDate = $data['end_date'];
            $engineerIds = $data['engineer_ids'];
            $updatedBy = (int)$data['updated_by'];
            $additionalInfoJson = $data['additional_info_json'];

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
                        $saved = $updateProject->bind_param('ssisssssssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $projectEmail, $projectCode, $poNumber, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $projectId)
                            && $updateProject->execute();
                    } else {
                        $saved = $updateProject->bind_param('ssisssssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $projectEmail, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $projectId)
                            && $updateProject->execute();
                    }
                } else {
                    $saved = $updateProject->bind_param('ssissssssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $projectSite, $projectAddress, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $projectId)
                        && $updateProject->execute();
                }
            } else {
                $saved = $updateProject->bind_param('ssissssssi', $projectName, $description, $clientId, $contactPerson, $contactNumber, $startDate, $projectStartDate, $estimatedCompletionDate, $endDate, $projectId)
                    && $updateProject->execute();
            }

            if (!$saved) {
                throw new RuntimeException('Failed to update project details.');
            }

            $clearAssignments = $conn->prepare('DELETE FROM project_assignments WHERE project_id = ?');
            if (
                !$clearAssignments ||
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
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('project_service_move_project_to_trash')) {
    function project_service_move_project_to_trash(mysqli $conn, int $projectId, int $deletedBy): bool {
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

        return $deleteProject &&
            $deleteProject->bind_param('ii', $deletedBy, $projectId) &&
            $deleteProject->execute() &&
            $deleteProject->affected_rows > 0;
    }
}

if (!function_exists('project_service_restore_project')) {
    function project_service_restore_project(mysqli $conn, int $projectId, int $restoredBy): bool {
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

        return $restoreProject &&
            $restoreProject->bind_param('ii', $restoredBy, $projectId) &&
            $restoreProject->execute() &&
            $restoreProject->affected_rows > 0;
    }
}

if (!function_exists('project_service_permanently_delete_project')) {
    function project_service_permanently_delete_project(mysqli $conn, int $projectId): bool {
        $deleteForever = $conn->prepare(
            'DELETE FROM projects
             WHERE id = ?
             AND deleted_at IS NOT NULL'
        );

        return $deleteForever &&
            $deleteForever->bind_param('i', $projectId) &&
            $deleteForever->execute() &&
            $deleteForever->affected_rows > 0;
    }
}

if (!function_exists('project_service_save_project_budget')) {
    function project_service_save_project_budget(mysqli $conn, int $projectId, float $budgetAmount, ?string $budgetNotes, int $updatedBy): bool {
        $saveBudget = $conn->prepare(
            'INSERT INTO project_budget_profiles (project_id, budget_amount, budget_notes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                budget_amount = VALUES(budget_amount),
                budget_notes = VALUES(budget_notes),
                updated_by = VALUES(updated_by)'
        );

        return $saveBudget &&
            $saveBudget->bind_param('idsii', $projectId, $budgetAmount, $budgetNotes, $updatedBy, $updatedBy) &&
            $saveBudget->execute();
    }
}

if (!function_exists('project_service_add_cost_entry')) {
    function project_service_add_cost_entry(mysqli $conn, int $projectId, string $costDate, string $costCategory, ?string $costDescription, float $costAmount, int $createdBy): bool {
        $insertCostEntry = $conn->prepare(
            'INSERT INTO project_cost_entries (project_id, cost_date, cost_category, description, amount, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        return $insertCostEntry &&
            $insertCostEntry->bind_param('isssdi', $projectId, $costDate, $costCategory, $costDescription, $costAmount, $createdBy) &&
            $insertCostEntry->execute();
    }
}

if (!function_exists('project_service_add_payment')) {
    function project_service_add_payment(mysqli $conn, int $projectId, string $paymentDate, float $paymentAmount, ?string $paymentNotes, int $createdBy): bool {
        $insertPayment = $conn->prepare(
            'INSERT INTO project_payments (project_id, payment_date, amount, notes, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );

        return $insertPayment &&
            $insertPayment->bind_param('isdsi', $projectId, $paymentDate, $paymentAmount, $paymentNotes, $createdBy) &&
            $insertPayment->execute();
    }
}

if (!function_exists('project_service_update_project_status')) {
    function project_service_update_project_status(mysqli $conn, int $projectId, string $status, ?string $completedAt): bool {
        $updateStatus = $conn->prepare('UPDATE projects SET status = ?, end_date = ? WHERE id = ?');

        return $updateStatus &&
            $updateStatus->bind_param('ssi', $status, $completedAt, $projectId) &&
            $updateStatus->execute();
    }
}

if (!function_exists('project_service_reopen_project')) {
    function project_service_reopen_project(mysqli $conn, int $projectId): bool {
        $reopenProject = $conn->prepare("UPDATE projects SET status = 'ongoing', end_date = NULL WHERE id = ?");

        return $reopenProject &&
            $reopenProject->bind_param('i', $projectId) &&
            $reopenProject->execute();
    }
}

if (!function_exists('project_service_add_task')) {
    function project_service_add_task(mysqli $conn, int $projectId, int $assignedTo, string $taskName, string $description, ?string $deadline, int $createdBy): ?int {
        $insertTask = $conn->prepare(
            'INSERT INTO tasks (project_id, assigned_to, task_name, description, deadline, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        if (
            !$insertTask ||
            !$insertTask->bind_param('iisssi', $projectId, $assignedTo, $taskName, $description, $deadline, $createdBy) ||
            !$insertTask->execute()
        ) {
            return null;
        }

        return (int)$insertTask->insert_id;
    }
}
