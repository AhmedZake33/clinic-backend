-- Combined SQL for Laravel migrations created on 2026_05_12.
-- Run this on the clinic database when you need the SQL equivalent of:
-- 2026_05_12_000001_create_reservation_service_permissions
-- 2026_05_12_000002_add_reservation_service_fk_to_financials
-- 2026_05_12_070008_create_transactions_table
-- 2026_05_12_095431_assign_role_permissions_to_doctor_and_assistant
-- 2026_05_12_105747_add_columns_to_transactions_table
-- 2026_05_12_115337_add_instapay_to_transactions_payment_method
-- 2026_05_12_120736_add_instapay_to_financials_payment_method
-- 2026_05_12_150000_add_parent_doctor_id_to_users_table
-- 2026_05_12_160600_add_sub_doctor_to_users_role_enum

DELIMITER $$

DROP PROCEDURE IF EXISTS add_column_if_missing$$
CREATE PROCEDURE add_column_if_missing(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN column_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = table_name_value
          AND column_name = column_name_value
    ) THEN
        SET @sql_statement = CONCAT('ALTER TABLE `', table_name_value, '` ADD COLUMN ', column_definition_value);
        PREPARE prepared_statement FROM @sql_statement;
        EXECUTE prepared_statement;
        DEALLOCATE PREPARE prepared_statement;
    END IF;
END$$

DROP PROCEDURE IF EXISTS add_index_if_missing$$
CREATE PROCEDURE add_index_if_missing(
    IN table_name_value VARCHAR(64),
    IN index_name_value VARCHAR(64),
    IN index_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = table_name_value
          AND index_name = index_name_value
    ) THEN
        SET @sql_statement = CONCAT('ALTER TABLE `', table_name_value, '` ADD ', index_definition_value);
        PREPARE prepared_statement FROM @sql_statement;
        EXECUTE prepared_statement;
        DEALLOCATE PREPARE prepared_statement;
    END IF;
END$$

DROP PROCEDURE IF EXISTS add_fk_if_missing$$
CREATE PROCEDURE add_fk_if_missing(
    IN table_name_value VARCHAR(64),
    IN fk_name_value VARCHAR(64),
    IN fk_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE table_schema = DATABASE()
          AND table_name = table_name_value
          AND constraint_name = fk_name_value
          AND constraint_type = 'FOREIGN KEY'
    ) THEN
        SET @sql_statement = CONCAT('ALTER TABLE `', table_name_value, '` ADD CONSTRAINT `', fk_name_value, '` ', fk_definition_value);
        PREPARE prepared_statement FROM @sql_statement;
        EXECUTE prepared_statement;
        DEALLOCATE PREPARE prepared_statement;
    END IF;
END$$

DELIMITER ;

-- Reservation service permissions.
INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT permission_name, 'web', NOW(), NOW()
FROM (
    SELECT 'reservation-services.view' AS permission_name
    UNION ALL SELECT 'reservation-services.create'
    UNION ALL SELECT 'reservation-services.edit'
    UNION ALL SELECT 'reservation-services.delete'
) AS reservation_service_permissions
WHERE NOT EXISTS (
    SELECT 1
    FROM `permissions`
    WHERE `permissions`.`name` = reservation_service_permissions.permission_name
      AND `permissions`.`guard_name` = 'web'
);

-- Ensure the roles used by the 2026_05_12 migrations exist.
INSERT INTO `roles` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT role_name, 'web', NOW(), NOW()
FROM (
    SELECT 'doctor' AS role_name
    UNION ALL SELECT 'assistant'
    UNION ALL SELECT 'client'
    UNION ALL SELECT 'admin'
) AS migration_roles
WHERE NOT EXISTS (
    SELECT 1
    FROM `roles`
    WHERE `roles`.`name` = migration_roles.role_name
      AND `roles`.`guard_name` = 'web'
);

-- Assign missing permissions for the same role groups used by the migrations.
-- This is intentionally additive and does not remove existing role permissions.
INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT `permissions`.`id`, `roles`.`id`
FROM `permissions`
JOIN `roles` ON `roles`.`guard_name` = `permissions`.`guard_name`
WHERE `roles`.`name` = 'doctor'
  AND (`permissions`.`name` LIKE 'doctor.%' OR `permissions`.`name` LIKE 'reservation-services.%');

INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT `permissions`.`id`, `roles`.`id`
FROM `permissions`
JOIN `roles` ON `roles`.`guard_name` = `permissions`.`guard_name`
WHERE `roles`.`name` = 'assistant'
  AND (`permissions`.`name` LIKE 'assistant.%' OR `permissions`.`name` LIKE 'reservation-services.%');

INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT `permissions`.`id`, `roles`.`id`
FROM `permissions`
JOIN `roles` ON `roles`.`guard_name` = `permissions`.`guard_name`
WHERE `roles`.`name` = 'client'
  AND `permissions`.`name` LIKE 'client.%';

INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT `permissions`.`id`, `roles`.`id`
FROM `permissions`
JOIN `roles` ON `roles`.`guard_name` = `permissions`.`guard_name`
WHERE `roles`.`name` = 'admin';

-- Financials changes.
CALL add_column_if_missing('financials', 'reservation_service_id', '`reservation_service_id` BIGINT UNSIGNED NULL AFTER `reservation_id`');
CALL add_column_if_missing('financials', 'voided', '`voided` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`');
CALL add_fk_if_missing(
    'financials',
    'financials_reservation_service_id_foreign',
    'FOREIGN KEY (`reservation_service_id`) REFERENCES `reservation_services` (`id`) ON DELETE SET NULL'
);

