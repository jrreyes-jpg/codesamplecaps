<?php
require_once __DIR__ . '/../config/database.php';

class AssetLoss {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    /**
     * Mark an asset as lost and log it
     *
     * @param int $assetId - ID ng nawawalang asset
     * @param string $reportedBy - Sino ang nag-report
     * @param string|null $notes - Optional na notes
     * @return bool - success/fail
     */
    public function reportLoss($assetId, $reportedBy, $notes = null) {
        $this->conn->begin_transaction();

        try {
            // 1️⃣ Bawasan quantity at mark as 'lost'
            $stmt1 = $this->conn->prepare("UPDATE assets SET quantity = quantity - 1, asset_status = 'lost' WHERE id = ?");
            $stmt1->bind_param("i", $assetId);
            $stmt1->execute();

            // 2️⃣ I-log sa asset_loss_logs
            $stmt2 = $this->conn->prepare("INSERT INTO asset_loss_logs (asset_id, reported_by, notes) VALUES (?, ?, ?)");
            $stmt2->bind_param("iss", $assetId, $reportedBy, $notes);
            $stmt2->execute();

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }
}