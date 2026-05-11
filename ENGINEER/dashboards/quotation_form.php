<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/quotation_module.php';

require_role('engineer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$quotationId = (int)($_GET['id'] ?? 0);
$prefillProjectId = (int)($_GET['project_id'] ?? 0);
$flash = quotation_module_consume_flash();
$bootstrap = quotation_module_bootstrap_tables($conn);
$tablesReady = (bool)($bootstrap['ready'] ?? false);
$csrfToken = quotation_module_csrf_token();
$projects = $tablesReady ? quotation_module_fetch_engineer_projects($conn, $userId) : [];
$foremen = $tablesReady ? quotation_module_fetch_foremen($conn) : [];
$inventoryOptions = $tablesReady ? quotation_module_fetch_inventory_options($conn) : [];
$assetOptions = $tablesReady ? quotation_module_fetch_asset_options($conn) : [];
$quotation = null;
$items = [];
$reviews = [];
$history = [];

if ($tablesReady && $quotationId > 0) {
    $quotation = quotation_module_fetch_quotation($conn, $quotationId);
    if (!$quotation || !quotation_module_user_can_access($quotation, 'engineer', $userId)) {
        quotation_module_set_flash('error', 'Quotation not found or inaccessible.');
        quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotations.php');
    }

    $items = quotation_module_fetch_quotation_items($conn, $quotationId);
    $reviews = quotation_module_fetch_reviews($conn, $quotationId);
    $history = quotation_module_fetch_history($conn, $quotationId);
}

$canEditDraft = $quotation === null || (((string)$quotation['status'] === 'draft') && (int)($quotation['is_locked'] ?? 0) === 0);
$canSubmitForReview = $quotation !== null && (string)$quotation['status'] === 'draft' && (int)($quotation['is_locked'] ?? 0) === 0;
$canSubmitForApproval = $quotation !== null && (string)$quotation['status'] === 'under_review';

if (empty($items)) {
    $items = [[
        'item_type' => 'material',
        'source_table' => '',
        'source_id' => '',
        'item_name' => '',
        'description' => '',
        'unit' => 'unit',
        'quantity' => 1,
        'rate' => 0,
        'hours' => 0,
        'days' => 0,
        'line_total' => 0,
    ]];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engineer Quotation Form - Edge Automation</title>
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
    <link rel="stylesheet" href="../css/quotation-form.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_engineer.php'; ?>
<main class="main-content">
    <div class="quotation-shell">
        <?php if ($flash): ?>
            <div class="flash <?php echo htmlspecialchars((string)$flash['type']); ?>"><?php echo htmlspecialchars((string)$flash['message']); ?></div>
        <?php endif; ?>

        <?php if (!empty($bootstrap['errors'])): ?>
            <div class="flash error">
                Quotation setup failed: <?php echo htmlspecialchars(implode(' | ', array_unique(array_map('strval', $bootstrap['errors'])))); ?>
            </div>
        <?php endif; ?>

        <?php if (!$tablesReady): ?>
            <section class="panel">
                <h1>Quotation Setup Needed</h1>
                <p class="helper-copy">The app tried to create the quotation tables automatically, but they are still unavailable. Run <code>scripts/setup_quotation_tables.php</code> if the database account blocks table creation.</p>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="top-actions">
                    <div>
                        <p class="section-eyebrow">Engineer Draft Builder</p>
                        <h1><?php echo $quotation ? 'Quotation ' . htmlspecialchars((string)$quotation['quotation_no']) : 'Create New Quotation'; ?></h1>
                        <p class="helper-copy">Engineer owns the quotation draft. Foreman reviews only. Super Admin gives final approval.</p>
                    </div>
                    <?php if ($quotation): ?>
                        <div class="btn-row">
                            <span class="status-pill <?php echo htmlspecialchars(quotation_module_status_class((string)$quotation['status'])); ?>"><?php echo htmlspecialchars(quotation_module_status_label((string)$quotation['status'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($quotation && !$canEditDraft): ?>
                <div class="readonly-banner">This quotation is no longer editable as a draft. You can still review comments and move it forward if it is under foreman review.</div>
            <?php endif; ?>

            <div class="grid two">
                <form class="grid" method="POST" action="/codesamplecaps/controllers/QuotationController.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="quotation_id" value="<?php echo (int)($quotation['id'] ?? 0); ?>">

                    <section class="panel grid">
                        <div>
                            <h2>Quotation Header</h2>
                            <p class="helper-copy">Link the quotation to the assigned project and choose the foreman reviewer.</p>
                        </div>
                        <div class="grid form">
                            <div class="form-group">
                                <div class="field-label-row">
                                    <label for="project_id">Project <span class="required-dot">*</span></label>
                                    <button type="button" class="field-tip" aria-label="Project selection help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">Select one of your assigned projects. The duration below is pulled automatically from that project's saved timeline.</span>
                                    </button>
                                </div>
                                <select id="project_id" name="project_id" <?php echo $canEditDraft ? '' : 'disabled'; ?> required>
                                    <option value="">Select project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <?php $selectedProjectId = (int)($quotation['project_id'] ?? $prefillProjectId); ?>
                                        <option
                                            value="<?php echo (int)$project['id']; ?>"
                                            data-duration-days="<?php echo htmlspecialchars((string)($project['project_duration_days'] ?? '')); ?>"
                                            data-start-date="<?php echo htmlspecialchars((string)($project['project_start_date'] ?? '')); ?>"
                                            data-end-date="<?php echo htmlspecialchars((string)($project['estimated_completion_date'] ?? '')); ?>"
                                            <?php echo $selectedProjectId === (int)$project['id'] ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars((string)$project['project_name'] . ' | ' . (string)$project['client_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$canEditDraft): ?><input type="hidden" name="project_id" value="<?php echo (int)$quotation['project_id']; ?>"><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <div class="field-label-row">
                                    <label for="foreman_reviewer_id">Foreman Reviewer <span class="required-dot">*</span></label>
                                    <button type="button" class="field-tip" aria-label="Foreman reviewer help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">Assign the foreman who will review manpower feasibility, execution concerns, and revision notes before admin approval.</span>
                                    </button>
                                </div>
                                <select id="foreman_reviewer_id" name="foreman_reviewer_id" <?php echo $canEditDraft ? '' : 'disabled'; ?>>
                                    <option value="">Select foreman</option>
                                    <?php foreach ($foremen as $foreman): ?>
                                        <option value="<?php echo (int)$foreman['id']; ?>" <?php echo (int)($quotation['foreman_reviewer_id'] ?? 0) === (int)$foreman['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)$foreman['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$canEditDraft && !empty($quotation['foreman_reviewer_id'])): ?><input type="hidden" name="foreman_reviewer_id" value="<?php echo (int)$quotation['foreman_reviewer_id']; ?>"><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <div class="field-label-row">
                                    <label for="title">Quotation Title <span class="required-dot">*</span></label>
                                    <button type="button" class="field-tip" aria-label="Quotation title help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">Use a short but clear title for the work package, site scope, or phase so reviewers can identify the quotation quickly.</span>
                                    </button>
                                </div>
                                <input id="title" type="text" name="title" minlength="5" maxlength="160" value="<?php echo htmlspecialchars((string)($quotation['title'] ?? '')); ?>" <?php echo $canEditDraft ? '' : 'readonly'; ?> required>
                            </div>
                            <div class="form-group">
                                <div class="field-label-row">
                                    <label for="estimated_duration_days">Estimated Duration (Days) <span class="required-dot">*</span></label>
                                    <button type="button" class="field-tip" aria-label="Estimated duration help">
                                        <span class="field-tip__icon" aria-hidden="true">i</span>
                                        <span class="field-tip__bubble">This is read-only here because the system syncs it directly from the project timeline set by Super Admin.</span>
                                    </button>
                                </div>
                                <input id="estimated_duration_days" type="number" min="1" name="estimated_duration_days" value="<?php echo htmlspecialchars((string)($quotation['estimated_duration_days'] ?? '')); ?>" readonly required>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="field-label-row">
                                <label for="scope_summary">Scope Summary</label>
                                <button type="button" class="field-tip" aria-label="Scope summary help">
                                    <span class="field-tip__icon" aria-hidden="true">i</span>
                                    <span class="field-tip__bubble">Optional but recommended. Use this when the title alone is not enough to explain the deliverable, site work, major materials, or execution notes.</span>
                                </button>
                            </div>
                            <textarea id="scope_summary" name="scope_summary" rows="4" minlength="10" placeholder="Optional: brief scope, deliverables, major materials, or execution notes" <?php echo $canEditDraft ? '' : 'readonly'; ?>><?php echo htmlspecialchars((string)($quotation['scope_summary'] ?? '')); ?></textarea>
                        </div>
                    </section>

                    <section class="panel grid">
                        <div class="top-actions">
                            <div>
                                <h2>Cost Breakdown</h2>
                                <p class="helper-copy">Materials, assets, manpower, and other costs roll up automatically into the quotation totals.</p>
                            </div>
                            <?php if ($canEditDraft): ?>
                                <div class="btn-row">
                                    <button class="btn-secondary" type="button" data-add-row="material">Add Material</button>
                                    <button class="btn-secondary" type="button" data-add-row="manpower">Add Manpower</button>
                                    <button class="btn-secondary" type="button" data-add-row="other">Add Other</button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="items-table-wrap">
                            <table class="items-table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Item</th>
                                        <th>Description</th>
                                        <th>Unit</th>
                                        <th>Qty</th>
                                        <th>Hours</th>
                                        <th>Rate</th>
                                        <th>Line Total</th>
                                        <?php if ($canEditDraft): ?><th></th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="quotationItemsBody">
                                    <?php foreach ($items as $item): ?>
                                        <tr class="quotation-item-row">
                                            <td>
                                                <select name="item_type[]" class="item-type" <?php echo $canEditDraft ? '' : 'disabled'; ?>>
                                                    <?php foreach (['material', 'asset', 'manpower', 'other'] as $type): ?>
                                                        <option value="<?php echo $type; ?>" <?php echo (string)$item['item_type'] === $type ? 'selected' : ''; ?>><?php echo ucfirst($type); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="hidden" name="source_table[]" value="<?php echo htmlspecialchars((string)($item['source_table'] ?? '')); ?>">
                                                <input type="hidden" name="source_id[]" value="<?php echo htmlspecialchars((string)($item['source_id'] ?? '')); ?>">
                                            </td>
                                            <td><input type="text" name="item_name[]" data-quote-validate="item-name" minlength="2" maxlength="160" value="<?php echo htmlspecialchars((string)$item['item_name']); ?>" <?php echo $canEditDraft ? '' : 'readonly'; ?> required></td>
                                            <td><input type="text" name="item_description[]" value="<?php echo htmlspecialchars((string)($item['description'] ?? '')); ?>" <?php echo $canEditDraft ? '' : 'readonly'; ?>></td>
                                            <td><input type="text" name="unit[]" value="<?php echo htmlspecialchars((string)($item['unit'] ?? 'unit')); ?>" <?php echo $canEditDraft ? '' : 'readonly'; ?> required></td>
                                            <td><input type="number" step="0.01" min="0.01" name="quantity[]" class="item-quantity" data-quote-validate="quantity" value="<?php echo htmlspecialchars((string)($item['quantity'] ?? 0)); ?>" <?php echo $canEditDraft ? '' : 'readonly'; ?>></td>
                                            <td><input type="number" step="0.01" min="0" name="hours[]" class="item-hours" data-quote-validate="hours" value="<?php echo htmlspecialchars((string)($item['hours'] ?? 0)); ?>" <?php echo $canEditDraft ? '' : 'readonly'; ?>></td>
                                            <td><input type="number" step="0.01" min="0.01" name="rate[]" class="item-rate" data-quote-validate="rate" value="<?php echo htmlspecialchars((string)($item['rate'] ?? 0)); ?>" <?php echo $canEditDraft ? '' : 'readonly'; ?> required></td>
                                            <td><input type="text" class="item-total" value="<?php echo htmlspecialchars(number_format((float)($item['line_total'] ?? 0), 2)); ?>" readonly></td>
                                            <?php if ($canEditDraft): ?><td><button type="button" class="btn-danger" data-remove-row>Remove</button></td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="btn-row form-actions">
                            <a class="btn-ghost" href="/codesamplecaps/ENGINEER/dashboards/quotations.php">Back to List</a>
                            <?php if ($canEditDraft): ?>
                                <button class="btn-primary" type="submit" name="action" value="save_draft">Save Draft</button>
                            <?php endif; ?>
                            <?php if ($canSubmitForReview): ?>
                                <button class="btn-secondary" type="submit" name="action" value="submit_review">Submit For Foreman Review</button>
                            <?php endif; ?>
                            <?php if ($canSubmitForApproval): ?>
                                <button class="btn-primary" type="submit" name="action" value="submit_for_approval">Submit For Super Admin Approval</button>
                            <?php endif; ?>
                        </div>
                    </section>
                </form>

                <div class="grid">
                    <section class="panel totals-card">
                        <h2>Live Totals</h2>
                        <div class="totals-row"><span>Materials</span><strong id="materialsTotal">PHP 0.00</strong></div>
                        <div class="totals-row"><span>Assets</span><strong id="assetsTotal">PHP 0.00</strong></div>
                        <div class="totals-row"><span>Manpower</span><strong id="manpowerTotal">PHP 0.00</strong></div>
                        <div class="totals-row"><span>Other</span><strong id="otherTotal">PHP 0.00</strong></div>
                        <div class="totals-row total-emphasis"><span>Total Cost</span><strong id="totalCost">PHP 0.00</strong></div>
                        <div class="totals-row total-emphasis"><span>Selling Price</span><strong id="sellingPrice">PHP 0.00</strong></div>
                    </section>

                    <section class="panel catalog-panel">
                        <div class="catalog-panel__header">
                            <div>
                            </div>
                            <div class="catalog-tabs" role="tablist" aria-label="Quotation catalogs">
                                <button type="button" class="catalog-tab is-active" role="tab" aria-selected="true" data-catalog-tab="materials">Materials</button>
                                <button type="button" class="catalog-tab" role="tab" aria-selected="false" data-catalog-tab="assets">Assets</button>
                            </div>
                        </div>

                        <div class="catalog-section is-active" data-catalog-panel="materials">
                            <div class="catalog-section__intro">
                                <strong>Materials Catalog</strong>
                                <p class="helper-copy">Quick-add from inventory to avoid retyping common material rows.</p>
                            </div>
                            <div class="catalog-grid">
                                <?php foreach ($inventoryOptions as $inventory): ?>
                                    <article class="catalog-card">
                                        <strong><?php echo htmlspecialchars((string)$inventory['asset_name']); ?></strong>
                                        <small><?php echo htmlspecialchars((string)($inventory['asset_category'] ?? 'Material')); ?> | Stock: <?php echo (int)$inventory['quantity']; ?></small>
                                        <?php if ($canEditDraft): ?>
                                            <button type="button" class="btn-ghost" data-catalog-item data-item-type="material" data-source-table="inventory" data-source-id="<?php echo (int)$inventory['id']; ?>" data-item-name="<?php echo htmlspecialchars((string)$inventory['asset_name']); ?>" data-unit="unit">Add Material Row</button>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="catalog-section" data-catalog-panel="assets" hidden>
                            <div class="catalog-section__intro">
                                <strong>Assets Catalog</strong>
                                <p class="helper-copy">Use this for equipment and tracked asset usage lines.</p>
                            </div>
                            <div class="catalog-grid">
                                <?php foreach ($assetOptions as $asset): ?>
                                    <article class="catalog-card">
                                        <strong><?php echo htmlspecialchars((string)$asset['asset_name']); ?></strong>
                                        <small><?php echo htmlspecialchars((string)($asset['asset_type'] ?? 'Asset')); ?> | <?php echo htmlspecialchars((string)($asset['asset_status'] ?? 'available')); ?></small>
                                        <?php if ($canEditDraft): ?>
                                            <button type="button" class="btn-ghost" data-catalog-item data-item-type="asset" data-source-table="assets" data-source-id="<?php echo (int)$asset['id']; ?>" data-item-name="<?php echo htmlspecialchars((string)$asset['asset_name']); ?>" data-unit="day">Add Asset Row</button>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                    <?php if ($quotation): ?>
                        <section class="panel review-thread">
                            <h2>Review Notes</h2>
                            <?php if (!empty($reviews)): ?>
                                <?php foreach ($reviews as $review): ?>
                                    <article class="review-card">
                                        <small><?php echo htmlspecialchars((string)$review['full_name'] . ' | ' . quotation_module_format_datetime((string)$review['created_at'])); ?></small>
                                        <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$review['review_type']))); ?></strong>
                                        <p><?php echo nl2br(htmlspecialchars((string)$review['message'])); ?></p>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notice-card">No review notes yet.</div>
                            <?php endif; ?>
                        </section>

                        <section class="panel timeline">
                            <h2>Status History</h2>
                            <?php if (!empty($history)): ?>
                                <?php foreach ($history as $entry): ?>
                                    <article class="timeline-card">
                                        <small><?php echo htmlspecialchars(quotation_module_format_datetime((string)$entry['created_at'])); ?></small>
                                        <strong><?php echo htmlspecialchars((string)$entry['full_name']); ?></strong>
                                        <p><?php echo htmlspecialchars(quotation_module_status_label((string)($entry['from_status'] ?? 'draft')) . ' -> ' . quotation_module_status_label((string)$entry['to_status'])); ?></p>
                                        <?php if (!empty($entry['remarks'])): ?><p><?php echo nl2br(htmlspecialchars((string)$entry['remarks'])); ?></p><?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notice-card">No workflow history yet.</div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>
<script>
    (function () {
        var tableBody = document.getElementById('quotationItemsBody');
        var quotationForm = document.querySelector('form[action="/codesamplecaps/controllers/QuotationController.php"]');
        var projectField = document.getElementById('project_id');
        var durationField = document.getElementById('estimated_duration_days');
        var foremanField = document.getElementById('foreman_reviewer_id');
        var catalogTabs = document.querySelectorAll('[data-catalog-tab]');
        var catalogPanels = document.querySelectorAll('[data-catalog-panel]');

        if (!tableBody || !quotationForm) {
            return;
        }

        function currency(value) {
            return 'PHP ' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function syncProjectDuration() {
            if (!projectField || !durationField) {
                return;
            }

            var selectedOption = projectField.options[projectField.selectedIndex];
            var durationDays = selectedOption ? String(selectedOption.getAttribute('data-duration-days') || '').trim() : '';

            if (durationDays !== '') {
                durationField.value = durationDays;
                durationField.setCustomValidity('');
            } else {
                durationField.value = '';
                durationField.setCustomValidity('This project has no saved duration yet. Update the project timeline first.');
            }
        }

        function validateRow(row) {
            var type = row.querySelector('.item-type').value;
            var quantityField = row.querySelector('.item-quantity');
            var hoursField = row.querySelector('.item-hours');
            var rateField = row.querySelector('.item-rate');
            var itemNameField = row.querySelector('input[name="item_name[]"]');
            var isManpower = type === 'manpower';
            var quantity = parseFloat(quantityField.value || '0');
            var hours = parseFloat(hoursField.value || '0');
            var rate = parseFloat(rateField.value || '0');

            itemNameField.classList.toggle('is-invalid', itemNameField.value.trim().length < 2);
            rateField.classList.toggle('is-invalid', rate <= 0);

            if (isManpower) {
                quantityField.value = quantityField.value === '' ? '0' : quantityField.value;
                quantityField.setCustomValidity('');
                hoursField.setCustomValidity(hours > 0 ? '' : 'Manpower rows require hours greater than zero.');
                hoursField.classList.toggle('is-invalid', !(hours > 0));
                quantityField.classList.remove('is-invalid');
            } else {
                quantityField.setCustomValidity(quantity > 0 ? '' : 'Quantity must be greater than zero.');
                hoursField.setCustomValidity('');
                quantityField.classList.toggle('is-invalid', !(quantity > 0));
                hoursField.classList.remove('is-invalid');
            }
        }

        function calculateRow(row) {
            var type = row.querySelector('.item-type').value;
            var quantity = parseFloat(row.querySelector('.item-quantity').value || '0');
            var hours = parseFloat(row.querySelector('.item-hours').value || '0');
            var rate = parseFloat(row.querySelector('.item-rate').value || '0');
            validateRow(row);
            var total = type === 'manpower' ? hours * rate : quantity * rate;
            row.querySelector('.item-total').value = total.toFixed(2);
            return {type: type, total: total};
        }

        function recalcTotals() {
            var totals = {material: 0, asset: 0, manpower: 0, other: 0};
            tableBody.querySelectorAll('.quotation-item-row').forEach(function (row) {
                var rowData = calculateRow(row);
                totals[rowData.type] = (totals[rowData.type] || 0) + rowData.total;
            });
            var totalCost = totals.material + totals.asset + totals.manpower + totals.other;
            document.getElementById('materialsTotal').textContent = currency(totals.material);
            document.getElementById('assetsTotal').textContent = currency(totals.asset);
            document.getElementById('manpowerTotal').textContent = currency(totals.manpower);
            document.getElementById('otherTotal').textContent = currency(totals.other);
            document.getElementById('totalCost').textContent = currency(totalCost);
            document.getElementById('sellingPrice').textContent = currency(totalCost);
        }

        function buildRow(data) {
            var row = document.createElement('tr');
            var type = data.item_type || 'other';
            var defaultUnit = data.unit || (type === 'manpower' ? 'hour' : 'unit');
            var defaultQuantity = type === 'manpower' ? '0' : String(data.quantity || '1');
            var defaultHours = String(data.hours || '0');
            var defaultDescription = data.description || (type === 'manpower' ? 'Crew or work package labor entry' : '');
            row.className = 'quotation-item-row';
            row.innerHTML =
                '<td><select name="item_type[]" class="item-type"><option value="material">Material</option><option value="asset">Asset</option><option value="manpower">Manpower</option><option value="other">Other</option></select><input type="hidden" name="source_table[]" value=""><input type="hidden" name="source_id[]" value=""></td>' +
                '<td><input type="text" name="item_name[]" data-quote-validate="item-name" minlength="2" maxlength="160" required></td>' +
                '<td><input type="text" name="item_description[]" value="' + defaultDescription.replace(/"/g, '&quot;') + '"></td>' +
                '<td><input type="text" name="unit[]" value="' + defaultUnit.replace(/"/g, '&quot;') + '" required></td>' +
                '<td><input type="number" step="0.01" min="0.01" name="quantity[]" class="item-quantity" data-quote-validate="quantity" value="' + defaultQuantity + '"></td>' +
                '<td><input type="number" step="0.01" min="0" name="hours[]" class="item-hours" data-quote-validate="hours" value="' + defaultHours + '"></td>' +
                '<td><input type="number" step="0.01" min="0.01" name="rate[]" class="item-rate" data-quote-validate="rate" value="0"></td>' +
                '<td><input type="text" class="item-total" value="0.00" readonly></td>' +
                '<td><button type="button" class="btn-danger" data-remove-row>Remove</button></td>';
            row.querySelector('.item-type').value = type;
            row.querySelector('input[name="source_table[]"]').value = data.source_table || '';
            row.querySelector('input[name="source_id[]"]').value = data.source_id || '';
            row.querySelector('input[name="item_name[]"]').value = data.item_name || '';
            tableBody.appendChild(row);
            recalcTotals();
        }

        function activateCatalogTab(tabName) {
            catalogTabs.forEach(function (tab) {
                var isActive = tab.getAttribute('data-catalog-tab') === tabName;
                tab.classList.toggle('is-active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            catalogPanels.forEach(function (panel) {
                var isActive = panel.getAttribute('data-catalog-panel') === tabName;
                panel.classList.toggle('is-active', isActive);
                panel.hidden = !isActive;
            });
        }

        tableBody.addEventListener('input', recalcTotals);
        tableBody.addEventListener('change', recalcTotals);
        tableBody.addEventListener('click', function (event) {
            if (event.target.matches('[data-remove-row]')) {
                event.preventDefault();
                event.target.closest('tr').remove();
                recalcTotals();
            }
        });

        document.querySelectorAll('[data-add-row]').forEach(function (button) {
            button.addEventListener('click', function () {
                buildRow({item_type: button.getAttribute('data-add-row')});
            });
        });

        document.querySelectorAll('[data-catalog-item]').forEach(function (button) {
            button.addEventListener('click', function () {
                buildRow({
                    item_type: button.getAttribute('data-item-type'),
                    source_table: button.getAttribute('data-source-table'),
                    source_id: button.getAttribute('data-source-id'),
                    item_name: button.getAttribute('data-item-name'),
                    unit: button.getAttribute('data-unit')
                });
            });
        });

        catalogTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activateCatalogTab(tab.getAttribute('data-catalog-tab'));
            });
        });

        quotationForm.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            var actionValue = submitter ? submitter.value : '';
            var hasRow = tableBody.querySelectorAll('.quotation-item-row').length > 0;

            syncProjectDuration();
            recalcTotals();

            if (!hasRow) {
                event.preventDefault();
                window.alert('Add at least one quotation item first.');
                return;
            }

            if ((actionValue === 'submit_review' || actionValue === 'save_draft') && foremanField && !foremanField.value.trim()) {
                if (actionValue === 'submit_review') {
                    event.preventDefault();
                    foremanField.focus();
                    foremanField.setCustomValidity('Select a foreman reviewer before submitting for review.');
                    foremanField.reportValidity();
                    return;
                }
            }

            if (foremanField) {
                foremanField.setCustomValidity('');
            }
        });

        if (projectField) {
            projectField.addEventListener('change', syncProjectDuration);
        }

        tableBody.querySelectorAll('.quotation-item-row').forEach(function (row) {
            validateRow(row);
        });

        activateCatalogTab('materials');
        syncProjectDuration();
        recalcTotals();
    })();
</script>
<script src="../js/engineer.js"></script>
</body>
</html>
