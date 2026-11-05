<?php
/**
 * ไฟล์ตั้งค่าหลักของระบบ + เชื่อมต่อฐานข้อมูล
 */

// ---------- ตั้งค่าฐานข้อมูล (แก้ตามเครื่อง XAMPP ของคุณ) ----------
define('DB_HOST', 'localhost');
define('DB_USER', 'rbxuser');
define('DB_PASS', '123456789');
define('DB_NAME', 'roblox_market');

// ---------- ตั้งค่าเว็บไซต์ ----------
define('SITE_NAME', 'TUNG TUNG SHOP');
// Thunder Solution slip verification: enable only after adding your real API key.
define('THUNDER_ENABLED', false);
define('THUNDER_API_KEY', '');
define('THUNDER_API_URL', 'https://api.thunder.in.th/v2/verify/bank');
define('THUNDER_MAX_SLIP_AGE_HOURS', 24);
define('TOPUP_MIN_AMOUNT', 10);
define('TOPUP_MAX_AMOUNT', 100000);
define('SITE_URL', 'http://157.85.105.154/roblox-market'); // แก้ path ให้ตรงกับโฟลเดอร์จริงใน htdocs
define('UPLOAD_SLIP_DIR', __DIR__ . '/../uploads/slips/');
define('UPLOAD_PRODUCT_DIR', __DIR__ . '/../uploads/products/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// ---------- ตั้งค่าอีเมล (สำหรับส่งลิงก์ลืมรหัสผ่าน) ----------
// ถ้า MAIL_ENABLED = false ระบบจะไม่ส่งอีเมล แต่แอดมินยังสร้างลิงก์รีเซ็ตให้ผู้ใช้ได้จากหน้า "ผู้ใช้งาน"
// ตัวอย่าง Gmail: host smtp.gmail.com / port 587 / secure 'tls' / user = อีเมลเว็บ / pass = "App Password" 16 หลัก
// (ต้องเปิดยืนยัน 2 ขั้นตอนใน Google ก่อน แล้วสร้าง App Password ห้ามใช้รหัสผ่าน Gmail จริง)
define('MAIL_ENABLED', true);
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_SECURE', 'tls');            // 'tls' (port 587) หรือ 'ssl' (port 465)
define('MAIL_USER', 'rbxmarket.noreply@gmail.com');                 // อีเมลที่ใช้ส่ง
define('MAIL_PASS', 'vhwc huuv sidz jkjr');                 // App Password
define('MAIL_FROM', MAIL_USER);
define('MAIL_FROM_NAME', SITE_NAME);
define('RESET_TOKEN_TTL_MIN', 30);       // ลิงก์จากอีเมลหมดอายุภายใน (นาที)
define('RESET_ADMIN_TTL_MIN', 1440);     // ลิงก์ที่แอดมินสร้างให้ หมดอายุภายใน (นาที) = 24 ชม.
define('DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/1561171624131170365/hLPQAJqNeYHeWpp9-SwlyMgXzGtTxsRSOvJN1mmNG5cf8ZaP33nAznu-Zb2sf4A00ZyK');
define('DISCORD_MENTION', '');

// ---------- เริ่ม session ----------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Bangkok');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ---------- เชื่อมต่อฐานข้อมูล (mysqli) ----------
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $conn->connect_error .
        '<br>กรุณาตรวจสอบว่าได้ import ไฟล์ config/database.sql และตั้งค่า config.php ถูกต้อง');
}
$conn->set_charset('utf8mb4');
