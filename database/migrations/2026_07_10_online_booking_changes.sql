-- SQL generated from 2026_07_10 Laravel migrations.
-- Safe to run more than once on MySQL/MariaDB.

DELIMITER $$

DROP PROCEDURE IF EXISTS add_online_booking_columns_20260710 $$

CREATE PROCEDURE add_online_booking_columns_20260710()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'booking_slug'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `booking_slug` VARCHAR(255) NULL AFTER `max_sub_doctors`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND INDEX_NAME = 'users_booking_slug_unique'
    ) THEN
        ALTER TABLE `users`
            ADD UNIQUE INDEX `users_booking_slug_unique` (`booking_slug`);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reservations'
          AND COLUMN_NAME = 'source'
    ) THEN
        ALTER TABLE `reservations`
            ADD COLUMN `source` VARCHAR(30) NOT NULL DEFAULT 'internal' AFTER `status`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reservations'
          AND COLUMN_NAME = 'online_booking_existing_client'
    ) THEN
        ALTER TABLE `reservations`
            ADD COLUMN `online_booking_existing_client` TINYINT(1) NOT NULL DEFAULT 0 AFTER `source`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reservations'
          AND COLUMN_NAME = 'online_booking_ip'
    ) THEN
        ALTER TABLE `reservations`
            ADD COLUMN `online_booking_ip` VARCHAR(45) NULL AFTER `online_booking_existing_client`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reservations'
          AND COLUMN_NAME = 'online_booking_user_agent'
    ) THEN
        ALTER TABLE `reservations`
            ADD COLUMN `online_booking_user_agent` VARCHAR(500) NULL AFTER `online_booking_ip`;
    END IF;
END $$

CALL add_online_booking_columns_20260710() $$

DROP PROCEDURE IF EXISTS add_online_booking_columns_20260710 $$

DELIMITER ;

