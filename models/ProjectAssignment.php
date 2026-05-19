<?php

require_once __DIR__ . '/../config/database.php';

class ProjectAssignment {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    public function assign($projectId, $userId, $roleInProject) {
        $stmt = $this->conn->prepare("INSERT INTO project_assignments (project_id, user_id, role_in_project) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $projectId, $userId, $roleInProject);
        return $stmt->execute();
    }

    public function getByUser($userId) {
        $stmt = $this->conn->prepare("SELECT * FROM project_assignments WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result();
    }
}
