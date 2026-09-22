<?php
define('AUTH_REQUIRED_ROLE', 'engineer');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/site_inspections.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/material_stock.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
$error = '';

function engineer_inspection_csrf_token(): string
{
    return auth_csrf_token('engineer_site_inspections');
}

function engineer_inspection_valid_csrf(?string $token): bool
{
    return auth_is_valid_csrf($token, 'engineer_site_inspections');
}

function engineer_format_money(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function engineer_inspection_is_whole_count_unit(string $unit): bool
{
    return in_array($unit, ['unit', 'pc', 'pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'bundle', 'sheet', 'pair', 'tube', 'trip'], true);
}

function engineer_inspection_valid_quantity(string $value, string $unit): bool
{
    if (engineer_inspection_is_whole_count_unit($unit)) {
        return preg_match('/^[1-9]\d*$/', $value) === 1;
    }

    return preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) === 1 && (float)$value > 0;
}

function engineer_inspection_valid_unit_cost(string $value): bool
{
    return preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $value) === 1 && (float)$value > 0;
}

function engineer_inspection_fetch_active_material(mysqli $conn, int $materialId): ?array
{
    $stmt = $conn->prepare('SELECT material_name, unit FROM materials WHERE id = ? AND status = \'active\' LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $materialId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function engineer_inspection_asset_requirements_table_exists(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'site_inspection_asset_requirements'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function engineer_inspection_fetch_active_asset(mysqli $conn, int $assetId): ?array
{
    $stmt = $conn->prepare(
        "SELECT a.id, a.asset_name,
                CASE
                    WHEN COALESCE(unit_totals.total_units, 0) > 0 THEN COALESCE(unit_totals.available_units, 0)
                    ELSE COALESCE(i.quantity, 0)
                END AS available_quantity
         FROM assets a
         INNER JOIN inventory i ON i.asset_id = a.id
         LEFT JOIN (
            SELECT inventory_id,
                   COUNT(*) AS total_units,
                   SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_units
            FROM asset_units
            WHERE status <> 'archived'
            GROUP BY inventory_id
         ) unit_totals ON unit_totals.inventory_id = i.id
         WHERE a.id = ? AND a.deleted_at IS NULL
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function engineer_inspection_draft_is_unchanged(mysqli $conn, int $inspectionId, array $rows, array $assetRequirements, string $findings, string $riskNotes, string $clientRequests): bool
{
    $notesStmt = $conn->prepare('SELECT engineer_findings, risk_notes, client_requests FROM site_inspections WHERE id = ? LIMIT 1');
    $itemsStmt = $conn->prepare('SELECT item_type, inventory_id, material_id, item_name, quantity, unit, unit_cost, notes FROM site_inspection_cost_items WHERE inspection_id = ? ORDER BY id ASC');
    if (!$notesStmt || !$itemsStmt) {
        return false;
    }

    $notesStmt->bind_param('i', $inspectionId);
    $notesStmt->execute();
    $savedNotes = $notesStmt->get_result()->fetch_assoc() ?: [];
    if (trim((string)($savedNotes['engineer_findings'] ?? '')) !== $findings
        || trim((string)($savedNotes['risk_notes'] ?? '')) !== $riskNotes
        || trim((string)($savedNotes['client_requests'] ?? '')) !== $clientRequests) {
        return false;
    }

    $itemsStmt->bind_param('i', $inspectionId);
    $itemsStmt->execute();
    $savedRows = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($savedRows) !== count($rows)) {
        return false;
    }

    foreach ($rows as $index => $row) {
        $saved = $savedRows[$index];
        if ((string)$saved['item_type'] !== $row['item_type']
            || (int)($saved['inventory_id'] ?? 0) !== (int)($row['inventory_id'] ?? 0)
            || (int)($saved['material_id'] ?? 0) !== (int)($row['material_id'] ?? 0)
            || trim((string)$saved['item_name']) !== $row['item_name']
            || trim((string)$saved['unit']) !== $row['unit']
            || trim((string)($saved['notes'] ?? '')) !== $row['notes']
            || abs((float)$saved['quantity'] - (float)$row['quantity']) > 0.000001
            || abs((float)$saved['unit_cost'] - (float)$row['unit_cost']) > 0.000001) {
            return false;
        }
    }

    $requirementsStmt = $conn->prepare(
        'SELECT asset_id, quantity_required, notes
         FROM site_inspection_asset_requirements
         WHERE inspection_id = ?
         ORDER BY id ASC'
    );
    if (!$requirementsStmt) {
        return false;
    }

    $requirementsStmt->bind_param('i', $inspectionId);
    $requirementsStmt->execute();
    $savedRequirements = $requirementsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($savedRequirements) !== count($assetRequirements)) {
        return false;
    }

    foreach ($assetRequirements as $index => $requirement) {
        $savedRequirement = $savedRequirements[$index];
        if ((int)$savedRequirement['asset_id'] !== (int)$requirement['asset_id']
            || (int)$savedRequirement['quantity_required'] !== (int)$requirement['quantity_required']
            || trim((string)($savedRequirement['notes'] ?? '')) !== $requirement['notes']) {
            return false;
        }
    }

    return true;
}

function engineer_inspection_complete_site_address(array $inspection): string
{
    $parts = [];
    $seen = [];

    foreach (['site_address', 'barangay', 'city_municipality', 'province'] as $field) {
        $value = trim((string)($inspection[$field] ?? ''));
        $key = mb_strtolower($value, 'UTF-8');
        if ($value !== '' && !isset($seen[$key])) {
            $parts[] = $value;
            $seen[$key] = true;
        }
    }

    return implode(', ', $parts);
}

function engineer_owns_inspection(mysqli $conn, int $inspectionId, int $engineerId): bool
{
    $stmt = $conn->prepare('SELECT id FROM site_inspections WHERE id = ? AND engineer_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $inspectionId, $engineerId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function engineer_get_inspection_state(mysqli $conn, int $inspectionId, int $engineerId): array
{
    $stmt = $conn->prepare(
        'SELECT status, admin_review_status, admin_remarks, submitted_at
         FROM site_inspections
         WHERE id = ? AND engineer_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $inspectionId, $engineerId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inspectionId = (int)($_POST['inspection_id'] ?? 0);
    $costingAction = (string)($_POST['costing_action'] ?? 'save_draft');
    $workflowAction = (string)($_POST['workflow_action'] ?? '');
    $workflowTargets = [
        'acknowledge' => 'Acknowledged',
        'start' => 'Ongoing',
        'complete' => 'Completed',
    ];
    $inspectionState = $inspectionId > 0
        ? engineer_get_inspection_state($conn, $inspectionId, $userId)
        : [];
    $currentStatus = (string)($inspectionState['status'] ?? '');
    $currentReviewStatus = (string)($inspectionState['admin_review_status'] ?? 'Pending');

    if (!engineer_inspection_valid_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } elseif ($inspectionId <= 0 || !engineer_owns_inspection($conn, $inspectionId, $userId)) {
        $error = 'Inspection not found.';
    } elseif ($workflowAction !== '') {
        $targetStatus = $workflowTargets[$workflowAction] ?? '';
        if ($targetStatus === '' || !site_inspection_can_transition($currentStatus, $targetStatus)) {
            $error = 'Invalid inspection status change. Please refresh the page.';
        } elseif (site_inspection_transition($conn, $inspectionId, $userId, $currentStatus, $targetStatus)) {
            $message = match ($targetStatus) {
                'Acknowledged' => 'Assignment acknowledged.',
                'Ongoing' => 'Site inspection started.',
                'Completed' => 'Site inspection marked completed. You may now submit the final findings.',
                default => 'Inspection status updated.',
            };
        } else {
            $error = 'Inspection status was not changed. Please refresh the page.';
        }
    } elseif (!in_array($costingAction, ['save_draft', 'submit_to_admin'], true)) {
        $error = 'Invalid costing action.';
    } elseif ($currentStatus === 'Submitted') {
        $error = 'This inspection was already submitted to Admin.';
    } elseif (!in_array($currentStatus, ['Ongoing', 'Completed'], true)) {
        $error = 'Start the inspection before saving findings or costing.';
    } elseif ($costingAction === 'submit_to_admin' && $currentStatus !== 'Completed') {
        $error = 'Mark the inspection completed before submitting to Admin.';
    } else {
        $itemTypes = $_POST['item_type'] ?? [];
        $inventoryIds = $_POST['inventory_id'] ?? [];
        $materialIds = $_POST['material_id'] ?? [];
        $itemNames = $_POST['item_name'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $units = $_POST['unit'] ?? [];
        $unitCosts = $_POST['unit_cost'] ?? [];
        $notes = $_POST['notes'] ?? [];
        $assetRequirementIds = $_POST['asset_requirement_asset_id'] ?? [];
        $assetRequirementQuantities = $_POST['asset_requirement_quantity'] ?? [];
        $assetRequirementNotes = $_POST['asset_requirement_notes'] ?? [];
        $engineerFindings = trim((string)($_POST['engineer_findings'] ?? ''));
        $riskNotes = trim((string)($_POST['risk_notes'] ?? ''));
        $clientRequests = trim((string)($_POST['client_requests'] ?? ''));
        $rows = [];
        $hasMaterial = false;
        $hasLabor = false;
        $grandTotal = 0.0;
        $assetRequirements = [];
        $selectedAssetIds = [];

        foreach ($itemNames as $index => $rawName) {
            $itemName = trim((string)$rawName);
            $rawQuantityText = trim((string)($quantities[$index] ?? ''));
            $rawUnitCostText = trim((string)($unitCosts[$index] ?? ''));
            if (preg_match('/^\.\d+$/', $rawQuantityText)) {
                $rawQuantityText = '0' . $rawQuantityText;
            }
            if (preg_match('/^\.\d+$/', $rawUnitCostText)) {
                $rawUnitCostText = '0' . $rawUnitCostText;
            }
            $unit = trim((string)($units[$index] ?? 'unit'));
            $quantity = (float)($quantities[$index] ?? 0);
            $unitCost = (float)($unitCosts[$index] ?? 0);
            $itemType = in_array(($itemTypes[$index] ?? 'material'), ['material', 'labor', 'equipment', 'service', 'other'], true)
                ? (string)$itemTypes[$index]
                : 'material';
            $inventoryId = (int)($inventoryIds[$index] ?? 0);
            $materialId = (int)($materialIds[$index] ?? 0);

            if ($itemType !== 'material') {
                $materialId = 0;
                $inventoryId = 0;
            }

            if ($itemName === '' && $rawQuantityText === '' && $rawUnitCostText === '') {
                continue;
            }

            if (!in_array($unit, ['unit', 'pc', 'pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'bundle', 'sheet', 'pair', 'tube', 'meter', 'kg', 'liter', 'hour', 'day', 'trip'], true)) {
                $error = 'Please select a valid quantity unit.';
                break;
            }

            if (!engineer_inspection_valid_quantity($rawQuantityText, $unit)) {
                $error = engineer_inspection_is_whole_count_unit($unit)
                    ? 'Quantity must be a whole number greater than 0 for ' . $unit . '.'
                    : 'Quantity must be greater than 0.';
                break;
            }

            if (!engineer_inspection_valid_unit_cost($rawUnitCostText)) {
                $error = 'Unit cost must be greater than 0.';
                break;
            }

            if ($itemName === '') {
                $error = 'Please complete item name and quantity.';
                break;
            }

            if ($unit === '') {
                $error = 'Please select a quantity unit.';
                break;
            }

            if ($materialId > 0) {
                $linkedMaterial = engineer_inspection_fetch_active_material($conn, $materialId);
                if (!$linkedMaterial) {
                    $error = 'Selected material is no longer available. Please choose again.';
                    break;
                }

                $itemName = trim((string)$linkedMaterial['material_name']);
                $unit = trim((string)$linkedMaterial['unit']);
            }

            $lineTotal = $quantity * $unitCost;
            $grandTotal += $lineTotal;
            $hasMaterial = $hasMaterial || $itemType === 'material';
            $hasLabor = $hasLabor || $itemType === 'labor';

            $rows[] = [
                'item_type' => $itemType,
                'inventory_id' => $inventoryId > 0 ? $inventoryId : null,
                'material_id' => $materialId > 0 ? $materialId : null,
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
                'notes' => trim((string)($notes[$index] ?? '')),
            ];
        }

        foreach ($assetRequirementIds as $index => $rawAssetId) {
            $assetId = (int)$rawAssetId;
            $quantityText = trim((string)($assetRequirementQuantities[$index] ?? ''));
            $requirementNotes = trim((string)($assetRequirementNotes[$index] ?? ''));

            if ($assetId <= 0 && $quantityText === '' && $requirementNotes === '') {
                continue;
            }

            if ($assetId <= 0) {
                $error = 'Please select an asset requirement.';
                break;
            }

            if (isset($selectedAssetIds[$assetId])) {
                $error = 'Each asset can be added only once. Update its quantity instead.';
                break;
            }

            if (preg_match('/^[1-9]\d*$/', $quantityText) !== 1) {
                $error = 'Asset requirement quantity must be a whole number greater than 0.';
                break;
            }

            $asset = engineer_inspection_fetch_active_asset($conn, $assetId);
            if (!$asset) {
                $error = 'Selected asset is no longer available. Please choose again.';
                break;
            }

            $selectedAssetIds[$assetId] = true;
            $assetRequirements[] = [
                'asset_id' => $assetId,
                'quantity_required' => (int)$quantityText,
                'notes' => $requirementNotes,
            ];
        }

        if ($error === '' && empty($rows)) {
            $error = 'Please add at least one costing item.';
        }

        if ($error === '' && !engineer_inspection_asset_requirements_table_exists($conn)) {
            $error = 'Asset Requirements setup is not ready yet. Please apply the required database migration.';
        }

        if ($error === '' && $costingAction === 'submit_to_admin') {
            if (mb_strlen($engineerFindings, 'UTF-8') < 10) {
                $error = 'Please add engineer findings before submitting to Admin.';
            } elseif (!$hasMaterial) {
                $error = 'Please add at least one material item before submitting.';
            } elseif (!$hasLabor) {
                $error = 'Please add at least one labor item before submitting.';
            } elseif ($grandTotal <= 0) {
                $error = 'Total costing must be greater than 0 before submitting.';
            }
        }

        if ($error === '' && $costingAction === 'save_draft'
            && engineer_inspection_draft_is_unchanged($conn, $inspectionId, $rows, $assetRequirements, $engineerFindings, $riskNotes, $clientRequests)) {
            $message = 'No draft changes to save.';
        }

        if ($error === '' && $message === '') {
            $conn->begin_transaction();

            try {
                $deleteStmt = $conn->prepare('DELETE FROM site_inspection_cost_items WHERE inspection_id = ?');
                if (!$deleteStmt) {
                    throw new RuntimeException('Failed to prepare old costing cleanup.');
                }
                $deleteStmt->bind_param('i', $inspectionId);
                $deleteStmt->execute();

                $insertStmt = $conn->prepare(
                    'INSERT INTO site_inspection_cost_items
                     (inspection_id, item_type, inventory_id, material_id, item_name, quantity, unit, unit_cost, line_total, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$insertStmt) {
                    throw new RuntimeException('Failed to prepare costing save.');
                }

                foreach ($rows as $row) {
                    $itemType = $row['item_type'];
                    $inventoryId = $row['inventory_id'];
                    $materialId = $row['material_id'];
                    $itemName = $row['item_name'];
                    $quantity = $row['quantity'];
                    $unit = $row['unit'];
                    $unitCost = $row['unit_cost'];
                    $lineTotal = $row['line_total'];
                    $itemNotes = $row['notes'];
                    $insertStmt->bind_param(
                        'isiisdsdds',
                        $inspectionId,
                        $itemType,
                        $inventoryId,
                        $materialId,
                        $itemName,
                        $quantity,
                        $unit,
                        $unitCost,
                        $lineTotal,
                        $itemNotes
                    );
                    $insertStmt->execute();
                }

                $deleteAssetRequirementsStmt = $conn->prepare(
                    'DELETE FROM site_inspection_asset_requirements WHERE inspection_id = ?'
                );
                if (!$deleteAssetRequirementsStmt) {
                    throw new RuntimeException('Failed to prepare old asset requirements cleanup.');
                }
                $deleteAssetRequirementsStmt->bind_param('i', $inspectionId);
                $deleteAssetRequirementsStmt->execute();

                $insertAssetRequirementStmt = $conn->prepare(
                    'INSERT INTO site_inspection_asset_requirements
                     (inspection_id, asset_id, quantity_required, notes)
                     VALUES (?, ?, ?, ?)'
                );
                if (!$insertAssetRequirementStmt) {
                    throw new RuntimeException('Failed to prepare asset requirements save.');
                }

                foreach ($assetRequirements as $assetRequirement) {
                    $assetId = $assetRequirement['asset_id'];
                    $quantityRequired = $assetRequirement['quantity_required'];
                    $requirementNotes = $assetRequirement['notes'];
                    $insertAssetRequirementStmt->bind_param(
                        'iiis',
                        $inspectionId,
                        $assetId,
                        $quantityRequired,
                        $requirementNotes
                    );
                    $insertAssetRequirementStmt->execute();
                }

                $notesStmt = $conn->prepare(
                    'UPDATE site_inspections
                     SET engineer_findings = ?, risk_notes = ?, client_requests = ?
                     WHERE id = ? AND engineer_id = ?'
                );
                if (!$notesStmt) {
                    throw new RuntimeException('Failed to prepare engineer notes save.');
                }
                $notesStmt->bind_param('sssii', $engineerFindings, $riskNotes, $clientRequests, $inspectionId, $userId);
                $notesStmt->execute();

                if ($costingAction === 'submit_to_admin'
                    && !site_inspection_transition($conn, $inspectionId, $userId, 'Completed', 'Submitted')) {
                    throw new RuntimeException('Failed to submit the inspection status.');
                }

                if ($costingAction === 'submit_to_admin') {
                    $resetReview = $conn->prepare(
                        "UPDATE site_inspections
                         SET admin_review_status = 'Pending'
                         WHERE id = ? AND engineer_id = ? AND status = 'Submitted'"
                    );
                    if (!$resetReview) {
                        throw new RuntimeException('Failed to reset the Admin review status.');
                    }
                    $resetReview->bind_param('ii', $inspectionId, $userId);
                    $resetReview->execute();

                    audit_log_event(
                        $conn,
                        $userId,
                        $currentReviewStatus === 'Returned'
                            ? 'resubmit_site_inspection_report'
                            : 'submit_site_inspection_report',
                        'site_inspection',
                        $inspectionId,
                        [
                            'status' => $currentStatus,
                            'admin_review_status' => $currentReviewStatus,
                            'submitted_at' => $inspectionState['submitted_at'] ?? null,
                        ],
                        [
                            'status' => 'Submitted',
                            'admin_review_status' => 'Pending',
                        ]
                    );
                }

                $conn->commit();
                $message = $costingAction === 'submit_to_admin'
                    ? 'Costing submitted to Admin.'
                    : 'Inspection draft saved.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'Failed to save costing.';
            }
        }
    }
}

$materialOptions = material_stock_fetch_active_materials($conn);
$assetRequirementOptions = [];
$assetRequirementsByInspection = [];
$assetRequirementsReady = engineer_inspection_asset_requirements_table_exists($conn);

if ($assetRequirementsReady) {
    $assetOptionsResult = $conn->query(
        "SELECT a.id, a.asset_name,
                CASE
                    WHEN COALESCE(unit_totals.total_units, 0) > 0 THEN COALESCE(unit_totals.available_units, 0)
                    ELSE COALESCE(i.quantity, 0)
                END AS available_quantity
         FROM assets a
         INNER JOIN inventory i ON i.asset_id = a.id
         LEFT JOIN (
            SELECT inventory_id,
                   COUNT(*) AS total_units,
                   SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_units
            FROM asset_units
            WHERE status <> 'archived'
            GROUP BY inventory_id
         ) unit_totals ON unit_totals.inventory_id = i.id
         WHERE a.deleted_at IS NULL
         ORDER BY a.asset_name ASC, a.id ASC"
    );
    $assetRequirementOptions = $assetOptionsResult instanceof mysqli_result
        ? $assetOptionsResult->fetch_all(MYSQLI_ASSOC)
        : [];

    $assetRequirementsResult = $conn->query(
        'SELECT inspection_id, asset_id, quantity_required, notes
         FROM site_inspection_asset_requirements
         ORDER BY id ASC'
    );
    foreach ($assetRequirementsResult?->fetch_all(MYSQLI_ASSOC) ?: [] as $assetRequirement) {
        $assetRequirementsByInspection[(int)$assetRequirement['inspection_id']][] = $assetRequirement;
    }
}

$inspections = [];
$stmt = $conn->prepare(
    'SELECT
        si.id,
        si.scheduled_at,
        si.site_notes,
        si.status,
        si.acknowledged_at,
        si.started_at,
        si.completed_at,
        si.submitted_at,
        si.admin_review_status,
        si.admin_remarks,
        si.admin_reviewed_at,
        si.engineer_findings,
        si.risk_notes,
        si.client_requests,
        si.created_at,
        s.client_name,
        s.company_name,
        s.email,
        s.contact_no,
        s.site_address,
        s.barangay,
        s.city_municipality,
        s.province,
        s.service_category,
        s.description
     FROM site_inspections si
     INNER JOIN service_inquiries s ON s.id = si.inquiry_id
     WHERE si.engineer_id = ?
     ORDER BY si.scheduled_at ASC, si.id DESC'
);
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['complete_site_address'] = engineer_inspection_complete_site_address($row);
        $inspections[] = $row;
    }
}

$costItemsByInspection = [];
$costResult = $conn->query('SELECT * FROM site_inspection_cost_items ORDER BY id ASC');
if ($costResult) {
    while ($item = $costResult->fetch_assoc()) {
        $costItemsByInspection[(int)$item['inspection_id']][] = $item;
    }
}

$csrfToken = engineer_inspection_csrf_token();
$engineerPageTitle = 'Site Inspections - Engineer';
$engineerCssFiles = [
    '/codesamplecaps/ENGINEER/css/site-inspections.css',
    '/codesamplecaps/SHARED/toast/css/toast.css',
];
require __DIR__ . '/../layout/header.php';
?>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>
<main class="main-content">
    <?php
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="inspection-shell">
        <?php if ($message === 'Inspection draft saved.'): ?>
            <div class="shared-toast shared-toast--success" data-shared-toast role="status">
                <span>Inspection draft saved.</span>
                <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
                <span class="shared-toast__progress" aria-hidden="true"></span>
            </div>
        <?php elseif ($message): ?>
            <div class="inspection-flash success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?><div class="inspection-flash error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <section class="inspection-panel">
            <?php if (empty($inspections)): ?>
                <p class="inspection-meta">No site inspections yet.</p>
            <?php else: ?>
                <?php foreach ($inspections as $inspection): ?>
                    <?php
                    $inspectionId = (int)$inspection['id'];
                    $costItems = $costItemsByInspection[$inspectionId] ?? [];
                    $assetRequirements = $assetRequirementsByInspection[$inspectionId] ?? [];
                    $totalCost = array_sum(array_map(static fn($item) => (float)($item['line_total'] ?? 0), $costItems));
                    $inspectionStatus = (string)($inspection['status'] ?? 'Assigned');
                    $adminReviewStatus = (string)($inspection['admin_review_status'] ?? 'Pending');
                    $displayInspectionStatus = $inspectionStatus === 'Completed' && $adminReviewStatus === 'Returned'
                        ? 'Returned for Revision'
                        : $inspectionStatus;
                    $inspectionStatuses = site_inspection_statuses();
                    $currentStatusIndex = array_search($inspectionStatus, $inspectionStatuses, true);
                    $currentStatusIndex = $currentStatusIndex === false ? -1 : $currentStatusIndex;
                    $isSubmittedToAdmin = $inspectionStatus === 'Submitted';
                    $canEditCosting = in_array($inspectionStatus, ['Ongoing', 'Completed'], true);
                    $canSubmitToAdmin = $inspectionStatus === 'Completed';
                    $workflowAction = match ($inspectionStatus) {
                        'Assigned' => 'acknowledge',
                        'Acknowledged' => 'start',
                        'Ongoing' => 'complete',
                        default => '',
                    };
                    $workflowActionLabel = match ($inspectionStatus) {
                        'Assigned' => 'Acknowledge Assignment',
                        'Acknowledged' => 'Start Inspection',
                        'Ongoing' => 'Mark Inspection Completed',
                        default => '',
                    };
                    $statusTimes = [
                        'Assigned' => $inspection['created_at'] ?? null,
                        'Acknowledged' => $inspection['acknowledged_at'] ?? null,
                        'Ongoing' => $inspection['started_at'] ?? null,
                        'Completed' => $inspection['completed_at'] ?? null,
                        'Submitted' => $inspection['submitted_at'] ?? null,
                    ];
                    if (empty($costItems)) {
                        $costItems = [[
                            'item_type' => 'material',
                            'inventory_id' => '',
                            'material_id' => '',
                            'item_name' => '',
                            'quantity' => 1,
                            'unit' => 'unit',
                            'unit_cost' => 0,
                            'notes' => '',
                        ]];
                    }
                    ?>
                    <article class="inspection-card">
                        <div class="inspection-card__head">
                            <div>
                                <h2><?php echo htmlspecialchars((string)$inspection['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                <p class="inspection-meta"><?php echo htmlspecialchars((string)$inspection['service_category'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <span class="inspection-status" data-status="<?php echo htmlspecialchars($displayInspectionStatus, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($displayInspectionStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="inspection-card__schedule">
                                <?php echo htmlspecialchars(site_inspection_format_datetime($inspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <button type="button" class="btn-primary inspection-view-button" data-inspection-modal-open="inspectionModal<?php echo $inspectionId; ?>">
                                View Details
                            </button>
                        </div>

                        <div class="inspection-modal" id="inspectionModal<?php echo $inspectionId; ?>" hidden>
                            <div class="inspection-modal__panel" role="dialog" aria-modal="true" aria-labelledby="inspectionModalTitle<?php echo $inspectionId; ?>">
                                <div class="inspection-modal__head">
                                    <div>
                                        <span class="inspection-modal__eyebrow">Site Inspection</span>
                                        <h2 id="inspectionModalTitle<?php echo $inspectionId; ?>"><?php echo htmlspecialchars((string)$inspection['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                        <p><?php echo htmlspecialchars((string)$inspection['service_category'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <button type="button" class="inspection-modal__close" data-inspection-modal-close aria-label="Close inspection details">&times;</button>
                                </div>

                                <div class="inspection-grid">
                                    <div class="inspection-detail"><span>Schedule</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($inspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Contact</span><strong><?php echo htmlspecialchars((string)$inspection['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Email</span><strong><?php echo htmlspecialchars((string)$inspection['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Company</span><strong><?php echo htmlspecialchars((string)($inspection['company_name'] ?: 'N/A'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail inspection-detail--wide"><span>Site Address</span><strong><?php echo htmlspecialchars((string)($inspection['complete_site_address'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail inspection-detail--wide"><span>Admin Notes</span><strong><?php echo htmlspecialchars((string)($inspection['site_notes'] ?: 'None'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                </div>

                                <p class="inspection-description"><?php echo nl2br(htmlspecialchars((string)$inspection['description'], ENT_QUOTES, 'UTF-8')); ?></p>

                                <?php if (!empty($inspection['admin_remarks'])): ?>
                                    <div class="inspection-admin-remarks" data-review-status="<?php echo htmlspecialchars($adminReviewStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                        <strong>Admin Remarks / Reason for Return</strong>
                                        <p><?php echo nl2br(htmlspecialchars((string)$inspection['admin_remarks'], ENT_QUOTES, 'UTF-8')); ?></p>
                                        <small>Reviewed: <?php echo htmlspecialchars(site_inspection_format_datetime($inspection['admin_reviewed_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></small>
                                    </div>
                                <?php endif; ?>

                                <div class="inspection-workflow" aria-label="Inspection progress">
                                    <?php foreach ($inspectionStatuses as $statusIndex => $statusLabel): ?>
                                        <?php
                                        $stepClass = $statusIndex < $currentStatusIndex
                                            ? 'is-done'
                                            : ($statusIndex === $currentStatusIndex ? 'is-current' : '');
                                        ?>
                                        <div class="inspection-workflow__step <?php echo $stepClass; ?>">
                                            <span><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <small><?php echo htmlspecialchars(site_inspection_format_datetime($statusTimes[$statusLabel] ?? null), ENT_QUOTES, 'UTF-8'); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($workflowAction !== ''): ?>
                                    <form method="POST" class="inspection-status-action">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                                        <input type="hidden" name="workflow_action" value="<?php echo htmlspecialchars($workflowAction, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="btn-primary" data-confirm-inspection-transition="<?php echo htmlspecialchars($workflowActionLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($workflowActionLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" class="inspection-costing-form" data-costing-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                            <div class="costing-head">
                                <strong>Inspection Findings &amp; Costing</strong>
                                <span>Total: <b data-costing-total><?php echo engineer_format_money($totalCost); ?></b></span>
                            </div>
                            <p class="costing-error" data-costing-error hidden></p>
                            <?php if ($isSubmittedToAdmin): ?>
                                <div class="inspection-submit-note">Submitted to Admin. Wait for Admin review before changing this costing.</div>
                            <?php elseif (!$canEditCosting): ?>
                                <div class="inspection-submit-note inspection-submit-note--waiting">Complete the current inspection step before adding findings and costing.</div>
                            <?php endif; ?>

                            <div class="inspection-costing-notes">
                                <label class="inspection-costing-notes__findings">
                                    <span>Engineer Findings <b>*</b></span>
                                    <textarea name="engineer_findings" rows="3" minlength="10" placeholder="Actual problem found, site condition, and recommended scope" <?php echo !$canEditCosting ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['engineer_findings'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </label>
                                <label>
                                    <span>Risk / Safety Notes</span>
                                    <textarea name="risk_notes" rows="2" placeholder="Access issue, electrical risk, working height, downtime risk..." <?php echo !$canEditCosting ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['risk_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </label>
                                <label>
                                    <span>Client Requests</span>
                                    <textarea name="client_requests" rows="2" placeholder="Preferred schedule, brand request, special instruction..." <?php echo !$canEditCosting ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['client_requests'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </label>
                            </div>

                            <div class="costing-rows" data-costing-rows>
                                <?php foreach ($costItems as $item): ?>
                                    <div class="costing-row">
                                        <label>
                                            <span>Type</span>
                                            <select name="item_type[]" required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                <option value="material" <?php echo ($item['item_type'] ?? '') === 'material' ? 'selected' : ''; ?>>Material</option>
                                                <option value="labor" <?php echo ($item['item_type'] ?? '') === 'labor' ? 'selected' : ''; ?>>Labor</option>
                                                <option value="equipment" <?php echo ($item['item_type'] ?? '') === 'equipment' ? 'selected' : ''; ?>>Equipment (Billable / Rental)</option>
                                                <option value="service" <?php echo ($item['item_type'] ?? '') === 'service' ? 'selected' : ''; ?>>Service</option>
                                                <option value="other" <?php echo ($item['item_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </label>
                                        <label data-material-field>
                                            <span>Material Reference</span>
                                            <input type="hidden" name="inventory_id[]" value="<?php echo (int)($item['inventory_id'] ?? 0); ?>">
                                            <select name="material_id[]" data-material-picker <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                <option value="">Manual / non-stock item</option>
                                                <?php foreach ($materialOptions as $material): ?>
                                                    <option
                                                        value="<?php echo (int)$material['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        <?php echo (int)($item['material_id'] ?? 0) === (int)$material['id'] ? 'selected' : ''; ?>
                                                    >
                                                        <?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars(number_format((float)$material['available_quantity'], 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?> available
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span>Item / Labor</span>
                                            <input type="text" name="item_name[]" placeholder="Item or labor name" value="<?php echo htmlspecialchars((string)($item['item_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                        </label>
                                        <label>
                                            <span>Qty</span>
                                            <input type="text" name="quantity[]" inputmode="decimal" autocomplete="off" value="<?php echo htmlspecialchars((string)($item['quantity'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>" data-costing-decimal required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                        </label>
                                        <label>
                                            <span>Unit</span>
                                            <select name="unit[]" required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                <?php foreach (['unit', 'pc', 'pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'bundle', 'sheet', 'pair', 'tube', 'meter', 'kg', 'liter', 'hour', 'day', 'trip'] as $unitOption): ?>
                                                    <option value="<?php echo htmlspecialchars($unitOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($item['unit'] ?? 'unit') === $unitOption ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars(ucfirst($unitOption), ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span>Estimated Unit Cost (PHP)</span>
                                            <input type="text" name="unit_cost[]" inputmode="decimal" autocomplete="off" value="<?php echo htmlspecialchars((string)($item['unit_cost'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" data-costing-decimal required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                        </label>
                                        <label>
                                            <span>Notes</span>
                                            <input type="text" name="notes[]" placeholder="Notes" value="<?php echo htmlspecialchars((string)($item['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                        </label>
                                        <?php if ($canEditCosting): ?>
                                            <button type="button" class="btn-remove-row" data-remove-costing-row>Remove</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <section class="asset-requirements" data-asset-requirements>
                                <div class="asset-requirements__head">
                                    <div>
                                        <strong>Asset Requirements</strong>
                                        <p>Reusable company assets needed for the future project. Not included in costing.</p>
                                    </div>
                                </div>

                                <?php if (!$assetRequirementsReady): ?>
                                    <p class="inspection-submit-note inspection-submit-note--waiting">Asset Requirements setup is not ready yet. Please apply the required database migration.</p>
                                <?php else: ?>
                                    <div class="asset-requirement-rows" data-asset-requirement-rows>
                                        <?php foreach ($assetRequirements as $assetRequirement): ?>
                                            <div class="asset-requirement-row" data-asset-requirement-row>
                                                <label>
                                                    <span>Asset</span>
                                                    <select name="asset_requirement_asset_id[]" data-asset-requirement-picker <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                        <option value="">Select asset</option>
                                                        <?php foreach ($assetRequirementOptions as $asset): ?>
                                                            <option value="<?php echo (int)$asset['id']; ?>" data-available="<?php echo (int)$asset['available_quantity']; ?>" <?php echo (int)$assetRequirement['asset_id'] === (int)$asset['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars((string)$asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?> — <?php echo (int)$asset['available_quantity']; ?> available
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label>
                                                    <span>Available</span>
                                                    <output data-asset-requirement-available>0 available</output>
                                                </label>
                                                <label>
                                                    <span>Qty Needed</span>
                                                    <input type="text" name="asset_requirement_quantity[]" inputmode="numeric" autocomplete="off" value="<?php echo htmlspecialchars((string)$assetRequirement['quantity_required'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                    <small class="asset-requirement-shortage" data-asset-requirement-shortage hidden></small>
                                                </label>
                                                <label>
                                                    <span>Notes</span>
                                                    <input type="text" name="asset_requirement_notes[]" placeholder="Optional notes" value="<?php echo htmlspecialchars((string)($assetRequirement['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                </label>
                                                <?php if ($canEditCosting): ?>
                                                    <button type="button" class="btn-remove-row" data-remove-asset-requirement>Remove</button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if ($canEditCosting): ?>
                                        <template data-asset-requirement-template>
                                            <div class="asset-requirement-row" data-asset-requirement-row>
                                                <label>
                                                    <span>Asset</span>
                                                    <select name="asset_requirement_asset_id[]" data-asset-requirement-picker>
                                                        <option value="">Select asset</option>
                                                        <?php foreach ($assetRequirementOptions as $asset): ?>
                                                            <option value="<?php echo (int)$asset['id']; ?>" data-available="<?php echo (int)$asset['available_quantity']; ?>">
                                                                <?php echo htmlspecialchars((string)$asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?> — <?php echo (int)$asset['available_quantity']; ?> available
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label><span>Available</span><output data-asset-requirement-available>Select an asset</output></label>
                                                <label><span>Qty Needed</span><input type="text" name="asset_requirement_quantity[]" inputmode="numeric" autocomplete="off" value="1"><small class="asset-requirement-shortage" data-asset-requirement-shortage hidden></small></label>
                                                <label><span>Notes</span><input type="text" name="asset_requirement_notes[]" placeholder="Optional notes"></label>
                                                <button type="button" class="btn-remove-row" data-remove-asset-requirement>Remove</button>
                                            </div>
                                        </template>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </section>

                            <?php if ($canEditCosting): ?>
                                <div class="inspection-actions">
                                    <button type="button" class="btn-secondary" data-add-costing-row>Add item</button>
                                    <?php if ($assetRequirementsReady): ?>
                                        <button type="button" class="btn-secondary" data-add-asset-requirement>+ Add Asset Requirement</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-clear-form" data-clear-costing-form>Clear Form</button>
                                    <button type="submit" name="costing_action" value="save_draft" class="btn-secondary" data-save-draft disabled>Save Draft</button>
                                    <?php if ($canSubmitToAdmin): ?>
                                        <button type="submit" name="costing_action" value="submit_to_admin" class="btn-primary" data-confirm-submit-costing>Submit to Admin</button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php
$engineerJsFiles = [
    '/codesamplecaps/SHARED/toast/js/toast.js',
    '/codesamplecaps/ENGINEER/js/site-inspections.js',
];
require __DIR__ . '/../layout/footer.php';
?>
