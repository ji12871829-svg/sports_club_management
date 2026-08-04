
-- Create the database
CREATE DATABASE IF NOT EXISTS `sports_club_db`;


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
