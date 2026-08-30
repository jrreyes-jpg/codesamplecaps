-- Canonical schema for a fresh local installation.
-- Schema only: no accounts, personal data, passwords, or reset tokens.
-- Generated from the verified local development schema on 2026-08-30.


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_category_defaults` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_key` varchar(80) NOT NULL,
  `category_label` varchar(120) NOT NULL,
  `recommended_min_stock` int(11) NOT NULL DEFAULT 0,
  `recommended_max_stock` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_key` (`category_key`),
  KEY `idx_asset_category_defaults_active_sort` (`is_active`,`sort_order`,`category_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_loss_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `reported_by` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `lost_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `asset_loss_logs_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_qr_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `qr_code_value` varchar(512) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asset` (`asset_id`),
  CONSTRAINT `fk_asset_qr_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_scan_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `asset_unit_id` int(11) DEFAULT NULL,
  `foreman_id` int(11) NOT NULL,
  `scan_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `scan_device` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_foreman` (`foreman_id`),
  KEY `idx_scan_time` (`scan_time`),
  KEY `idx_asset_scan_history_unit` (`asset_unit_id`),
  CONSTRAINT `fk_asset_scan_history_unit` FOREIGN KEY (`asset_unit_id`) REFERENCES `asset_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `unit_number` int(11) NOT NULL,
  `unit_code` varchar(190) NOT NULL,
  `qr_code_value` varchar(512) NOT NULL,
  `status` enum('available','deployed','archived','maintenance','lost') NOT NULL DEFAULT 'available',
  `archived_at` datetime DEFAULT NULL,
  `last_scanned_at` datetime DEFAULT NULL,
  `last_scanned_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_asset_units_code` (`unit_code`),
  UNIQUE KEY `uniq_asset_units_qr` (`qr_code_value`),
  UNIQUE KEY `uniq_asset_units_inventory_number` (`inventory_id`,`unit_number`),
  KEY `idx_asset_units_asset` (`asset_id`),
  KEY `idx_asset_units_inventory` (`inventory_id`),
  KEY `idx_asset_units_status` (`status`),
  KEY `fk_asset_units_last_scanned_by` (`last_scanned_by`),
  CONSTRAINT `fk_asset_units_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_units_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_units_last_scanned_by` FOREIGN KEY (`last_scanned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_usage_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `asset_unit_id` int(11) DEFAULT NULL,
  `foreman_id` int(11) NOT NULL,
  `worker_name` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `used_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_foreman` (`foreman_id`),
  KEY `idx_used_at` (`used_at`),
  KEY `idx_asset_usage_logs_unit` (`asset_unit_id`),
  CONSTRAINT `fk_asset_usage_logs_unit` FOREIGN KEY (`asset_unit_id`) REFERENCES `asset_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_name` varchar(255) NOT NULL,
  `asset_category` varchar(80) DEFAULT NULL,
  `asset_type` varchar(150) DEFAULT NULL,
  `serial_number` varchar(150) DEFAULT NULL,
  `asset_status` enum('available','maintenance','damaged','in_use','lost') NOT NULL DEFAULT 'available',
  `criticality` varchar(30) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `quantity` int(11) DEFAULT 0,
  `status` enum('available','maintenance','damaged','in_use','lost') DEFAULT 'available',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`asset_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deleted_users_archive` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_user_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payload_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deleted_users_archive_original` (`original_user_id`),
  KEY `idx_deleted_users_archive_deleted_by` (`deleted_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `engineer_task_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `engineer_id` int(11) NOT NULL,
  `status_snapshot` varchar(50) DEFAULT NULL,
  `progress_note` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_engineer_task_updates_task` (`task_id`),
  KEY `idx_engineer_task_updates_engineer` (`engineer_id`),
  KEY `idx_engineer_task_updates_created_at` (`created_at`),
  CONSTRAINT `fk_engineer_task_updates_engineer` FOREIGN KEY (`engineer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_engineer_task_updates_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `foreman_manual_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `foreman_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `report_date` date NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'submitted',
  `notes` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_foreman_manual_reports_foreman` (`foreman_id`),
  KEY `idx_foreman_manual_reports_project` (`project_id`),
  KEY `idx_foreman_manual_reports_date` (`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inquiry_quotation_drafts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inquiry_id` int(11) NOT NULL,
  `inspection_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `quotation_no` varchar(80) NOT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'Draft',
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `profit_margin_percent` decimal(7,2) NOT NULL DEFAULT 0.00,
  `profit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `sent_to_client_id` int(11) DEFAULT NULL,
  `sent_to_name` varchar(190) DEFAULT NULL,
  `sent_to_email` varchar(190) DEFAULT NULL,
  `sent_to_contact` varchar(40) DEFAULT NULL,
  `recipient_source` varchar(40) DEFAULT NULL,
  `public_access_token_hash` varchar(255) DEFAULT NULL,
  `public_token_expires_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `client_decision_note` text DEFAULT NULL,
  `client_decision_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inquiry_quote_inspection` (`inspection_id`),
  KEY `idx_inquiry_quote_inquiry` (`inquiry_id`),
  KEY `idx_inquiry_quote_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inquiry_quotation_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `draft_id` int(11) NOT NULL,
  `item_type` varchar(30) NOT NULL,
  `item_name` varchar(180) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit` varchar(30) NOT NULL DEFAULT 'unit',
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inquiry_quote_items_draft` (`draft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inquiry_quotation_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `draft_id` int(11) NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) NOT NULL,
  `note` text DEFAULT NULL,
  `actor_id` int(11) NOT NULL,
  `actor_role` varchar(40) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inquiry_quote_history_draft` (`draft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `min_stock` int(11) DEFAULT NULL,
  `max_stock` int(11) DEFAULT NULL,
  `status` enum('available','low-stock','out-of-stock') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `asset_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_stock_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` int(11) NOT NULL,
  `movement_type` enum('stock_in','stock_out') NOT NULL,
  `quantity` int(11) NOT NULL,
  `previous_quantity` int(11) NOT NULL,
  `new_quantity` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_stock_movements_inventory` (`inventory_id`),
  KEY `idx_inventory_stock_movements_type` (`movement_type`),
  KEY `idx_inventory_stock_movements_created_at` (`created_at`),
  KEY `idx_inventory_stock_movements_user` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `attempts` int(11) DEFAULT 0,
  `last_attempt` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email_hash` char(64) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reset_attempt_ip_time` (`ip_address`,`attempted_at`),
  KEY `idx_reset_attempt_email_time` (`email_hash`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_token` (`token`),
  CONSTRAINT `fk_prt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pending_service_inquiries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(80) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `payload_json` longtext NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_pending_inquiry_token` (`token`),
  KEY `idx_pending_inquiry_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_asset_deployments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `deployed_by` int(11) NOT NULL,
  `deployed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `returned_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_project_asset_deployments_project` (`project_id`),
  KEY `idx_project_asset_deployments_asset` (`asset_id`),
  KEY `idx_project_asset_deployments_returned` (`returned_at`),
  KEY `fk_project_asset_deployments_user` (`deployed_by`),
  CONSTRAINT `fk_project_asset_deployments_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_asset_deployments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_asset_deployments_user` FOREIGN KEY (`deployed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `engineer_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`project_id`,`engineer_id`),
  KEY `idx_engineer` (`engineer_id`),
  KEY `fk_assignment_assigner` (`assigned_by`),
  KEY `idx_project_assignments_project_latest` (`project_id`,`id`,`engineer_id`),
  CONSTRAINT `fk_assignment_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assignment_engineer` FOREIGN KEY (`engineer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_budget_breakdowns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `quotation_id` int(11) DEFAULT NULL,
  `budget_category` enum('materials','assets','manpower','other','contingency','profit') NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_budget_breakdowns_project` (`project_id`,`budget_category`),
  KEY `fk_project_budget_breakdowns_quotation` (`quotation_id`),
  KEY `fk_project_budget_breakdowns_user` (`created_by`),
  CONSTRAINT `fk_project_budget_breakdowns_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_budget_breakdowns_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_project_budget_breakdowns_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_budget_profiles` (
  `project_id` int(11) NOT NULL,
  `budget_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `budget_notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`project_id`),
  KEY `idx_project_budget_profiles_updated_at` (`updated_at`),
  KEY `fk_project_budget_profiles_created_by` (`created_by`),
  KEY `fk_project_budget_profiles_updated_by` (`updated_by`),
  CONSTRAINT `fk_project_budget_profiles_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_budget_profiles_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_budget_profiles_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_cost_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `cost_date` date NOT NULL,
  `cost_category` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_cost_entries_project_date` (`project_id`,`cost_date`,`id`),
  KEY `idx_project_cost_entries_created_by` (`created_by`),
  CONSTRAINT `fk_project_cost_entries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_cost_entries_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `previous_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `source_type` varchar(80) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_history_project` (`project_id`,`created_at`,`id`),
  KEY `idx_project_history_user` (`user_id`),
  KEY `idx_project_history_source` (`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_inventory_deployment_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deployment_id` int(11) NOT NULL,
  `asset_unit_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `returned_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_project_inventory_deployment_units_active` (`deployment_id`,`asset_unit_id`,`returned_at`),
  KEY `idx_project_inventory_deployment_units_deployment` (`deployment_id`),
  KEY `idx_project_inventory_deployment_units_unit` (`asset_unit_id`),
  KEY `idx_project_inventory_deployment_units_returned` (`returned_at`),
  CONSTRAINT `fk_project_inventory_deployment_units_deployment` FOREIGN KEY (`deployment_id`) REFERENCES `project_inventory_deployments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_inventory_deployment_units_unit` FOREIGN KEY (`asset_unit_id`) REFERENCES `asset_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_inventory_deployments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `deployed_by` int(11) NOT NULL,
  `deployed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `returned_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_project_inventory_deployments_project` (`project_id`),
  KEY `idx_project_inventory_deployments_inventory` (`inventory_id`),
  KEY `idx_project_inventory_deployments_returned` (`returned_at`),
  KEY `idx_project_inventory_deployments_user` (`deployed_by`),
  CONSTRAINT `fk_project_inventory_deployments_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_inventory_deployments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_inventory_deployments_user` FOREIGN KEY (`deployed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_inventory_return_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deployment_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `returned_by` int(11) NOT NULL,
  `returned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_project_inventory_return_logs_deployment` (`deployment_id`),
  KEY `idx_project_inventory_return_logs_returned_at` (`returned_at`),
  KEY `fk_project_inventory_return_logs_user` (`returned_by`),
  CONSTRAINT `fk_project_inventory_return_logs_deployment` FOREIGN KEY (`deployment_id`) REFERENCES `project_inventory_deployments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_inventory_return_logs_user` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_payments_project_date` (`project_id`,`payment_date`,`id`),
  KEY `idx_project_payments_created_by` (`created_by`),
  CONSTRAINT `fk_project_payments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_payments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `contact_person` varchar(190) DEFAULT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `project_site` varchar(190) DEFAULT NULL,
  `project_address` text DEFAULT NULL,
  `project_email` varchar(190) DEFAULT NULL,
  `additional_info_json` longtext DEFAULT NULL,
  `project_code` varchar(80) DEFAULT NULL,
  `project_source` varchar(40) NOT NULL DEFAULT 'walk_in',
  `po_number` varchar(80) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `project_start_date` date DEFAULT NULL,
  `estimated_completion_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('draft','pending','ongoing','completed','on-hold','cancelled','archived') DEFAULT 'pending',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `delete_scheduled_at` datetime DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL,
  `restored_by` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_status` (`status`),
  KEY `idx_projects_search_name_status_created` (`project_name`,`status`,`created_at`,`id`),
  KEY `idx_projects_search_address` (`project_address`(120)),
  KEY `idx_projects_search_site` (`project_site`),
  KEY `idx_projects_deleted_at` (`deleted_at`,`delete_scheduled_at`,`status`),
  CONSTRAINT `fk_project_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(11) NOT NULL,
  `purchase_request_item_id` int(11) DEFAULT NULL,
  `item_description` varchar(255) NOT NULL,
  `specification` varchar(255) DEFAULT NULL,
  `unit` varchar(40) NOT NULL,
  `quantity_ordered` decimal(12,2) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_purchase_order_items_order` (`purchase_order_id`),
  KEY `fk_purchase_order_items_request_item` (`purchase_request_item_id`),
  CONSTRAINT `fk_purchase_order_items_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_order_items_request_item` FOREIGN KEY (`purchase_request_item_id`) REFERENCES `purchase_request_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_no` varchar(80) NOT NULL,
  `purchase_request_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('draft','issued','partially_delivered','delivered','cancelled','closed') NOT NULL DEFAULT 'issued',
  `admin_approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_approved_by` int(11) DEFAULT NULL,
  `admin_approved_at` datetime DEFAULT NULL,
  `admin_approval_notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_no` (`po_no`),
  KEY `idx_purchase_orders_request` (`purchase_request_id`),
  KEY `idx_purchase_orders_project_status` (`project_id`,`status`),
  KEY `idx_purchase_orders_supplier` (`supplier_id`),
  KEY `fk_purchase_orders_created_by` (`created_by`),
  CONSTRAINT `fk_purchase_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_orders_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_orders_request` FOREIGN KEY (`purchase_request_id`) REFERENCES `purchase_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_orders_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_request_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_request_id` int(11) NOT NULL,
  `item_description` varchar(255) NOT NULL,
  `specification` varchar(255) DEFAULT NULL,
  `unit` varchar(40) NOT NULL,
  `quantity_requested` decimal(12,2) NOT NULL,
  `estimated_unit_cost` decimal(14,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_purchase_request_items_request` (`purchase_request_id`),
  CONSTRAINT `fk_purchase_request_items_request` FOREIGN KEY (`purchase_request_id`) REFERENCES `purchase_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_no` varchar(80) NOT NULL,
  `project_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `engineer_reviewed_by` int(11) DEFAULT NULL,
  `needed_date` date DEFAULT NULL,
  `request_type` enum('material','tool','equipment','service') NOT NULL DEFAULT 'material',
  `site_location` varchar(190) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `engineer_review_notes` text DEFAULT NULL,
  `engineer_reviewed_at` datetime DEFAULT NULL,
  `status` enum('draft','submitted','engineer_review','engineer_approved','engineer_rejected','approved','cancelled') NOT NULL DEFAULT 'submitted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_no` (`request_no`),
  KEY `idx_purchase_requests_project_status` (`project_id`,`status`),
  KEY `idx_purchase_requests_requested_by` (`requested_by`),
  KEY `fk_purchase_requests_engineer_reviewed_by` (`engineer_reviewed_by`),
  CONSTRAINT `fk_purchase_requests_engineer_reviewed_by` FOREIGN KEY (`engineer_reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_requests_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_requests_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quotation_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_id` int(11) NOT NULL,
  `item_type` enum('material','asset','manpower','other') NOT NULL,
  `source_table` varchar(40) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `unit` varchar(40) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate` decimal(14,2) NOT NULL DEFAULT 0.00,
  `hours` decimal(12,2) NOT NULL DEFAULT 0.00,
  `days` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quotation_items_quotation` (`quotation_id`,`item_type`),
  CONSTRAINT `fk_quotation_items_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quotation_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `reviewer_role` varchar(30) NOT NULL,
  `review_type` enum('comment','suggestion','adjustment','approval_note','client_response') NOT NULL DEFAULT 'comment',
  `message` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quotation_reviews_quotation` (`quotation_id`,`created_at`),
  KEY `fk_quotation_reviews_reviewer` (`reviewer_id`),
  CONSTRAINT `fk_quotation_reviews_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quotation_reviews_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quotation_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_id` int(11) NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) NOT NULL,
  `acted_by` int(11) NOT NULL,
  `actor_role` varchar(30) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quotation_status_history_quotation` (`quotation_id`,`created_at`),
  KEY `fk_quotation_history_user` (`acted_by`),
  CONSTRAINT `fk_quotation_history_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quotation_history_user` FOREIGN KEY (`acted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quotations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_no` varchar(80) NOT NULL,
  `project_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `engineer_id` int(11) NOT NULL,
  `foreman_reviewer_id` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `title` varchar(190) NOT NULL,
  `scope_summary` text DEFAULT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'PHP',
  `estimated_duration_days` int(11) DEFAULT NULL,
  `manpower_hours` decimal(12,2) NOT NULL DEFAULT 0.00,
  `materials_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `assets_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `manpower_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `other_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `profit_margin_percent` decimal(7,2) NOT NULL DEFAULT 0.00,
  `profit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','under_review','for_approval','approved','sent','accepted','rejected') NOT NULL DEFAULT 'draft',
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` int(11) DEFAULT NULL,
  `client_response_at` datetime DEFAULT NULL,
  `client_response_note` text DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_quotation_no` (`quotation_no`),
  KEY `idx_quotations_project_status` (`project_id`,`status`),
  KEY `idx_quotations_client_status` (`client_id`,`status`),
  KEY `idx_quotations_engineer_status` (`engineer_id`,`status`),
  KEY `fk_quotations_foreman` (`foreman_reviewer_id`),
  KEY `fk_quotations_approved_by` (`approved_by`),
  KEY `fk_quotations_sent_by` (`sent_by`),
  KEY `fk_quotations_created_by` (`created_by`),
  KEY `fk_quotations_updated_by` (`updated_by`),
  KEY `fk_quotations_locked_by` (`locked_by`),
  CONSTRAINT `fk_quotations_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_quotations_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_quotations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_quotations_engineer` FOREIGN KEY (`engineer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_quotations_foreman` FOREIGN KEY (`foreman_reviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_quotations_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_quotations_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quotations_sent_by` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_quotations_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `code` varchar(30) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `migration` varchar(190) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_barangays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `province` varchar(80) NOT NULL,
  `city_municipality` varchar(120) NOT NULL,
  `barangay` varchar(150) NOT NULL,
  `psgc_code` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_barangay` (`province`,`city_municipality`,`barangay`),
  KEY `idx_service_barangays_city` (`province`,`city_municipality`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_inquiries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_name` varchar(150) NOT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `contact_no` varchar(30) NOT NULL,
  `province` varchar(80) DEFAULT NULL,
  `city_municipality` varchar(120) DEFAULT NULL,
  `barangay` varchar(150) DEFAULT NULL,
  `site_address` text NOT NULL,
  `service_category` varchar(80) NOT NULL,
  `description` text NOT NULL,
  `preferred_inspection_date` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Pending Review',
  `admin_notes` text DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `archive_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_inspection_cost_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` int(11) NOT NULL,
  `item_type` varchar(30) NOT NULL DEFAULT 'material',
  `inventory_id` int(11) DEFAULT NULL,
  `item_name` varchar(180) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit` varchar(30) NOT NULL DEFAULT 'unit',
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_site_inspection_cost_items_inspection` (`inspection_id`),
  KEY `idx_site_inspection_cost_items_inventory` (`inventory_id`),
  KEY `idx_site_inspection_cost_items_type` (`item_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_inspections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inquiry_id` int(11) NOT NULL,
  `engineer_id` int(11) NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `site_notes` text DEFAULT NULL,
  `engineer_findings` text DEFAULT NULL,
  `risk_notes` text DEFAULT NULL,
  `client_requests` text DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'Scheduled',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_site_inspections_inquiry` (`inquiry_id`),
  KEY `idx_site_inspections_engineer` (`engineer_id`),
  KEY `idx_site_inspections_status` (`status`),
  KEY `idx_site_inspections_scheduled_at` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(80) NOT NULL,
  `supplier_name` varchar(190) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_code` (`supplier_code`),
  KEY `idx_suppliers_status_name` (`status`,`supplier_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `task_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `status` enum('pending','ongoing','completed','delayed') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_task_engineer` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `nickname` varchar(80) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `status_changed_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL,
  `restored_by` int(11) DEFAULT NULL,
  `failed_attempts` int(11) DEFAULT 0,
  `last_failed_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `reset_requested_at` datetime DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `profile_photo_data` longtext DEFAULT NULL,
  `profile_photo_mime` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_full_name` (`full_name`),
  UNIQUE KEY `reset_token` (`reset_token`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `fk_created_by` (`created_by`),
  KEY `idx_users_search_role_status_name` (`role`,`status`,`full_name`),
  KEY `idx_users_deleted_at` (`deleted_at`,`role`,`status`),
  KEY `idx_users_role_status` (`role`,`status`),
  CONSTRAINT `fk_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Active Engineer password-change OTP table.
CREATE TABLE IF NOT EXISTS `engineer_password_otps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `sent_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_engineer_password_otp_user` (`user_id`),
  KEY `idx_engineer_password_otp_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
