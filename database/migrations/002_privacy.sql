-- Migration 002: Datenschutz (Betroffenenrechte nach DSG und DSGVO)
-- Hinweis: Jedes Statement endet mit ";" am Zeilenende. Keine Semikolons in Kommentaren oder Strings verwenden.

ALTER TABLE users
  ADD COLUMN processing_restricted_at DATETIME NULL AFTER deleted_at,
  ADD COLUMN processing_restricted_note VARCHAR(255) NOT NULL DEFAULT '' AFTER processing_restricted_at,
  ADD COLUMN privacy_ack_at DATETIME NULL AFTER processing_restricted_note;

CREATE TABLE privacy_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  subject_label VARCHAR(190) NOT NULL DEFAULT '',
  type ENUM('access','portability','rectification','erasure','restriction','objection','other') NOT NULL,
  message VARCHAR(2000) NOT NULL DEFAULT '',
  source ENUM('user','admin') NOT NULL DEFAULT 'user',
  status ENUM('open','in_progress','completed','rejected') NOT NULL DEFAULT 'open',
  response VARCHAR(2000) NOT NULL DEFAULT '',
  due_at DATETIME NOT NULL,
  handled_by BIGINT UNSIGNED NULL,
  handled_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_privacy_status (status, due_at),
  KEY idx_privacy_user (user_id, created_at),
  CONSTRAINT fk_privacy_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_privacy_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_privacy_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
