<?php

require_once __DIR__ . '/../config/database.php';

class ProjectMilestone {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    public function create($projectId, $name, $description, $dueDate) {
        $stmt = $this->conn->prepare("INSERT INTO project_milestones (project_id, milestone_name, description, due_date) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $projectId, $name, $description, $dueDate);
        return $stmt->execute();
    }

    public function getByProject($projectId) {
        $stmt = $this->conn->prepare("SELECT * FROM project_milestones WHERE project_id = ? ORDER BY due_date ASC");
        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function updateStatus($id, $status) {
        $stmt = $this->conn->prepare("UPDATE project_milestones SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        return $stmt->execute();
    }
}