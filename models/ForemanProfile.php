<?php

require_once __DIR__ . '/../config/database.php';

class ForemanProfile {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    public function create($userId, $specialization = null, $availabilityStatus = 'available', $maxProjects = 3) {
        $stmt = $this->conn->prepare("INSERT INTO foreman_profiles (user_id, specialization, availability_status, max_projects) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("issi", $userId, $specialization, $availabilityStatus, $maxProjects);
        return $stmt->execute();
    }

    public function updateAvailability($userId, $status) {
        $stmt = $this->conn->prepare("UPDATE foreman_profiles SET availability_status = ? WHERE user_id = ?");
        $stmt->bind_param("si", $status, $userId);
        return $stmt->execute();
    }
}
