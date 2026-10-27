CREATE TABLE IF NOT EXISTS `site_inspection_costing_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `engineer_findings` text DEFAULT NULL,
  `risk_notes` text DEFAULT NULL,
  `client_requests` text DEFAULT NULL,
  `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `review_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `admin_remarks` text DEFAULT NULL,
  `submitted_by` int(11) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_costing_version` (`inspection_id`, `version_no`),
  KEY `idx_costing_version_review` (`inspection_id`, `review_status`),
  CONSTRAINT `fk_costing_version_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `site_inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `site_inspection_costing_version_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `costing_version_id` int(11) NOT NULL,
  `item_type` varchar(30) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `item_name` varchar(180) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit` varchar(30) NOT NULL DEFAULT 'unit',
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_costing_version_items_version` (`costing_version_id`),
  CONSTRAINT `fk_costing_version_items_version` FOREIGN KEY (`costing_version_id`) REFERENCES `site_inspection_costing_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
