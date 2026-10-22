-- Migration 010: Notizen-Chat (Selbst-Chat) und KI-Anbindung
-- Hinweis: Jedes Statement endet mit ";" am Zeilenende. Keine Semikolons in Kommentaren oder Strings verwenden.

ALTER TABLE conversations MODIFY COLUMN type ENUM('direct','group','notes') NOT NULL;

ALTER TABLE conversations ADD COLUMN ai_context ENUM('request','recent','full') NOT NULL DEFAULT 'recent';

ALTER TABLE users ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0;
