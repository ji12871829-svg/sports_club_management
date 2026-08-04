-- ================================================
-- Damage Reports & Fines System
-- Run this SQL to set up the required table
-- ================================================

CREATE TABLE IF NOT EXISTS damage_reports (
    report_id       INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id    INT NOT NULL,
    reported_by     INT NOT NULL,               -- user_id of the person who damaged/reported
    reported_by_role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    damage_description TEXT NOT NULL,
    qty_damaged     INT NOT NULL DEFAULT 1,     -- how many units were damaged
    fine_amount     DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    fine_status     ENUM('unpaid', 'paid', 'waived') NOT NULL DEFAULT 'unpaid',
    reported_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME NULL,
    notes           TEXT NULL,                  -- admin notes / resolution notes

    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id) ON DELETE CASCADE
);

CREATE INDEX `idx_damage_reports_reporter` ON `damage_reports` (`reported_by`);
CREATE INDEX `idx_damage_reports_equipment` ON `damage_reports` (`equipment_id`);
CREATE INDEX `idx_damage_reports_status` ON `damage_reports` (`fine_status`);
