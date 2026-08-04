-- ============================================================
-- Migration 048: Role-Based Access Control
-- Creates roles, role_permissions, and adds role_id to members/admins
-- ============================================================

-- Roles table
CREATE TABLE IF NOT EXISTS roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    slug VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT '',
    is_system TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permissions table
CREATE TABLE IF NOT EXISTS permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL DEFAULT '',
    description VARCHAR(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Role-Permission junction table
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add role_id to members table
ALTER TABLE members ADD COLUMN IF NOT EXISTS role_id INT DEFAULT NULL AFTER member_id;
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_member_role' AND TABLE_NAME = 'members');
SET @fk_sql = IF(@fk_exists = 0, 'ALTER TABLE members ADD CONSTRAINT fk_member_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add role_id to admins table
ALTER TABLE admins ADD COLUMN IF NOT EXISTS role_id INT DEFAULT NULL AFTER admin_id;
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_admin_role' AND TABLE_NAME = 'admins');
SET @fk_sql = IF(@fk_exists = 0, 'ALTER TABLE admins ADD CONSTRAINT fk_admin_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seed default roles
INSERT IGNORE INTO roles (name, slug, description, is_system) VALUES
('Member', 'member', 'Standard club member with basic access', 1),
('Player', 'player', 'Registered player who participates in matches', 1),
('Coach', 'coach', 'Club coach who can view session notes and player stats', 1),
('Parent', 'parent', 'Parent or guardian of a junior member', 1),
('Staff', 'staff', 'Club staff with operational access', 1),
('Administrator', 'administrator', 'Full system access to manage the club', 1),
('Super Admin', 'super-admin', 'Unrestricted access to all system functions', 1);

-- Seed permissions
INSERT IGNORE INTO permissions (code, name, module, description) VALUES
-- Members module
('members.view', 'View Members', 'Members', 'View the member directory'),
('members.create', 'Create Members', 'Members', 'Register new members'),
('members.edit', 'Edit Members', 'Members', 'Edit member profiles'),
('members.delete', 'Delete Members', 'Members', 'Delete member accounts'),
('members.export', 'Export Members', 'Members', 'Export member data'),

-- Bookings module
('bookings.view', 'View Bookings', 'Bookings', 'View all bookings'),
('bookings.create', 'Create Bookings', 'Bookings', 'Create new bookings'),
('bookings.approve', 'Approve Bookings', 'Bookings', 'Approve or reject bookings'),
('bookings.delete', 'Delete Bookings', 'Bookings', 'Delete bookings'),

-- Finance module
('payments.view', 'View Payments', 'Finance', 'View payment records'),
('payments.create', 'Create Payments', 'Finance', 'Record new payments'),
('payments.refund', 'Process Refunds', 'Finance', 'Process payment refunds'),
('payments.export', 'Export Payments', 'Finance', 'Export payment data'),
('revenue.view', 'View Revenue', 'Finance', 'View revenue dashboard'),

-- Sports & Facilities
('sports.manage', 'Manage Sports', 'Sports', 'Add/edit/delete sports'),
('facilities.manage', 'Manage Facilities', 'Facilities', 'Add/edit/delete facilities'),
('equipment.manage', 'Manage Equipment', 'Equipment', 'Manage equipment inventory'),

-- Competitions
('leagues.manage', 'Manage Leagues', 'Competitions', 'Create and manage leagues'),
('fixtures.manage', 'Manage Fixtures', 'Competitions', 'Create and manage fixtures'),
('standings.manage', 'Manage Standings', 'Competitions', 'Update league standings'),
('tickets.manage', 'Manage Tickets', 'Competitions', 'Manage ticket settings'),

-- System
('settings.view', 'View Settings', 'System', 'View system settings'),
('settings.edit', 'Edit Settings', 'System', 'Edit system settings'),
('roles.manage', 'Manage Roles', 'System', 'Manage roles and permissions'),
('backup.create', 'Create Backups', 'System', 'Create database backups'),
('logs.view', 'View Logs', 'System', 'View activity logs'),

-- Engagement
('announcements.manage', 'Manage Announcements', 'Engagement', 'Create and manage announcements'),
('polls.manage', 'Manage Polls', 'Engagement', 'Create and manage polls'),
('forum.moderate', 'Moderate Forum', 'Engagement', 'Moderate forum posts'),
('sponsors.manage', 'Manage Sponsors', 'Engagement', 'Manage sponsors'),
('volunteers.manage', 'Manage Volunteers', 'Engagement', 'Manage volunteer opportunities');

-- Assign all permissions to Administrator role
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r, permissions p
WHERE r.slug = 'administrator';

-- Assign all permissions to Super Admin
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r, permissions p
WHERE r.slug = 'super-admin';

-- Assign basic permissions to Staff
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r, permissions p
WHERE r.slug = 'staff'
  AND p.code IN (
    'members.view', 'members.edit',
    'bookings.view', 'bookings.approve',
    'payments.view',
    'sports.manage', 'facilities.manage', 'equipment.manage',
    'leagues.manage', 'fixtures.manage', 'standings.manage',
    'announcements.manage', 'polls.manage', 'sponsors.manage'
  );

-- Assign basic permissions to Member
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r, permissions p
WHERE r.slug = 'member'
  AND p.code IN ('bookings.view', 'bookings.create');
