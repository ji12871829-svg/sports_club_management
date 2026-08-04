-- 050_add_ai_settings_table.sql
-- Key/value settings store. Lets AI provider keys (GEMINI_API_KEY,
-- OPENROUTER_API_KEY) live in the database as a fallback when the .env
-- file is missing keys — e.g. after deployment on a host where .env is
-- not present or was overwritten.

CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed with current .env values if you like (edit the values below):
-- INSERT INTO settings (setting_key, setting_value) VALUES
--     ('GEMINI_API_KEY', ''),
--     ('OPENROUTER_API_KEY', ''),
--     ('OPENROUTER_MODEL', '')
-- ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
