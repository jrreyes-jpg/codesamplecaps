<?php
require_once __DIR__ . '/../config/database.php';

class Project {
    private $conn;

    public function __construct($db = null) {
        $this->conn = $db ?? $GLOBALS['conn'];
    }

    // Create project
    public function createProject($name, $clientId, $description = null, $startDate = null, $endDate = null, $status = 'pending', $createdBy) {
        $stmt = $this->conn->prepare(
            "INSERT INTO projects (project_name, client_id, description, start_date, end_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sissssi", $name, $clientId, $description, $startDate, $endDate, $status, $createdBy);
        return $stmt->execute();
    }

    // Update project
    public function updateProject($id, $name, $clientId, $description = null, $startDate = null, $endDate = null, $status = 'pending') {
        $stmt = $this->conn->prepare(
            "UPDATE projects SET project_name = ?, client_id = ?, description = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?"
        );
        $stmt->bind_param("sissssi", $name, $clientId, $description, $startDate, $endDate, $status, $id);
        return $stmt->execute();
    }

    // Get project
    public function getProject($id) {
        $stmt = $this->conn->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    // Assign engineer/worker
    public function assignUser($projectId, $engineerId, $assignedBy) {
        $stmt = $this->conn->prepare(
            "INSERT INTO project_assignments (project_id, engineer_id, assigned_by) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("iii", $projectId, $engineerId, $assignedBy);
        return $stmt->execute();
    }

    // Get users assigned to project
    public function getProjectUsers($projectId) {
        $stmt = $this->conn->prepare(
            "SELECT pa.*, u.username, u.full_name 
             FROM project_assignments pa 
             JOIN users u ON pa.engineer_id = u.id 
             WHERE pa.project_id = ?"
        );
        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        return $stmt->get_result();
    }

    // Change project status
    public function changeStatus($projectId, $status) {
        $stmt = $this->conn->prepare("UPDATE projects SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $projectId);
        return $stmt->execute();
    }
}
?>