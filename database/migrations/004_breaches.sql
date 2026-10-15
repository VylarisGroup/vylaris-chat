CREATE TABLE IF NOT EXISTS data_breaches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discovered_at DATETIME NOT NULL,
  occurred_at DATETIME NULL,
  description TEXT NOT NULL,
  data_categories VARCHAR(500) NOT NULL DEFAULT '',
  affected_count INT UNSIGNED NULL,
  risk ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  measures TEXT NULL,
  authority_notified_at DATETIME NULL,
  authority_reason VARCHAR(500) NULL,
  individuals_notified_at DATETIME NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_breach_status (status, discovered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
