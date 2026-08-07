-- Admin auth epoch for "log out other sessions" support.
-- Bumping this value invalidates every other active session for the admin
-- (sessions store the epoch at login; the auth guard compares it per request).

ALTER TABLE `admins`
    ADD COLUMN `auth_epoch` INT NOT NULL DEFAULT 0 AFTER `recovery_codes`;
