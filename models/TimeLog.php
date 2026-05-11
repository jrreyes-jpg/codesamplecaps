<?php

require_once __DIR__ . '/../config/database.php';

class TimeLog {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    public function log($taskId, $userId, $hours, $description = null) {
        $stmt = $this->conn->prepare("INSERT INTO time_logs (task_id, user_id, hours, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $taskId, $userId, $hours, $description);
        return $stmt->execute();
    }

    public function getByTask($taskId) {
        $stmt = $this->conn->prepare("SELECT tl.*, u.full_name FROM time_logs tl JOIN users u ON tl.user_id = u.id WHERE tl.task_id = ? ORDER BY tl.logged_at DESC");
        $stmt->bind_param("i", $taskId);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getByUser($userId, $startDate = null, $endDate = null) {
        $query = "SELECT tl.*, t.task_name, p.project_name FROM time_logs tl JOIN tasks t ON tl.task_id = t.id JOIN projects p ON t.project_id = p.id WHERE tl.user_id = ?";
        $params = [$userId];
        $types = "i";
        if ($startDate && $endDate) {
            $query .= " AND tl.logged_at BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
            $types .= "ss";
        }
        $query .= " ORDER BY tl.logged_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }
}