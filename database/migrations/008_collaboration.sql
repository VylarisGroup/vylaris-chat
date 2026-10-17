-- Migration 008: Threads, Umfragen, Termine, synchrone Entwürfe, Gastzugänge
-- Hinweis: Jedes Statement endet mit ";" am Zeilenende. Keine Semikolons in Kommentaren oder Strings verwenden.

ALTER TABLE messages MODIFY COLUMN kind ENUM('text','system','poll','event') NOT NULL DEFAULT 'text';

ALTER TABLE messages ADD COLUMN thread_root_id BIGINT UNSIGNED NULL AFTER reply_to_id;

ALTER TABLE messages ADD KEY idx_messages_thread (thread_root_id, id);

CREATE TABLE polls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL,
  question VARCHAR(300) NOT NULL,
  multiple TINYINT(1) NOT NULL DEFAULT 0,
  anonymous TINYINT(1) NOT NULL DEFAULT 0,
  closes_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_polls_message (message_id),
  CONSTRAINT fk_polls_msg FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_polls_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE poll_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  poll_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(200) NOT NULL,
  position TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_poll_options_poll (poll_id, position),
  CONSTRAINT fk_poll_options_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE poll_votes (
  poll_id BIGINT UNSIGNED NOT NULL,
  option_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (option_id, user_id),
  KEY idx_poll_votes_user (poll_id, user_id),
  CONSTRAINT fk_poll_votes_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_votes_option FOREIGN KEY (option_id) REFERENCES poll_options (id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_votes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  description VARCHAR(2000) NOT NULL DEFAULT '',
  location VARCHAR(200) NOT NULL DEFAULT '',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  uid CHAR(36) NOT NULL,
  sequence INT UNSIGNED NOT NULL DEFAULT 0,
  cancelled_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_calendar_message (message_id),
  UNIQUE KEY uq_calendar_uid (uid),
  KEY idx_calendar_conv (conversation_id, starts_at),
  KEY idx_calendar_start (starts_at),
  CONSTRAINT fk_calendar_msg FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_calendar_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_calendar_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_rsvps (
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('yes','maybe','no') NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (event_id, user_id),
  KEY idx_rsvps_user (user_id),
  CONSTRAINT fk_rsvps_event FOREIGN KEY (event_id) REFERENCES calendar_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_rsvps_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drafts (
  user_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  thread_root_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  reply_to_id BIGINT UNSIGNED NULL,
  device CHAR(12) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, conversation_id, thread_root_id),
  KEY idx_drafts_updated (updated_at),
  CONSTRAINT fk_drafts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_drafts_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users MODIFY COLUMN role ENUM('admin','member','guest') NOT NULL DEFAULT 'member';

ALTER TABLE users ADD COLUMN guest_expires_at DATETIME NULL;

ALTER TABLE users ADD COLUMN ics_token_hash CHAR(64) NULL;

ALTER TABLE users ADD UNIQUE KEY uq_users_ics (ics_token_hash);

ALTER TABLE invitations MODIFY COLUMN role ENUM('admin','member','guest') NOT NULL DEFAULT 'member';

ALTER TABLE invitations ADD COLUMN conversation_ids VARCHAR(255) NULL;

ALTER TABLE invitations ADD COLUMN guest_days SMALLINT UNSIGNED NULL;
