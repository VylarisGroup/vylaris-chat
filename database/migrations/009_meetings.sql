-- Migration 009: Videokonferenzen (TheRedStonee Meets)
-- Hinweis: Jedes Statement endet mit ";" am Zeilenende. Keine Semikolons in Kommentaren oder Strings verwenden.

ALTER TABLE messages MODIFY COLUMN kind ENUM('text','system','poll','event','meeting') NOT NULL DEFAULT 'text';

CREATE TABLE meetings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  room_code VARCHAR(60) NOT NULL,
  started_by BIGINT UNSIGNED NULL,
  started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  ended_reason VARCHAR(20) NULL,
  participants SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  peak SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_sync_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meetings_message (message_id),
  UNIQUE KEY uq_meetings_room (room_code),
  KEY idx_meetings_conv (conversation_id, ended_at),
  CONSTRAINT fk_meetings_msg FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_meetings_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_meetings_starter FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
