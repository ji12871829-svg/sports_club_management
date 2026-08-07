-- Table for Members
CREATE TABLE IF NOT EXISTS `members` (
    `member_id` INT AUTO_INCREMENT PRIMARY KEY,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `phone_number` VARCHAR(20),
    `address` VARCHAR(255),
    `date_joined` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for Sports
CREATE TABLE IF NOT EXISTS `sports` (
    `sport_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT
);

-- Table for Coaches
CREATE TABLE IF NOT EXISTS `coaches` (
    `coach_id` INT AUTO_INCREMENT PRIMARY KEY,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `phone_number` VARCHAR(20),
    `specialization` VARCHAR(255)
);

-- Table for Facilities
CREATE TABLE IF NOT EXISTS `facilities` (
    `facility_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `location` VARCHAR(255),
    `type` VARCHAR(100),
    `capacity` INT
);

-- Table for Equipment
CREATE TABLE IF NOT EXISTS `equipment` (
    `equipment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `quantity` INT NOT NULL,
    `condition` VARCHAR(50)
);

-- Table for Bookings
CREATE TABLE IF NOT EXISTS `bookings` (
    `booking_id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `facility_id` INT,
    `coach_id` INT,
    `sport_id` INT,
    `booking_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `status` VARCHAR(50) DEFAULT 'Pending',
    FOREIGN KEY (`member_id`) REFERENCES `members`(`member_id`) ON DELETE CASCADE,
    FOREIGN KEY (`facility_id`) REFERENCES `facilities`(`facility_id`) ON DELETE SET NULL,
    FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`coach_id`) ON DELETE SET NULL,
    FOREIGN KEY (`sport_id`) REFERENCES `sports`(`sport_id`) ON DELETE SET NULL
);

-- Table for Payments
CREATE TABLE IF NOT EXISTS `payments` (
    `payment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `payment_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `payment_method` VARCHAR(50),
    `description` TEXT,
    FOREIGN KEY (`member_id`) REFERENCES `members`(`member_id`) ON DELETE CASCADE
);

-- Sample Data for Members
INSERT INTO `members` (`first_name`, `last_name`, `email`, `password`, `phone_number`, `address`)
VALUES
('John', 'Doe', 'john.doe@example.com', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '123-456-7890', '123 Main St'),
('Jane', 'Smith', 'jane.smith@example.com', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '098-765-4321', '456 Oak Ave');

-- Sample Data for Sports
INSERT INTO `sports` (`name`, `description`)
VALUES
('Rugby', 'A contact team sport played with an oval ball, two teams of 15 players compete to carry or kick the ball to the opposing goal line'),
('Football', 'The world''s most popular sport where two teams of 11 players try to score by kicking a ball into the opposing goal'),
('Hockey', 'A fast-paced team sport played on a field with sticks and a ball, two teams of 11 players compete to score goals'),
('Volleyball', 'A team sport where two teams of 6 players are separated by a net and try to score by grounding the ball on the opponent''s court'),
('Chess', 'A strategic two-player board game played on a checkered board with 16 pieces per player, the goal is to checkmate the opponent''s king'),
('Horse Riding', 'An equestrian sport involving riding, driving, or vaulting with horses, includes disciplines like dressage, jumping, and polo'),
('Badminton', 'A racquet sport played with a shuttlecock across a net, can be played as singles or doubles');

-- Sample Data for Coaches
INSERT INTO `coaches` (`first_name`, `last_name`, `email`, `phone_number`, `specialization`)
VALUES
('James', 'Ochieng', 'james.o@example.com', '111-222-3333', 'Rugby'),
('David', 'Mwangi', 'david.m@example.com', '444-555-6666', 'Football'),
('Sarah', 'Wanjiku', 'sarah.w@example.com', '777-888-9999', 'Hockey'),
('Peter', 'Kamau', 'peter.k@example.com', '222-333-4444', 'Volleyball'),
('Grace', 'Akinyi', 'grace.a@example.com', '555-666-7777', 'Chess'),
('John', 'Kipchoge', 'john.k@example.com', '888-999-0000', 'Horse Riding'),
('Mary', 'Njeri', 'mary.n@example.com', '333-444-5555', 'Badminton');

-- Sample Data for Facilities
INSERT INTO `facilities` (`name`, `location`, `type`, `capacity`)
VALUES
('Rugby Field', 'East Wing - Outdoor Grounds', 'Field', 30),
('Football Pitch', 'Main Stadium - South Wing', 'Field', 22),
('Hockey Field', 'West Wing - Outdoor Grounds', 'Field', 22),
('Volleyball Court', 'Indoor Sports Hall - Block A', 'Court', 12),
('Chess Room', 'Club House - Room 105', 'Room', 20),
('Horse Riding Arena', 'Equestrian Center - North Gate', 'Arena', 10),
('Badminton Court', 'Indoor Sports Hall - Block B', 'Court', 8);

-- Sample Data for Equipment
INSERT INTO `equipment` (`name`, `description`, `quantity`, `condition`)
VALUES
('Rugby Ball', 'Standard size 5 rugby ball', 15, 'Good'),
('Football', 'FIFA standard size 5 football', 20, 'Good'),
('Hockey Stick', 'Composite field hockey stick', 22, 'Good'),
('Volleyball', 'Official FIVB match volleyball', 10, 'New'),
('Chess Set', 'Tournament standard chess set with clock', 20, 'Good'),
('Riding Helmet', 'Safety certified equestrian helmet', 10, 'Good'),
('Badminton Racket', 'Lightweight carbon fiber racket', 16, 'New'),
('Shuttlecock', 'Feather shuttlecocks (tube of 12)', 10, 'New');

-- Sample Data for Bookings
INSERT INTO `bookings` (`member_id`, `facility_id`, `coach_id`, `sport_id`, `booking_date`, `start_time`, `end_time`, `status`)
VALUES
(1, 2, 2, 2, '2026-05-01', '10:00:00', '11:00:00', 'Confirmed'),
(2, 4, 4, 4, '2026-05-02', '14:00:00', '15:00:00', 'Confirmed');

-- Sample Data for Payments
INSERT INTO `payments` (`member_id`, `amount`, `payment_method`, `description`)
VALUES
(1, 50.00, 'Credit Card', 'Monthly membership fee'),
(2, 25.00, 'Cash', 'Volleyball session fee');

-- Table for Admins
CREATE TABLE IF NOT EXISTS `admins` (
    `admin_id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL
);

-- Default Admin User (admin@sportsclub.com / Admin1234#)
-- NOTE: Keep this hash in sync with the default-password warning in admin/admin_dashboard.php.
-- WARNING: This is a default credential. You MUST change this password upon deployment.
INSERT INTO `admins` (`email`, `password`)
VALUES (
    'admin@sportsclub.com',
    '$2y$10$i2eAHCjiK5J5RMDmjUdzmOUDdGMMRbCYYL2TUOn3jOjdQ9WFve50O' -- Hashed password for 'Admin1234#'
);

-- Table for tracking failed login attempts (Rate Limiting)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email_time` (`email`, `attempted_at`),
    INDEX `idx_ip_time` (`ip_address`, `attempted_at`)
);

-- Index optimizations for high-volume dashboard queries and lookups
CREATE INDEX `idx_bookings_status` ON `bookings` (`status`);
CREATE INDEX `idx_members_date_joined` ON `members` (`date_joined`);
