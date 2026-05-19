<?php

require_once __DIR__ . '/../config/database.php';

class QuotationRepository
{
    private mysqli $conn;

    public function __construct(?mysqli $database = null)
    {
        global $conn;
        $this->conn = $database ?? $conn;
    }

    public function getConnection(): mysqli
    {
        return $this->conn;
    }

    public function createQuotation(array $payload): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO quotations (
                quotation_no, project_id, client_id, engineer_id, foreman_reviewer_id,
                title, scope_summary, currency_code, estimated_duration_days, manpower_hours,
                materials_cost, assets_cost, manpower_cost, other_cost, total_cost,
                profit_margin_percent, profit_amount, selling_price, status, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            'siiiisssidddddddddsii',
            $payload['quotation_no'],
            $payload['project_id'],
            $payload['client_id'],
            $payload['engineer_id'],
            $payload['foreman_reviewer_id'],
            $payload['title'],
            $payload['scope_summary'],
            $payload['currency_code'],
            $payload['estimated_duration_days'],
            $payload['manpower_hours'],
            $payload['materials_cost'],
            $payload['assets_cost'],
            $payload['manpower_cost'],
            $payload['other_cost'],
            $payload['total_cost'],
            $payload['profit_margin_percent'],
            $payload['profit_amount'],
            $payload['selling_price'],
            $payload['status'],
            $payload['created_by'],
            $payload['updated_by']
        );

        $stmt->execute();
        return (int)$this->conn->insert_id;
    }

    public function updateQuotationSummary(int $quotationId, array $payload): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE quotations
             SET title = ?,
                 scope_summary = ?,
                 foreman_reviewer_id = ?,
                 estimated_duration_days = ?,
                 manpower_hours = ?,
                 materials_cost = ?,
                 assets_cost = ?,
                 manpower_cost = ?,
                 other_cost = ?,
                 total_cost = ?,
                 profit_margin_percent = ?,
                 profit_amount = ?,
                 selling_price = ?,
                 updated_by = ?
             WHERE id = ?"
        );

        $stmt->bind_param(
            'ssiidddddddddii',
            $payload['title'],
            $payload['scope_summary'],
            $payload['foreman_reviewer_id'],
            $payload['estimated_duration_days'],
            $payload['manpower_hours'],
            $payload['materials_cost'],
            $payload['assets_cost'],
            $payload['manpower_cost'],
            $payload['other_cost'],
            $payload['total_cost'],
            $payload['profit_margin_percent'],
            $payload['profit_amount'],
            $payload['selling_price'],
            $payload['updated_by'],
            $quotationId
        );

        return $stmt->execute();
    }

    public function replaceItems(int $quotationId, array $items): void
    {
        $deleteStmt = $this->conn->prepare("DELETE FROM quotation_items WHERE quotation_id = ?");
        $deleteStmt->bind_param('i', $quotationId);
        $deleteStmt->execute();

        $insertStmt = $this->conn->prepare(
            "INSERT INTO quotation_items (
                quotation_id, item_type, source_table, source_id, item_name, description,
                unit, quantity, rate, hours, days, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($items as $index => $item) {
            $insertStmt->bind_param(
                'ississsdddddi',
                $quotationId,
                $item['item_type'],
                $item['source_table'],
                $item['source_id'],
                $item['item_name'],
                $item['description'],
                $item['unit'],
                $item['quantity'],
                $item['rate'],
                $item['hours'],
                $item['days'],
                $item['line_total'],
                $index
            );
            $insertStmt->execute();
        }
    }

    public function findQuotationById(int $quotationId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT q.*, p.project_name, u.full_name AS client_name
             FROM quotations q
             INNER JOIN projects p ON p.id = q.project_id
             INNER JOIN users u ON u.id = q.client_id
             WHERE q.id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $quotationId);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? ($result->fetch_assoc() ?: null) : null;
    }

    public function getQuotationItems(int $quotationId): array
    {
        $stmt = $this->conn->prepare(
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

    public function updateStatus(
        int $quotationId,
        string $status,
        int $actedBy,
        ?string $approvedAt = null,
        ?string $sentAt = null,
        int $lockFlag = 0,
        ?string $lockedAt = null,
        ?int $lockedBy = null
    ): bool {
        $stmt = $this->conn->prepare(
            "UPDATE quotations
             SET status = ?,
                 approved_by = CASE WHEN ? IS NOT NULL THEN ? ELSE approved_by END,
                 sent_by = CASE WHEN ? IS NOT NULL THEN ? ELSE sent_by END,
                 approved_at = COALESCE(?, approved_at),
                 sent_at = COALESCE(?, sent_at),
                 is_locked = ?,
                 locked_at = ?,
                 locked_by = ?,
                 updated_by = ?
             WHERE id = ?"
        );

        $approvedBy = $approvedAt !== null ? $actedBy : null;
        $sentBy = $sentAt !== null ? $actedBy : null;
        $stmt->bind_param(
            'siiiissisiii',
            $status,
            $approvedBy,
            $approvedBy,
            $sentBy,
            $sentBy,
            $approvedAt,
            $sentAt,
            $lockFlag,
            $lockedAt,
            $lockedBy,
            $actedBy,
            $quotationId
        );

        return $stmt->execute();
    }

    public function setClientResponse(int $quotationId, string $status, string $note, int $clientId): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE quotations
             SET status = ?,
                 client_response_at = NOW(),
                 client_response_note = ?,
                 updated_by = ?
             WHERE id = ?"
        );
        $stmt->bind_param('ssii', $status, $note, $clientId, $quotationId);
        return $stmt->execute();
    }

    public function addReview(array $payload): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO quotation_reviews (
                quotation_id, reviewer_id, reviewer_role, review_type, message, is_internal
            ) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iisssi',
            $payload['quotation_id'],
            $payload['reviewer_id'],
            $payload['reviewer_role'],
            $payload['review_type'],
            $payload['message'],
            $payload['is_internal']
        );
        return $stmt->execute();
    }

    public function addStatusHistory(array $payload): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO quotation_status_history (
                quotation_id, from_status, to_status, acted_by, actor_role, remarks
            ) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'ississ',
            $payload['quotation_id'],
            $payload['from_status'],
            $payload['to_status'],
            $payload['acted_by'],
            $payload['actor_role'],
            $payload['remarks']
        );
        return $stmt->execute();
    }

    public function syncBudgetBreakdown(int $projectId, int $quotationId, array $breakdowns, int $createdBy): void
    {
        $deleteStmt = $this->conn->prepare(
            "DELETE FROM project_budget_breakdowns WHERE project_id = ? AND quotation_id = ?"
        );
        $deleteStmt->bind_param('ii', $projectId, $quotationId);
        $deleteStmt->execute();

        $insertStmt = $this->conn->prepare(
            "INSERT INTO project_budget_breakdowns (
                project_id, quotation_id, budget_category, amount, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($breakdowns as $breakdown) {
            $insertStmt->bind_param(
                'iisdsi',
                $projectId,
                $quotationId,
                $breakdown['budget_category'],
                $breakdown['amount'],
                $breakdown['notes'],
                $createdBy
            );
            $insertStmt->execute();
        }
    }
}
