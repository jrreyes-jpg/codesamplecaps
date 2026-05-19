<?php

require_once __DIR__ . '/../config/database.php';

class AssetProjectLink {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    public function link($assetId, $projectId, $notes = null) {
        $stmt = $this->conn->prepare("INSERT INTO asset_project_links (asset_id, project_id, notes) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $assetId, $projectId, $notes);
        return $stmt->execute();
    }

    public function unlink($assetId, $projectId) {
        $stmt = $this->conn->prepare("UPDATE asset_project_links SET unlinked_at = NOW() WHERE asset_id = ? AND project_id = ? AND unlinked_at IS NULL");
        $stmt->bind_param("ii", $assetId, $projectId);
        return $stmt->execute();
    }

    public function getByProject($projectId) {
        $stmt = $this->conn->prepare("SELECT apl.*, a.asset_name, a.asset_type FROM asset_project_links apl JOIN assets a ON apl.asset_id = a.id WHERE apl.project_id = ? AND apl.unlinked_at IS NULL");
        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getByAsset($assetId) {
        $stmt = $this->conn->prepare("SELECT apl.*, p.project_name FROM asset_project_links apl JOIN projects p ON apl.project_id = p.id WHERE apl.asset_id = ? AND apl.unlinked_at IS NULL");
        $stmt->bind_param("i", $assetId);
        $stmt->execute();
        return $stmt->get_result();
    }
}