CREATE TABLE IF NOT EXISTS license_seats (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  hwid VARCHAR(64) NOT NULL,
  activated_at DATETIME NOT NULL,
  UNIQUE KEY uq_license_seats_hwid (hwid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
