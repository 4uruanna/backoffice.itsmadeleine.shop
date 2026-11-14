-- Schéma initial (M1). Dates en UTC (SET time_zone = '+00:00' à la connexion).
-- En local : exécuté par MySQL à la création du volume Docker.
-- Sur OVH : à lancer une fois dans phpMyAdmin.

CREATE TABLE pickup_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shopify_order_id BIGINT UNSIGNED NOT NULL UNIQUE,
  order_name VARCHAR(32) NOT NULL,
  customer_email VARCHAR(255) NULL,
  status_page_url VARCHAR(500) NULL,
  token CHAR(32) NULL UNIQUE,
  status ENUM('pending','processing','ignored','sent','failed','error') NOT NULL DEFAULT 'pending',
  pending_reason VARCHAR(32) NULL,          -- ex. rate_limited
  error_code VARCHAR(32) NULL,              -- payload_errors, no_email, max_attempts
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  webhook_id VARCHAR(64) NULL,
  revoked_at DATETIME NULL,
  revoked_reason ENUM('cancelled','refunded') NULL,
  locked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  KEY idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pickup_order_id INT UNSIGNED NOT NULL,
  result ENUM('redirected','revoked') NOT NULL,
  scanned_at DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  KEY idx_scanned_at (scanned_at),
  CONSTRAINT fk_scans_pickup_order FOREIGN KEY (pickup_order_id)
    REFERENCES pickup_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pickup_order_id INT UNSIGNED NULL,
  kind ENUM('qr','alert') NOT NULL,
  sent_at DATETIME NOT NULL,
  KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
