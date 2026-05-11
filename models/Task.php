<?php

require_once __DIR__ . '/../config/database.php';

class Task {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    public function create($projectId, $assignedTo, $createdBy, $title, $description, $status = 'pending', $priority = 'medium', $deadline = null) {
        $stmt = $this->conn->prepare("INSERT INTO tasks (project_id, assigned_to, created_by, title, description, status, priority, deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisssss", $projectId, $assignedTo, $createdBy, $title, $description, $status, $priority, $deadline);
        return $stmt->execute();
    }

    public function getByProject($projectId) {
        $stmt = $this->conn->prepare("SELECT * FROM tasks WHERE project_id = ?");
        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function updateStatus($taskId, $status) {
        $stmt = $this->conn->prepare("UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $status, $taskId);
        return $stmt->execute();
    }
}
