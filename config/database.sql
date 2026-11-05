-- ============================================================
-- TUNG TUNG SHOP - Roblox ID Market - Database Schema
-- Import ไฟล์นี้ผ่าน phpMyAdmin หรือคำสั่ง mysql -u root -p < database.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS roblox_market CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE roblox_market;

-- ---------------------------------------------
-- ตารางผู้ใช้งาน
-- ---------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    status ENUM('active','banned') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------
-- ตารางร้านค้า
-- ---------------------------------------------
CREATE TABLE shops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    logo VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------
-- ตารางแมพ (เกม/แมพใน Roblox เช่น Blox Fruits, Adopt Me)
-- ---------------------------------------------
CREATE TABLE maps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    description TEXT,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------
-- ตารางสินค้า (ไอดี Roblox ที่ประกาศขาย)
-- ---------------------------------------------
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    map_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    price DECIMAL(12,2) NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    account_detail TEXT COMMENT 'ข้อมูลบัญชี (username/pass) จะแสดงเฉพาะหลังซื้อสำเร็จ',
    status ENUM('available','sold','hidden') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (map_id) REFERENCES maps(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------
-- ตารางคำสั่งซื้อ
-- ---------------------------------------------
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    status ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------
-- ตารางแจ้งเติมเงิน (Topup)
-- ---------------------------------------------
CREATE TABLE topups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    slip_image VARCHAR(255) NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME DEFAULT NULL,
    verify_result VARCHAR(20) DEFAULT NULL COMMENT 'auto_approved / auto_rejected / manual_review / skipped',
    verify_detail TEXT DEFAULT NULL COMMENT 'JSON ผลตรวจสลิป (ยอดในสลิป ผู้โอน ผู้รับ เหตุผล)',
    slip_trans_ref VARCHAR(64) DEFAULT NULL COMMENT 'เลขอ้างอิงรายการจากสลิป ใช้กันสลิปซ้ำ',
    UNIQUE KEY uq_slip_trans_ref (slip_trans_ref),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------
-- ตารางบันทึกกิจกรรม (Audit Log) สำหรับแอดมิน
-- ---------------------------------------------
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

-- ---------------------------------------------
-- ตารางรีเซ็ตรหัสผ่าน
-- ---------------------------------------------
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

-- ---------------------------------------------
-- ระบบแชทผู้ใช้ <-> แอดมิน
-- ---------------------------------------------
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

-- ---------------------------------------------
-- สร้างบัญชีแอดมินเริ่มต้น
-- Username: admin  |  Password: admin1234
-- (รหัสผ่านถูก hash ด้วย password_hash แบบ bcrypt)
-- ---------------------------------------------
INSERT INTO users (username, email, password, wallet_balance, role)
VALUES ('admin', 'admin@robloxmarket.local', '$2b$10$gW8yeBtV12zncWzGwDQur.zAhN0Cp4YtlPZJdJFkv42o0AqCkUqmC', 0.00, 'admin');
-- hash ด้านบน = admin1234

-- ---------------------------------------------
-- ตัวอย่างร้านค้า
-- ---------------------------------------------
INSERT INTO shops (name, description, logo) VALUES
('ShopMaster ID', 'ร้านไอดีคุณภาพ การันตีทุกไอดี เปลี่ยนเมลได้ 100%', 'shop1.png'),
('ProGamer Store', 'ไอดีเทพ ไอเทมแน่น ราคาถูก ส่งไว', 'shop2.png'),
('EZ Roblox Shop', 'ร้านเก่าแก่ ขายมานานกว่า 3 ปี ไว้ใจได้', 'shop3.png');

-- ---------------------------------------------
-- ตัวอย่างแมพ (เกมยอดนิยมใน Roblox)
-- ---------------------------------------------
INSERT INTO maps (name, description, image) VALUES
('Blox Fruits', 'ไอดีสาย Blox Fruits เลเวลสูง ผลไม้หายาก', 'map1.png'),
('Adopt Me', 'ไอดีมีสัตว์เลี้ยงหายาก Legendary/Mythical เพียบ', 'map2.png'),
('Pet Simulator X', 'ไอดีมีเพทเทพ Huge/Titanic', 'map3.png'),
('Grow a Garden', 'ไอดีสวนสวย ไอเทมแน่น', 'map4.png'),
('Arsenal', 'ไอดีมีสกินปืนเยอะ แรงค์สูง', 'map5.png');

-- ---------------------------------------------
-- ตัวอย่างสินค้า
-- ---------------------------------------------
INSERT INTO products (shop_id, map_id, title, description, price, account_detail, status) VALUES
(1, 1, 'ไอดี Blox Fruits เลเวลแม็กซ์ + ผล Dragon', 'เลเวล 2550 มีผล Dragon Awaken เงินในเกม 500M', 590.00, 'user: bf_acc001 / pass: xxxx1234 (ข้อมูลจริงจะส่งหลังชำระเงิน)', 'available'),
(1, 3, 'ไอดี Pet Simulator X เพท Huge Cat', 'มีเพท Huge จำนวน 5 ตัว เงิน 10B', 450.00, 'user: psx_acc002 / pass: xxxx5678', 'available'),
(2, 2, 'ไอดี Adopt Me สัตว์ Legendary 20+ ตัว', 'มี Shadow Dragon, Frost Dragon, Bat Dragon', 890.00, 'user: am_acc003 / pass: xxxx9012', 'available'),
(2, 5, 'ไอดี Arsenal แรงค์ Sergeant สกินครบ', 'สกินปืนกว่า 30 แบบ แรงค์สูง', 250.00, 'user: ars_acc004 / pass: xxxx3456', 'available'),
(3, 4, 'ไอดี Grow a Garden สวนเต็มไอเทมหายาก', 'ไอเทมสวนหายากครบชุด', 320.00, 'user: gag_acc005 / pass: xxxx7890', 'available'),
(3, 1, 'ไอดี Blox Fruits สาย Sword หายาก', 'มีดาบ True Triple Katana + เลเวล 2000', 490.00, 'user: bf_acc006 / pass: xxxx2468', 'available');
