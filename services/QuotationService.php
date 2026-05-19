<?php

require_once __DIR__ . '/../config/quotation_access.php';
require_once __DIR__ . '/../config/audit_log.php';
require_once __DIR__ . '/../repositories/QuotationRepository.php';

class QuotationService
{
    private QuotationRepository $quotationRepository;
    private mysqli $conn;

    public function __construct(?QuotationRepository $quotationRepository = null)
    {
        $this->quotationRepository = $quotationRepository ?? new QuotationRepository();
        $this->conn = $this->quotationRepository->getConnection();
    }

    public function computeTotals(array $items, float $profitMarginPercent): array
    {
        $bucketTotals = [
            'materials_cost' => 0.0,
            'assets_cost' => 0.0,
            'manpower_cost' => 0.0,
            'other_cost' => 0.0,
            'manpower_hours' => 0.0,
        ];

        foreach ($items as $item) {
            $normalizedItem = $this->normalizeItem($item);

            if ($normalizedItem['item_type'] === 'manpower') {
                $lineTotal = $normalizedItem['hours'] * $normalizedItem['rate'];
                $bucketTotals['manpower_hours'] += $normalizedItem['hours'];
            } else {
                $lineTotal = $normalizedItem['quantity'] * $normalizedItem['rate'];
            }

            $lineTotal = round($lineTotal, 2);

            if ($normalizedItem['item_type'] === 'material') {
                $bucketTotals['materials_cost'] += $lineTotal;
            } elseif ($normalizedItem['item_type'] === 'asset') {
                $bucketTotals['assets_cost'] += $lineTotal;
            } elseif ($normalizedItem['item_type'] === 'manpower') {
                $bucketTotals['manpower_cost'] += $lineTotal;
            } else {
                $bucketTotals['other_cost'] += $lineTotal;
            }
        }

        $totalCost = round(
            $bucketTotals['materials_cost']
            + $bucketTotals['assets_cost']
            + $bucketTotals['manpower_cost']
            + $bucketTotals['other_cost'],
            2
        );

        $profitAmount = round($totalCost * ($profitMarginPercent / 100), 2);
        $sellingPrice = round($totalCost + $profitAmount, 2);

        return $bucketTotals + [
            'total_cost' => $totalCost,
            'profit_margin_percent' => round($profitMarginPercent, 2),
            'profit_amount' => $profitAmount,
            'selling_price' => $sellingPrice,
        ];
    }

