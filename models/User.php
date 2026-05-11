<?php

require_once __DIR__ . '/../config/database.php';

class User {
    private $conn;

    public function __construct($db = null) {
        if ($db) {
            $this->conn = $db;
        } else {
            global $conn;
            $this->conn = $conn;
        }
    }

    public function findByEmail($email) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function create($fullName, $email, $passwordHash, $role, $phone = null) {
        $stmt = $this->conn->prepare("INSERT INTO users (full_name, email, password, role, phone) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $fullName, $email, $passwordHash, $role, $phone);
        return $stmt->execute();
    }

    public function countByRole($role) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $stmt->close();
        return $cnt;
    }

    // additional methods: activate, deactivate, updatePassword, etc.
}