ALTER TABLE `financials`
    MODIFY COLUMN `payment_method` ENUM('cash', 'card', 'transfer', 'other', 'instapay') NOT NULL DEFAULT 'cash';

-- Transactions table, with the final 2026_05_12 shape.
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `financial_id` BIGINT UNSIGNED NOT NULL,
    `doctor_id` BIGINT UNSIGNED NOT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `payment_method` ENUM('cash', 'card', 'transfer', 'other', 'instapay') NOT NULL DEFAULT 'cash',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `transactions_financial_id_foreign` (`financial_id`),
    KEY `transactions_doctor_id_foreign` (`doctor_id`),
    KEY `transactions_created_by_foreign` (`created_by`),
    CONSTRAINT `transactions_financial_id_foreign` FOREIGN KEY (`financial_id`) REFERENCES `financials` (`id`) ON DELETE CASCADE,
    CONSTRAINT `transactions_doctor_id_foreign` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
    CONSTRAINT `transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If transactions already existed before these migrations, add the missing final columns/keys.
CALL add_column_if_missing('transactions', 'financial_id', '`financial_id` BIGINT UNSIGNED NOT NULL AFTER `id`');
CALL add_column_if_missing('transactions', 'doctor_id', '`doctor_id` BIGINT UNSIGNED NOT NULL AFTER `financial_id`');
CALL add_column_if_missing('transactions', 'created_by', '`created_by` BIGINT UNSIGNED NOT NULL AFTER `doctor_id`');
CALL add_column_if_missing('transactions', 'amount', '`amount` DECIMAL(10, 2) NOT NULL AFTER `created_by`');
CALL add_column_if_missing('transactions', 'payment_method', '`payment_method` ENUM(''cash'', ''card'', ''transfer'', ''other'', ''instapay'') NOT NULL DEFAULT ''cash'' AFTER `amount`');
CALL add_column_if_missing('transactions', 'notes', '`notes` TEXT NULL AFTER `payment_method`');
CALL add_column_if_missing('transactions', 'created_at', '`created_at` TIMESTAMP NULL DEFAULT NULL AFTER `notes`');
CALL add_column_if_missing('transactions', 'updated_at', '`updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`');

ALTER TABLE `transactions`
    MODIFY COLUMN `payment_method` ENUM('cash', 'card', 'transfer', 'other', 'instapay') NOT NULL DEFAULT 'cash';

CALL add_index_if_missing('transactions', 'transactions_financial_id_foreign', 'INDEX `transactions_financial_id_foreign` (`financial_id`)');
CALL add_index_if_missing('transactions', 'transactions_doctor_id_foreign', 'INDEX `transactions_doctor_id_foreign` (`doctor_id`)');
CALL add_index_if_missing('transactions', 'transactions_created_by_foreign', 'INDEX `transactions_created_by_foreign` (`created_by`)');
CALL add_fk_if_missing(
    'transactions',
    'transactions_financial_id_foreign',
    'FOREIGN KEY (`financial_id`) REFERENCES `financials` (`id`) ON DELETE CASCADE'
);
CALL add_fk_if_missing(
    'transactions',
    'transactions_doctor_id_foreign',
    'FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`)'
);
CALL add_fk_if_missing(
    'transactions',
    'transactions_created_by_foreign',
    'FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)'
);

-- Users changes.
CALL add_column_if_missing('users', 'parent_doctor_id', '`parent_doctor_id` BIGINT UNSIGNED NULL AFTER `doctor_id`');
CALL add_index_if_missing('users', 'users_parent_doctor_id_index', 'INDEX `users_parent_doctor_id_index` (`parent_doctor_id`)');
CALL add_fk_if_missing(
    'users',
    'users_parent_doctor_id_foreign',
    'FOREIGN KEY (`parent_doctor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL'
);

ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('admin', 'doctor', 'assistant', 'client', 'sub-doctor') NOT NULL DEFAULT 'client';

-- Mark the original Laravel migrations as applied, so php artisan migrate will not run them again.
SET @migration_batch := (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
('2026_05_12_000001_create_reservation_service_permissions', @migration_batch),
('2026_05_12_000002_add_reservation_service_fk_to_financials', @migration_batch),
('2026_05_12_070008_create_transactions_table', @migration_batch),
('2026_05_12_095431_assign_role_permissions_to_doctor_and_assistant', @migration_batch),
('2026_05_12_105747_add_columns_to_transactions_table', @migration_batch),
('2026_05_12_115337_add_instapay_to_transactions_payment_method', @migration_batch),
('2026_05_12_120736_add_instapay_to_financials_payment_method', @migration_batch),
('2026_05_12_150000_add_parent_doctor_id_to_users_table', @migration_batch),
('2026_05_12_160600_add_sub_doctor_to_users_role_enum', @migration_batch);

DROP PROCEDURE IF EXISTS add_column_if_missing;
DROP PROCEDURE IF EXISTS add_index_if_missing;
DROP PROCEDURE IF EXISTS add_fk_if_missing;


-- Add max_sub_doctors column to users table

ALTER TABLE `users`
ADD COLUMN `max_sub_doctors` TINYINT UNSIGNED NOT NULL DEFAULT 0
COMMENT 'Max sub-doctors this doctor can create. 0 = not allowed.'
AFTER `notes`;