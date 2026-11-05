-- ============================================================
-- Migration: ตาราง password_resets (ระบบลืมรหัสผ่าน)
-- import ผ่าน phpMyAdmin (ถ้าติดตั้งใหม่ ตารางนี้อยู่ใน database.sql แล้ว)
-- ============================================================
USE roblox_market;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'เก็บ SHA-256 ของ token ไม่เก็บ token จริง',
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    requested_ip VARCHAR(45) DEFAULT NULL,
    created_by_admin INT DEFAULT NULL COMMENT 'ถ้าแอดมินเป็นคนสร้างลิงก์ จะเป็น id แอดมิน',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token_hash),
    INDEX idx_user (user_id, created_at),
    INDEX idx_ip (requested_ip, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