    public function createDraft(array $quotationData, array $items, int $actorId, string $actorRole): int
    {
        if (!quotation_user_can('create', $actorRole)) {
            throw new RuntimeException('Only engineers can create quotation drafts.');
        }

        $totals = $this->computeTotals($items, (float)($quotationData['profit_margin_percent'] ?? 0));
        $payload = array_merge($quotationData, $totals, [
            'quotation_no' => $quotationData['quotation_no'] ?? $this->generateQuotationNumber(),
            'currency_code' => $quotationData['currency_code'] ?? 'PHP',
            'status' => 'draft',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $normalizedItems = array_map(fn(array $item): array => $this->normalizeItem($item), $items);

        $this->conn->begin_transaction();

        try {
            $quotationId = $this->quotationRepository->createQuotation($payload);
            $this->quotationRepository->replaceItems($quotationId, $normalizedItems);
            $this->quotationRepository->addStatusHistory([
                'quotation_id' => $quotationId,
                'from_status' => null,
                'to_status' => 'draft',
                'acted_by' => $actorId,
                'actor_role' => $actorRole,
                'remarks' => 'Quotation draft created.',
            ]);
            $this->syncProjectBudgetFromTotals($payload['project_id'], $quotationId, $totals, $actorId);
            audit_log_event($this->conn, $actorId, 'create_quotation_draft', 'quotation', $quotationId, null, [
                'project_id' => $payload['project_id'],
                'selling_price' => $payload['selling_price'],
                'status' => 'draft',
            ]);
            $this->conn->commit();

            return $quotationId;
        } catch (Throwable $throwable) {
            $this->conn->rollback();
            throw $throwable;
        }
    }

    public function updateDraft(int $quotationId, array $quotationData, array $items, int $actorId, string $actorRole): bool
    {
        $quotation = $this->requireQuotation($quotationId);

        if (!quotation_user_can('edit_draft', $actorRole) || $quotation['status'] !== 'draft' || (int)$quotation['is_locked'] === 1) {
            throw new RuntimeException('Only unlocked draft quotations can be edited by engineers.');
        }

        $totals = $this->computeTotals($items, (float)($quotationData['profit_margin_percent'] ?? 0));
        $payload = array_merge($quotationData, $totals, [
            'updated_by' => $actorId,
        ]);

        $normalizedItems = array_map(fn(array $item): array => $this->normalizeItem($item), $items);

        $this->conn->begin_transaction();

        try {
            $updated = $this->quotationRepository->updateQuotationSummary($quotationId, $payload);
            $this->quotationRepository->replaceItems($quotationId, $normalizedItems);
            $this->syncProjectBudgetFromTotals((int)$quotation['project_id'], $quotationId, $totals, $actorId);
            audit_log_event($this->conn, $actorId, 'update_quotation_draft', 'quotation', $quotationId, [
                'status' => $quotation['status'],
            ], [
                'selling_price' => $payload['selling_price'],
                'profit_margin_percent' => $payload['profit_margin_percent'],
            ]);
            $this->conn->commit();

            return $updated;
        } catch (Throwable $throwable) {
            $this->conn->rollback();
            throw $throwable;
        }
    }

    public function submitForReview(int $quotationId, int $actorId, string $actorRole, string $remarks = ''): bool
    {
        $quotation = $this->requireQuotation($quotationId);

        if (!quotation_user_can('submit_for_review', $actorRole)) {
            throw new RuntimeException('Only engineers can submit quotation drafts for review.');
        }

        return $this->transitionQuotation($quotation, 'under_review', $actorId, $actorRole, $remarks ?: 'Submitted for foreman review.');
    }

    public function submitForApproval(int $quotationId, int $actorId, string $actorRole, string $remarks = ''): bool
    {
        $quotation = $this->requireQuotation($quotationId);

        if (!quotation_user_can('submit_for_approval', $actorRole)) {
            throw new RuntimeException('Only engineers can submit quotations for super admin approval.');
        }

        if ($quotation['status'] !== 'under_review') {
            throw new RuntimeException('Only reviewed quotations can be submitted for approval.');
        }

        return $this->transitionQuotation(
            $quotation,
            'for_approval',
            $actorId,
            $actorRole,
            $remarks !== '' ? $remarks : 'Engineer submitted quotation for super admin approval.'
        );
    }

    public function addForemanReview(int $quotationId, int $actorId, string $actorRole, string $message, bool $returnToDraft = false): bool
    {
        $quotation = $this->requireQuotation($quotationId);

        if (!quotation_user_can('review', $actorRole)) {
            throw new RuntimeException('Only foreman and super admin users can review quotations.');
        }

        $this->conn->begin_transaction();

        try {
            $this->quotationRepository->addReview([
                'quotation_id' => $quotationId,
                'reviewer_id' => $actorId,
                'reviewer_role' => $actorRole,
                'review_type' => 'suggestion',
                'message' => $message,
                'is_internal' => 1,
            ]);

            if ($returnToDraft) {
                $this->transitionQuotationInternal($quotation, 'draft', $actorId, $actorRole, 'Returned to engineer with foreman suggestions.');
            }

            audit_log_event($this->conn, $actorId, 'review_quotation', 'quotation', $quotationId, null, [
                'reviewer_role' => $actorRole,
                'return_to_draft' => $returnToDraft,
            ]);
            $this->conn->commit();

            return true;
        } catch (Throwable $throwable) {
            $this->conn->rollback();
            throw $throwable;
        }
    }

    public function approveQuotation(int $quotationId, float $profitMarginPercent, int $actorId, string $actorRole, string $remarks = ''): bool
    {
        $quotation = $this->requireQuotation($quotationId);

        if (!quotation_user_can('approve', $actorRole)) {
            throw new RuntimeException('Only super admin can approve quotations.');
        }

        $items = $this->quotationRepository->getQuotationItems($quotationId);
        $totals = $this->computeTotals($items, $profitMarginPercent);

        $this->conn->begin_transaction();

        try {
            $this->quotationRepository->updateQuotationSummary($quotationId, [
                'title' => $quotation['title'],
                'scope_summary' => $quotation['scope_summary'],
                'foreman_reviewer_id' => $quotation['foreman_reviewer_id'],
                'estimated_duration_days' => $quotation['estimated_duration_days'],
                'manpower_hours' => $totals['manpower_hours'],
                'materials_cost' => $totals['materials_cost'],
                'assets_cost' => $totals['assets_cost'],
                'manpower_cost' => $totals['manpower_cost'],
                'other_cost' => $totals['other_cost'],
                'total_cost' => $totals['total_cost'],
                'profit_margin_percent' => $totals['profit_margin_percent'],
                'profit_amount' => $totals['profit_amount'],
                'selling_price' => $totals['selling_price'],
                'updated_by' => $actorId,
            ]);

            $approvedAt = date('Y-m-d H:i:s');
            $this->quotationRepository->updateStatus(
                $quotationId,
                'approved',
                $actorId,
                $approvedAt,
                null,
                1,
                $approvedAt,
                $actorId
            );
            $this->quotationRepository->addReview([
                'quotation_id' => $quotationId,
                'reviewer_id' => $actorId,
                'reviewer_role' => $actorRole,
                'review_type' => 'approval_note',
                'message' => $remarks !== '' ? $remarks : 'Approved and locked for client release.',
                'is_internal' => 1,
            ]);
            $this->quotationRepository->addStatusHistory([
                'quotation_id' => $quotationId,
                'from_status' => $quotation['status'],
                'to_status' => 'approved',
                'acted_by' => $actorId,
                'actor_role' => $actorRole,
                'remarks' => $remarks,
            ]);
            $this->syncProjectBudgetFromTotals((int)$quotation['project_id'], $quotationId, $totals, $actorId);
            audit_log_event($this->conn, $actorId, 'approve_quotation', 'quotation', $quotationId, [
                'status' => $quotation['status'],
            ], [
                'status' => 'approved',
                'selling_price' => $totals['selling_price'],
            ]);
            $this->conn->commit();

            return true;
        } catch (Throwable $throwable) {
            $this->conn->rollback();
            throw $throwable;
        }
    }

    public function sendToClient(int $quotationId, int $actorId, string $actorRole, string $remarks = ''): bool
    {
        $quotation = $this->requireQuotation($quotationId);

        if (!quotation_user_can('send_to_client', $actorRole)) {
            throw new RuntimeException('Only super admin can send quotations to the client.');
        }

        return $this->transitionQuotation(
            $quotation,
            'sent',
            $actorId,
            $actorRole,
            $remarks !== '' ? $remarks : 'Quotation sent to client.'
        );
    }

    public function respondAsClient(int $quotationId, bool $accepted, string $note, int $actorId, string $actorRole): bool
    {
        $quotation = $this->requireQuotation($quotationId);

        if (!quotation_user_can('respond', $actorRole)) {
            throw new RuntimeException('Only clients can respond to quotations.');
        }

        if ($quotation['status'] !== 'sent') {
            throw new RuntimeException('Client response is only allowed after the quotation is sent.');
        }

        $targetStatus = $accepted ? 'accepted' : 'rejected';

        $this->conn->begin_transaction();

        try {
            $this->quotationRepository->setClientResponse($quotationId, $targetStatus, $note, $actorId);
            $this->quotationRepository->addReview([
                'quotation_id' => $quotationId,
                'reviewer_id' => $actorId,
                'reviewer_role' => $actorRole,
                'review_type' => 'client_response',
                'message' => $note !== '' ? $note : ($accepted ? 'Client accepted quotation.' : 'Client rejected quotation.'),
                'is_internal' => 0,
            ]);
            $this->quotationRepository->addStatusHistory([
                'quotation_id' => $quotationId,
                'from_status' => $quotation['status'],
                'to_status' => $targetStatus,
                'acted_by' => $actorId,
                'actor_role' => $actorRole,
                'remarks' => $note,
            ]);
            audit_log_event($this->conn, $actorId, 'client_respond_quotation', 'quotation', $quotationId, [
                'status' => $quotation['status'],
            ], [
                'status' => $targetStatus,
            ]);
            $this->conn->commit();

            return true;
        } catch (Throwable $throwable) {
            $this->conn->rollback();
            throw $throwable;
        }
    }

    private function transitionQuotation(array $quotation, string $toStatus, int $actorId, string $actorRole, string $remarks): bool
    {
        $this->conn->begin_transaction();

        try {
            $result = $this->transitionQuotationInternal($quotation, $toStatus, $actorId, $actorRole, $remarks);
            audit_log_event($this->conn, $actorId, 'transition_quotation', 'quotation', (int)$quotation['id'], [
                'status' => $quotation['status'],
            ], [
                'status' => $toStatus,
            ]);
            $this->conn->commit();

            return $result;
        } catch (Throwable $throwable) {
            $this->conn->rollback();
            throw $throwable;
        }
    }

    private function transitionQuotationInternal(array $quotation, string $toStatus, int $actorId, string $actorRole, string $remarks): bool
    {
        $fromStatus = (string)$quotation['status'];

        if (!quotation_transition_is_allowed($fromStatus, $toStatus)) {
            throw new RuntimeException("Quotation cannot move from {$fromStatus} to {$toStatus}.");
        }

        $sentAt = $toStatus === 'sent' ? date('Y-m-d H:i:s') : null;
        $result = $this->quotationRepository->updateStatus(
            (int)$quotation['id'],
            $toStatus,
            $actorId,
            null,
            $sentAt,
            (int)$quotation['is_locked'],
            $quotation['locked_at'],
            $quotation['locked_by'] !== null ? (int)$quotation['locked_by'] : null
        );

        $this->quotationRepository->addStatusHistory([
            'quotation_id' => (int)$quotation['id'],
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'acted_by' => $actorId,
            'actor_role' => $actorRole,
            'remarks' => $remarks,
        ]);

        return $result;
    }

    private function requireQuotation(int $quotationId): array
    {
        $quotation = $this->quotationRepository->findQuotationById($quotationId);
        if ($quotation === null) {
            throw new RuntimeException('Quotation record not found.');
        }

        return $quotation;
    }

    private function normalizeItem(array $item): array
    {
        $itemType = (string)($item['item_type'] ?? 'other');
        $quantity = round((float)($item['quantity'] ?? 0), 2);
        $rate = round((float)($item['rate'] ?? 0), 2);
        $hours = round((float)($item['hours'] ?? 0), 2);
        $days = round((float)($item['days'] ?? 0), 2);

        $lineTotal = $itemType === 'manpower'
            ? round($hours * $rate, 2)
            : round($quantity * $rate, 2);

        return [
            'item_type' => $itemType,
            'source_table' => $item['source_table'] ?? null,
            'source_id' => isset($item['source_id']) ? (int)$item['source_id'] : null,
            'item_name' => trim((string)($item['item_name'] ?? '')),
            'description' => trim((string)($item['description'] ?? '')),
            'unit' => trim((string)($item['unit'] ?? 'unit')),
            'quantity' => $quantity,
            'rate' => $rate,
            'hours' => $hours,
            'days' => $days,
            'line_total' => $lineTotal,
        ];
    }

    private function syncProjectBudgetFromTotals(int $projectId, int $quotationId, array $totals, int $actorId): void
    {
        $breakdowns = [
            ['budget_category' => 'materials', 'amount' => $totals['materials_cost'], 'notes' => 'Quotation materials subtotal'],
            ['budget_category' => 'assets', 'amount' => $totals['assets_cost'], 'notes' => 'Quotation asset usage subtotal'],
            ['budget_category' => 'manpower', 'amount' => $totals['manpower_cost'], 'notes' => 'Quotation manpower subtotal'],
            ['budget_category' => 'other', 'amount' => $totals['other_cost'], 'notes' => 'Quotation other subtotal'],
            ['budget_category' => 'profit', 'amount' => $totals['profit_amount'], 'notes' => 'Super admin-approved quotation margin'],
        ];

        $this->quotationRepository->syncBudgetBreakdown($projectId, $quotationId, $breakdowns, $actorId);
    }

    private function generateQuotationNumber(): string
    {
        return 'QT-' . date('Ymd-His') . '-' . random_int(100, 999);
    }
}
