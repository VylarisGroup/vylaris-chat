-- Migration 011: Sprachnachrichten (Anhänge mit Dauer)
-- Hinweis: Jedes Statement endet mit ";" am Zeilenende. Keine Semikolons in Kommentaren oder Strings verwenden.

ALTER TABLE message_attachments ADD COLUMN is_voice TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE message_attachments ADD COLUMN duration_ms INT UNSIGNED NULL;
