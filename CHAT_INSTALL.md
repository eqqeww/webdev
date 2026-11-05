# ติดตั้งระบบแชทผู้ใช้ <-> แอดมิน

## 1) เพิ่มตารางในฐานข้อมูล (ทำครั้งเดียว)
phpMyAdmin → เลือกฐานข้อมูล `roblox_market` → แท็บ Import → เลือก `config/migration_chat.sql`
หรือบนเซิร์ฟเวอร์: `sudo mysql roblox_market < config/migration_chat.sql`

## 2) คัดลอกไฟล์
แตก zip นี้ทับโฟลเดอร์โปรเจกต์ (ไม่มี config.php ใน zip จึงไม่ทับรหัสผ่านของคุณ)

ไฟล์ใหม่:   chat.php, chat_api.php, includes/chat.php, admin/chat.php, admin/chat_api.php, config/migration_chat.sql
ไฟล์ที่แก้: includes/header.php, includes/functions.php, admin/inc_header.php, assets/css/style.css, config/database.sql
(ถ้าคุณแก้ 4 ไฟล์แรกเพิ่มเองหลังส่ง rar มา ให้ merge ด้วยมือ: ค้นหาคำว่า "chat" ในไฟล์เหล่านั้นจะเห็นส่วนที่เพิ่ม)

## 3) ใช้งาน
- ผู้ใช้: เมนู "แชทแอดมิน" (/chat.php) มีป้ายแดงเมื่อแอดมินตอบ
- แอดมิน: เมนู "แชทผู้ใช้" (/admin/chat.php) ซ้าย = รายการห้อง ขวา = บทสนทนา, ปิด/เปิดเรื่องได้
- ถ้าเวลาของข้อความเพี้ยนไป 7 ชม. = timezone ของ MySQL ไม่ตรง ตั้ง `default-time-zone='+07:00'` ใน my.cnf/mariadb.cnf
