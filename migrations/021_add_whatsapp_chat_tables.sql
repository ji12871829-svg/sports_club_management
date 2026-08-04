-- Migration: Add WhatsApp Chat Tables
-- This table stores incoming and outgoing WhatsApp messages.

CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `wa_message_id` VARCHAR(255) UNIQUE NOT NULL, -- WhatsApp's unique ID for the message
  `member_id` INT DEFAULT NULL, -- Link to members table if recognized
  `phone_number` VARCHAR(20) NOT NULL, -- The WhatsApp number (sender or receiver)
  `message_body` TEXT,
  `message_type` ENUM('text', 'image', 'document', 'audio', 'video', 'location') DEFAULT 'text',
  `direction` ENUM('inbound', 'outbound') NOT NULL, -- inbound = from user, outbound = from admin
  `status` ENUM('sent', 'delivered', 'read', 'failed', 'received') DEFAULT 'received',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`member_id`) REFERENCES `members`(`member_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for faster lookups by phone number and member
CREATE INDEX idx_wa_phone ON whatsapp_messages(phone_number);
CREATE INDEX idx_wa_member ON whatsapp_messages(member_id);
