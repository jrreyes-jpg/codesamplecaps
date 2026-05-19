<?php

if (!function_exists('quotation_module_required_tables')) {
    function quotation_module_required_tables(): array
    {
        return [
            'quotations',
            'quotation_items',
            'quotation_reviews',
            'quotation_status_history',
            'project_budget_breakdowns',
        ];
    }
}

if (!function_exists('quotation_module_schema_statements')) {
    function quotation_module_schema_statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `quotations` (
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
                KEY `idx_quotations_project_status` (`project_id`, `status`),
                KEY `idx_quotations_client_status` (`client_id`, `status`),
                KEY `idx_quotations_engineer_status` (`engineer_id`, `status`),
                CONSTRAINT `fk_quotations_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_quotations_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`),
                CONSTRAINT `fk_quotations_engineer` FOREIGN KEY (`engineer_id`) REFERENCES `users` (`id`),
                CONSTRAINT `fk_quotations_foreman` FOREIGN KEY (`foreman_reviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_quotations_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_quotations_sent_by` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_quotations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
                CONSTRAINT `fk_quotations_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_quotations_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            "CREATE TABLE IF NOT EXISTS `quotation_items` (
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
                KEY `idx_quotation_items_quotation` (`quotation_id`, `item_type`),
                CONSTRAINT `fk_quotation_items_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            "CREATE TABLE IF NOT EXISTS `quotation_reviews` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `quotation_id` int(11) NOT NULL,
                `reviewer_id` int(11) NOT NULL,
                `reviewer_role` varchar(30) NOT NULL,
                `review_type` enum('comment','suggestion','adjustment','approval_note','client_response') NOT NULL DEFAULT 'comment',
                `message` text NOT NULL,
                `is_internal` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_quotation_reviews_quotation` (`quotation_id`, `created_at`),
                CONSTRAINT `fk_quotation_reviews_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_quotation_reviews_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            "CREATE TABLE IF NOT EXISTS `quotation_status_history` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `quotation_id` int(11) NOT NULL,
                `from_status` varchar(40) DEFAULT NULL,
                `to_status` varchar(40) NOT NULL,
                `acted_by` int(11) NOT NULL,
                `actor_role` varchar(30) NOT NULL,
                `remarks` text DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_quotation_status_history_quotation` (`quotation_id`, `created_at`),
                CONSTRAINT `fk_quotation_history_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_quotation_history_user` FOREIGN KEY (`acted_by`) REFERENCES `users` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            "CREATE TABLE IF NOT EXISTS `project_budget_breakdowns` (
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
                KEY `idx_project_budget_breakdowns_project` (`project_id`, `budget_category`),
                CONSTRAINT `fk_project_budget_breakdowns_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_project_budget_breakdowns_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_project_budget_breakdowns_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
        ];
    }
}

if (!function_exists('quotation_module_ensure_schema')) {
    function quotation_module_ensure_schema(mysqli $conn): array
    {
        $errors = [];

        foreach (quotation_module_schema_statements() as $sql) {
            if (!$conn->query($sql)) {
                $errors[] = $conn->error;
            }
        }

        return [
            'success' => $errors === [],
            'errors' => $errors,
        ];
    }
}
