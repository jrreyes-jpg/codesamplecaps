<?php

require_once __DIR__ . '/../config/database.php';

class ProjectComment {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    public function create($projectId, $userId, $comment) {
        $stmt = $this->conn->prepare("INSERT INTO project_comments (project_id, user_id, comment) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $projectId, $userId, $comment);
        return $stmt->execute();
    }

    public function getByProject($projectId) {
        $stmt = $this->conn->prepare("SELECT pc.*, u.full_name FROM project_comments pc JOIN users u ON pc.user_id = u.id WHERE pc.project_id = ? ORDER BY pc.created_at DESC");
        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        return $stmt->get_result();
    }
}