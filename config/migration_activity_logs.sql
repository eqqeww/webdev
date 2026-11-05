-- ============================================================
-- Migration: เพิ่มตาราง activity_logs (Audit Log สำหรับแอดมิน)
-- สำหรับฐานข้อมูลที่ติดตั้งไปแล้ว: import ไฟล์นี้ผ่าน phpMyAdmin
--   หรือ mysql -u root -p roblox_market < migration_activity_logs.sql
-- (ถ้าติดตั้งใหม่ ตารางนี้อยู่ใน database.sql แล้ว)
-- ============================================================
USE roblox_market;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL COMMENT 'ผู้กระทำ (NULL = ผู้เยี่ยมชม/ล็อกอินไม่สำเร็จ)',
    username VARCHAR(50) DEFAULT NULL COMMENT 'เก็บชื่อไว้ด้วย เผื่อ user ถูกลบภายหลัง',
    role VARCHAR(10) DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(30) DEFAULT NULL,
    target_id INT DEFAULT NULL,
    details TEXT DEFAULT NULL COMMENT 'JSON รายละเอียดเพิ่มเติม',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_target (target_type, target_id)
) ENGINE=InnoDB;
-- ไม่ใส่ FOREIGN KEY โดยตั้งใจ เพื่อให้ log ยังอยู่แม้ลบ user/สินค้าไปแล้ว
