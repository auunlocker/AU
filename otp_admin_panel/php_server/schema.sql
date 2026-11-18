-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2025-2026 Shomy
--
-- Database schema for the Penumbra / Let's Fire SLA auth & user portal server.
-- Database: u879807497_multi_tool
--
-- Run once in phpMyAdmin (SQL tab) or:
--   mysql -u u879807497_multi_tool -p u879807497_multi_tool < schema.sql

-- 1. Administrators table (/admin/login.php)
-- Default login:
--   User:     admin
--   Password: multitool@
CREATE TABLE IF NOT EXISTS `admins` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)     NOT NULL,
    `password_hash` VARCHAR(255)    NOT NULL,
    `role`          VARCHAR(32)     NOT NULL DEFAULT 'superadmin',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at` DATETIME        NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admins` (`username`, `password_hash`, `role`)
VALUES ('admin', SHA2('multitool@', 256), 'superadmin')
ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`),
                        `role` = VALUES(`role`);

-- 2. OTP codes table (/otp/otp_eu.php, /admin/otps.php, /user/dashboard.php)
CREATE TABLE IF NOT EXISTS `otp` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `otp`        VARCHAR(64)     NOT NULL,
    `status`     ENUM('valid','used','expired','inactive') NOT NULL DEFAULT 'valid',
    `used`       TINYINT(1)      NOT NULL DEFAULT 0,
    `expired_at` DATETIME        NULL DEFAULT NULL,
    `user`       VARCHAR(64)     NOT NULL DEFAULT '',
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_otp` (`otp`),
    KEY `idx_status_expiry` (`status`, `expired_at`),
    KEY `idx_user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `otp` (`otp`, `status`, `used`, `expired_at`, `user`)
VALUES ('TEST1234', 'valid', 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'admin')
ON DUPLICATE KEY UPDATE `status` = 'valid', `used` = 0,
                        `expired_at` = VALUES(`expired_at`), `user` = VALUES(`user`);

-- 3. Hardware SLA RSA signature log (/api/v1/sign, /admin/signatures.php, /user/logs.php)
CREATE TABLE IF NOT EXISTS `signature_log` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user`          VARCHAR(64)     NOT NULL DEFAULT '',
    `purpose`       VARCHAR(32)     NOT NULL DEFAULT '',
    `digest`        CHAR(64)        NOT NULL DEFAULT '',
    `payload_bytes` INT UNSIGNED    NOT NULL DEFAULT 0,
    `signature`     VARCHAR(1024)   NOT NULL DEFAULT '',
    `ip`            VARCHAR(45)     NOT NULL DEFAULT '',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Registered users / technicians with credit balances (/user/*, /admin/users.php)
CREATE TABLE IF NOT EXISTS `users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)     NOT NULL,
    `password_hash` VARCHAR(255)    NOT NULL,
    `credits`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `profile`       VARCHAR(32)     NOT NULL DEFAULT 'default',
    `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `note`          VARCHAR(255)    NOT NULL DEFAULT '',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at` DATETIME        NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Service & OTP pricing configuration (/admin/price-service.php)
CREATE TABLE IF NOT EXISTS `service_settings` (
    `setting_key`   VARCHAR(64)     NOT NULL,
    `setting_value` VARCHAR(255)    NOT NULL DEFAULT '',
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `service_settings` (`setting_key`, `setting_value`) VALUES
    ('otp_price', '1.00'),
    ('otp_default_minutes', '60'),
    ('currency_label', 'Credits')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

-- 6. Audit log of user credit recharges & OTP deductions (/admin/users.php, /user/credits.php)
CREATE TABLE IF NOT EXISTS `credit_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)     NOT NULL,
    `amount`        DECIMAL(12,2)   NOT NULL,
    `balance_after` DECIMAL(12,2)   NOT NULL,
    `type`          VARCHAR(32)     NOT NULL DEFAULT 'admin_add',
    `description`   VARCHAR(255)    NOT NULL DEFAULT '',
    `actor`         VARCHAR(64)     NOT NULL DEFAULT 'admin',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_created` (`username`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Application service logs per user & OTP (/user/logs.php)
CREATE TABLE IF NOT EXISTS `app_service_logs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(64)     NOT NULL DEFAULT '',
    `otp_code`   VARCHAR(64)     NOT NULL DEFAULT '',
    `event_type` VARCHAR(48)     NOT NULL DEFAULT '',
    `details`    VARCHAR(255)    NOT NULL DEFAULT '',
    `ip`         VARCHAR(45)     NOT NULL DEFAULT '',
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_created` (`username`, `created_at`),
    KEY `idx_otp` (`otp_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
