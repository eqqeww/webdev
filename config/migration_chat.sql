-- ============================================================
-- Migration: ระบบแชทระหว่างผู้ใช้กับแอดมิน
-- สำหรับฐานข้อมูลที่ติดตั้งไปแล้ว: import ผ่าน phpMyAdmin
--   หรือ mysql -u root -p roblox_market < config/migration_chat.sql
-- (ถ้าติดตั้งใหม่ ตารางเหล่านี้อยู่ใน database.sql แล้ว)
-- ============================================================
USE roblox_market;

-- 1 ผู้ใช้ = 1 ห้องแชท
CREATE TABLE IF NOT EXISTS chat_threads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_preview VARCHAR(120) DEFAULT NULL COMMENT 'NULL = ยังไม่มีข้อความ (ไม่แสดงในรายการของแอดมิน)',
    user_unread INT NOT NULL DEFAULT 0 COMMENT 'จำนวนข้อความจากแอดมินที่ผู้ใช้ยังไม่อ่าน',
    admin_unread INT NOT NULL DEFAULT 0 COMMENT 'จำนวนข้อความจากผู้ใช้ที่แอดมินยังไม่อ่าน',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_last (last_message_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    thread_id INT NOT NULL,
    sender_id INT NOT NULL,
    sender_role ENUM('user','admin') NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_thread (thread_id, id),
    FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB;
