<?php
define('AUTH_REQUIRED_ROLE', 'engineer');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/site_inspections.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
$error = '';

site_inspection_ensure_table($conn);
site_inspection_ensure_costing_table($conn);

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

function engineer_get_inspection_status(mysqli $conn, int $inspectionId, int $engineerId): string
{
    $stmt = $conn->prepare('SELECT status FROM site_inspections WHERE id = ? AND engineer_id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('ii', $inspectionId, $engineerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (string)($row['status'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inspectionId = (int)($_POST['inspection_id'] ?? 0);
    $costingAction = (string)($_POST['costing_action'] ?? 'save_draft');

    if (!engineer_inspection_valid_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } elseif ($inspectionId <= 0 || !engineer_owns_inspection($conn, $inspectionId, $userId)) {
        $error = 'Inspection not found.';
    } elseif (engineer_get_inspection_status($conn, $inspectionId, $userId) === 'Submitted to Admin') {
        $error = 'This costing was already submitted to Admin.';
    } else {
        $itemTypes = $_POST['item_type'] ?? [];
        $inventoryIds = $_POST['inventory_id'] ?? [];
        $itemNames = $_POST['item_name'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitCosts = $_POST['unit_cost'] ?? [];
        $notes = $_POST['notes'] ?? [];
        $engineerFindings = trim((string)($_POST['engineer_findings'] ?? ''));
        $riskNotes = trim((string)($_POST['risk_notes'] ?? ''));
        $clientRequests = trim((string)($_POST['client_requests'] ?? ''));
        $rows = [];
        $hasMaterial = false;
        $hasLabor = false;
        $grandTotal = 0.0;

        foreach ($itemNames as $index => $rawName) {
            $itemName = trim((string)$rawName);
            $quantity = max(0, (float)($quantities[$index] ?? 0));
            $unitCost = max(0, (float)($unitCosts[$index] ?? 0));
            $itemType = in_array(($itemTypes[$index] ?? 'material'), ['material', 'labor', 'other'], true)
                ? (string)$itemTypes[$index]
                : 'material';
            $inventoryId = (int)($inventoryIds[$index] ?? 0);

            if ($itemName === '' && $quantity <= 0 && $unitCost <= 0) {
                continue;
            }

            if ($itemName === '' || $quantity <= 0) {
                $error = 'Please complete item name and quantity.';
                break;
            }

            if ($costingAction === 'submit_to_admin' && $unitCost <= 0) {
                $error = 'Unit cost must be greater than 0 before submitting to Admin.';
                break;
            }

            $lineTotal = $quantity * $unitCost;
            $grandTotal += $lineTotal;
            $hasMaterial = $hasMaterial || $itemType === 'material';
            $hasLabor = $hasLabor || $itemType === 'labor';

            $rows[] = [
                'item_type' => $itemType,
                'inventory_id' => $inventoryId > 0 ? $inventoryId : null,
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
                'notes' => trim((string)($notes[$index] ?? '')),
            ];
        }

        if ($error === '' && empty($rows)) {
            $error = 'Please add at least one costing item.';
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

        if ($error === '') {
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
                     (inspection_id, item_type, inventory_id, item_name, quantity, unit_cost, line_total, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$insertStmt) {
                    throw new RuntimeException('Failed to prepare costing save.');
                }

                foreach ($rows as $row) {
                    $itemType = $row['item_type'];
                    $inventoryId = $row['inventory_id'];
                    $itemName = $row['item_name'];
                    $quantity = $row['quantity'];
                    $unitCost = $row['unit_cost'];
                    $lineTotal = $row['line_total'];
                    $itemNotes = $row['notes'];
                    $insertStmt->bind_param(
                        'isisddds',
                        $inspectionId,
                        $itemType,
                        $inventoryId,
                        $itemName,
                        $quantity,
                        $unitCost,
                        $lineTotal,
                        $itemNotes
                    );
                    $insertStmt->execute();
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

                // Kapag final submit, Admin review na ang next step.
                $status = $costingAction === 'submit_to_admin' ? 'Submitted to Admin' : 'Costing Draft';
                $statusStmt = $conn->prepare('UPDATE site_inspections SET status = ? WHERE id = ? AND engineer_id = ?');
                if (!$statusStmt) {
                    throw new RuntimeException('Failed to update inspection status.');
                }
                $statusStmt->bind_param('sii', $status, $inspectionId, $userId);
                $statusStmt->execute();

                $conn->commit();
                $message = $costingAction === 'submit_to_admin'
                    ? 'Costing submitted to Admin.'
                    : 'Inspection costing saved.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'Failed to save costing.';
            }
        }
    }
}

$inventoryOptions = [];
$inventoryResult = $conn->query(
    "SELECT i.id, i.quantity, i.status, a.asset_name, a.asset_category, a.asset_type
     FROM inventory i
     INNER JOIN assets a ON a.id = i.asset_id
     ORDER BY a.asset_name ASC"
);
if ($inventoryResult) {
    $inventoryOptions = $inventoryResult->fetch_all(MYSQLI_ASSOC);
}

$inspections = [];
$stmt = $conn->prepare(
    'SELECT
        si.id,
        si.scheduled_at,
        si.site_notes,
        si.status,
        si.engineer_findings,
        si.risk_notes,
        si.client_requests,
        si.created_at,
        s.client_name,
        s.company_name,
        s.email,
        s.contact_no,
        s.site_address,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Inspections - Engineer</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
    <link rel="stylesheet" href="../css/site-inspections.css">
</head>
<body>
<?php include '../sidebar/sidebar_engineer.php'; ?>
<main class="main-content">
    <?php
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="inspection-shell">
        <?php if ($message): ?><div class="inspection-flash success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="inspection-flash error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <section class="inspection-panel">
            <?php if (empty($inspections)): ?>
                <p class="inspection-meta">No site inspections yet.</p>
            <?php else: ?>
                <?php foreach ($inspections as $inspection): ?>
                    <?php
                    $inspectionId = (int)$inspection['id'];
                    $costItems = $costItemsByInspection[$inspectionId] ?? [];
                    $totalCost = array_sum(array_map(static fn($item) => (float)($item['line_total'] ?? 0), $costItems));
                    $inspectionStatus = (string)($inspection['status'] ?? 'Scheduled');
                    $isSubmittedToAdmin = $inspectionStatus === 'Submitted to Admin';
                    if (empty($costItems)) {
                        $costItems = [[
                            'item_type' => 'material',
                            'inventory_id' => '',
                            'item_name' => '',
                            'quantity' => 1,
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
                                        <span class="inspection-status inspection-status--modal" data-status="<?php echo htmlspecialchars($inspectionStatus, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($inspectionStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <button type="button" class="inspection-modal__close" data-inspection-modal-close aria-label="Close inspection details">&times;</button>
                                </div>

                                <div class="inspection-grid">
                                    <div class="inspection-detail"><span>Schedule</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($inspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Contact</span><strong><?php echo htmlspecialchars((string)$inspection['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Email</span><strong><?php echo htmlspecialchars((string)$inspection['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Company</span><strong><?php echo htmlspecialchars((string)($inspection['company_name'] ?: 'N/A'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail inspection-detail--wide"><span>Site Address</span><strong><?php echo htmlspecialchars((string)$inspection['site_address'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail inspection-detail--wide"><span>Admin Notes</span><strong><?php echo htmlspecialchars((string)($inspection['site_notes'] ?: 'None'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                </div>

                                <p class="inspection-description"><?php echo nl2br(htmlspecialchars((string)$inspection['description'], ENT_QUOTES, 'UTF-8')); ?></p>

                                <form method="POST" class="inspection-costing-form" data-costing-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                            <div class="costing-head">
                                <strong>Costing Draft</strong>
                                <span>Total: <b data-costing-total><?php echo engineer_format_money($totalCost); ?></b></span>
                            </div>
                            <?php if ($isSubmittedToAdmin): ?>
                                <div class="inspection-submit-note">Submitted to Admin. Wait for Admin review before changing this costing.</div>
                            <?php endif; ?>

                            <div class="inspection-costing-notes">
                                <label>
                                    <span>Engineer Findings <b>*</b></span>
                                    <textarea name="engineer_findings" rows="3" minlength="10" placeholder="Actual problem found, site condition, and recommended scope" <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['engineer_findings'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </label>
                                <label>
                                    <span>Risk / Safety Notes</span>
                                    <textarea name="risk_notes" rows="2" placeholder="Access issue, electrical risk, working height, downtime risk..." <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['risk_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </label>
                                <label>
                                    <span>Client Requests</span>
                                    <textarea name="client_requests" rows="2" placeholder="Preferred schedule, brand request, special instruction..." <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['client_requests'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </label>
                            </div>

                            <div class="costing-rows" data-costing-rows>
                                <?php foreach ($costItems as $item): ?>
                                    <div class="costing-row">
                                        <select name="item_type[]" required <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>>
                                            <option value="material" <?php echo ($item['item_type'] ?? '') === 'material' ? 'selected' : ''; ?>>Material</option>
                                            <option value="labor" <?php echo ($item['item_type'] ?? '') === 'labor' ? 'selected' : ''; ?>>Labor</option>
                                            <option value="other" <?php echo ($item['item_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                        <select name="inventory_id[]" data-inventory-picker <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>>
                                            <option value="">No inventory link</option>
                                            <?php foreach ($inventoryOptions as $inventory): ?>
                                                <option
                                                    value="<?php echo (int)$inventory['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars((string)$inventory['asset_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    <?php echo (int)($item['inventory_id'] ?? 0) === (int)$inventory['id'] ? 'selected' : ''; ?>
                                                >
                                                    <?php echo htmlspecialchars((string)$inventory['asset_name'], ENT_QUOTES, 'UTF-8'); ?> | Stock: <?php echo (int)$inventory['quantity']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="item_name[]" placeholder="Item or labor name" value="<?php echo htmlspecialchars((string)($item['item_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>>
                                        <input type="number" name="quantity[]" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string)($item['quantity'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>" data-costing-number required <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>>
                                        <input type="number" name="unit_cost[]" min="0" step="0.01" value="<?php echo htmlspecialchars((string)($item['unit_cost'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" data-costing-number required <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>>
                                        <input type="text" name="notes[]" placeholder="Notes" value="<?php echo htmlspecialchars((string)($item['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isSubmittedToAdmin ? 'disabled' : ''; ?>>
                                        <?php if (!$isSubmittedToAdmin): ?>
                                            <button type="button" class="btn-remove-row" data-remove-costing-row>Remove</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (!$isSubmittedToAdmin): ?>
                                <div class="inspection-actions">
                                    <button type="button" class="btn-secondary" data-add-costing-row>Add item</button>
                                    <button type="submit" name="costing_action" value="save_draft" class="btn-secondary">Save Draft</button>
                                    <button type="submit" name="costing_action" value="submit_to_admin" class="btn-primary" data-confirm-submit-costing>Submit to Admin</button>
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
<script src="../js/engineer.js"></script>
<script src="../js/site-inspections.js"></script>
</body>
</html>
