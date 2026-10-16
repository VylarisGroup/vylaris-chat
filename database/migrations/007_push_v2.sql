-- Push v2: verschlüsselte Nutzlast (RFC 8291), Diagnose je Gerät, Sichtbarkeit der App
ALTER TABLE push_subscriptions
  ADD COLUMN p256dh VARCHAR(100) NULL AFTER endpoint,
  ADD COLUMN auth_secret VARCHAR(40) NULL AFTER p256dh,
  ADD COLUMN last_status SMALLINT UNSIGNED NULL,
  ADD COLUMN last_error VARCHAR(255) NULL,
  ADD COLUMN last_error_at DATETIME NULL;

ALTER TABLE users
  ADD COLUMN last_visible_at DATETIME NULL;
