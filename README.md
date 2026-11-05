# TUNG TUNG SHOP - เว็บซื้อขายไอดี Roblox (PHP + MySQL)

ระบบซื้อขายไอดี Roblox แบบครบวงจร รองรับระบบสมาชิก, Wallet (กระเป๋าเงิน), แจ้งเติมเงินพร้อมอัปโหลดสลิป,
เลือกร้านค้า/เลือกแมพ และระบบแอดมินจัดการทั้งหมด

## ✨ ฟีเจอร์หลัก

**ฝั่งผู้ใช้ (User)**
- สมัครสมาชิก / เข้าสู่ระบบ / ออกจากระบบ (รหัสผ่านเข้ารหัสด้วย `password_hash`)
- ระบบ Wallet (เงินในระบบ) แสดงยอดคงเหลือตลอดเวลา
- หน้าแจ้งเติมเงิน (`topup.php`) — กรอกจำนวนเงิน + อัปโหลดสลิปโอนเงิน รอแอดมินอนุมัติ
- เรียกดู/ค้นหา/กรองสินค้า (ไอดี Roblox) ตามร้านค้าและแมพ พร้อมเรียงราคา
- ซื้อไอดีด้วยยอดเงินใน Wallet (หักเงินอัตโนมัติ, กันซื้อซ้ำด้วย Transaction Lock)
- ดูประวัติคำสั่งซื้อ พร้อมข้อมูลบัญชีไอดีหลังซื้อสำเร็จ

**ฝั่งแอดมิน (Admin)**
- แดชบอร์ดสรุปยอดขาย / จำนวนสมาชิก / คำขอเติมเงินที่รอตรวจสอบ
- อนุมัติ/ปฏิเสธคำขอเติมเงิน (ดูสลิปที่แนบ แล้วเติมยอดเข้า Wallet ผู้ใช้อัตโนมัติเมื่ออนุมัติ)
- จัดการร้านค้า (เพิ่ม/แก้ไข/ลบ) พร้อมอัปโหลดโลโก้
- จัดการแมพ (เพิ่ม/แก้ไข/ลบ) พร้อมอัปโหลดรูป
- จัดการสินค้า/ไอดี ผูกกับร้านค้าและแมพ กำหนดราคา สถานะ และข้อมูลบัญชี (ซ่อนไว้จนกว่าจะขาย)
- ดูคำสั่งซื้อทั้งหมดในระบบ
- จัดการผู้ใช้งาน: ระงับ/ปลดระงับบัญชี, ปรับยอด Wallet ด้วยมือ

## 🛠 เทคโนโลยีที่ใช้
- PHP 8+ (mysqli, prepared statements ป้องกัน SQL Injection)
- MySQL / MariaDB
- Bootstrap 5 + Font Awesome (โทนสีม่วง-ทอง ธีมเดียวกันทั้งเว็บ)
- รันบน XAMPP (Apache + MySQL)

## 📦 วิธีติดตั้งบน XAMPP

1. **ติดตั้ง XAMPP** (ถ้ายังไม่มี) จาก https://www.apachefriends.org
2. **แตกไฟล์ zip** นี้แล้ว **คัดลอกโฟลเดอร์ `roblox-market`** ทั้งหมดไปไว้ที่:
   - Windows: `C:\xampp\htdocs\roblox-market`
   - macOS/Linux: `/Applications/XAMPP/htdocs/roblox-market` หรือ `/opt/lampp/htdocs/roblox-market`
3. **เปิด XAMPP Control Panel** แล้ว Start `Apache` และ `MySQL`
4. **สร้างฐานข้อมูล**
   - เปิดเบราว์เซอร์ไปที่ `http://localhost/phpmyadmin`
   - คลิกแท็บ **Import** → เลือกไฟล์ `roblox-market/config/database.sql` → กด **Go**
   - ระบบจะสร้างฐานข้อมูลชื่อ `roblox_market` พร้อมตาราง, บัญชีแอดมิน และข้อมูลตัวอย่าง (ร้านค้า/แมพ/สินค้า) ให้อัตโนมัติ
5. **ตั้งค่าไฟล์ config**
   - เปิดไฟล์ `roblox-market/config/config.php`
   - ตรวจสอบค่า `DB_USER`, `DB_PASS` ให้ตรงกับ MySQL ของคุณ (ค่าเริ่มต้นของ XAMPP คือ user: `root`, pass: ว่าง)
   - แก้ `SITE_URL` ให้ตรงกับ path จริง เช่น `http://localhost/roblox-market`
6. **ตั้งสิทธิ์โฟลเดอร์อัปโหลด**
   - ตรวจสอบว่าโฟลเดอร์ `uploads/slips/` และ `uploads/products/` เขียนไฟล์ได้ (chmod 755 หรือมากกว่า บน macOS/Linux)
7. **เปิดใช้งานเว็บไซต์**
   - ไปที่ `http://localhost/roblox-market/index.php`

## 🔑 บัญชีทดสอบ

| บทบาท | Username | Password |
|--------|----------|----------|
| แอดมิน | `admin`  | `admin1234` |

> สมัครสมาชิกใหม่ผ่านหน้า `register.php` เพื่อทดสอบฝั่งผู้ใช้งานทั่วไป

## 📁 โครงสร้างโฟลเดอร์

```
roblox-market/
├── admin/                  # หน้าแอดมินทั้งหมด
│   ├── index.php           # แดชบอร์ด
│   ├── topups.php          # อนุมัติ/ปฏิเสธคำขอเติมเงิน
│   ├── shops.php           # จัดการร้านค้า
│   ├── maps.php            # จัดการแมพ
│   ├── products.php        # จัดการสินค้า/ไอดี
│   ├── orders.php          # ดูคำสั่งซื้อ
│   ├── users.php           # จัดการผู้ใช้งาน
│   ├── inc_header.php      # เลย์เอาต์ sidebar ของแอดมิน
│   └── inc_footer.php
├── config/
│   ├── config.php          # ตั้งค่าฐานข้อมูล + session
│   └── database.sql        # ไฟล์ SQL สำหรับ import
├── includes/
│   ├── functions.php       # ฟังก์ชันช่วยเหลือ (auth, upload, csrf, ฯลฯ)
│   ├── header.php          # เลย์เอาต์ navbar หน้าเว็บหลัก
│   └── footer.php
├── assets/css/style.css    # ธีมสีหลักของเว็บ (ม่วง-ทอง)
├── uploads/
│   ├── slips/               # เก็บรูปสลิปที่ผู้ใช้อัปโหลด
│   └── products/            # เก็บรูปสินค้า/โลโก้ร้าน/รูปแมพ
├── index.php                # หน้าแรก + filter ร้านค้า/แมพ
├── shop.php / map.php       # หน้าแสดงร้านค้า/แมพทั้งหมด
├── product.php              # หน้ารายละเอียดสินค้า
├── buy.php                  # ประมวลผลการซื้อ (หัก wallet)
├── topup.php                # แจ้งเติมเงิน + อัปโหลดสลิป
├── profile.php               # ประวัติคำสั่งซื้อของผู้ใช้
├── register.php / login.php / logout.php
└── README.md
```

## 🔒 ความปลอดภัยที่ใส่ไว้ในระบบ
- รหัสผ่านเข้ารหัสด้วย `password_hash()` / ตรวจสอบด้วย `password_verify()`
- ป้องกัน SQL Injection ด้วย Prepared Statements (mysqli) ทุกจุด
- ป้องกัน CSRF ด้วย token ในทุกฟอร์มสำคัญ
- ป้องกัน XSS ด้วยการ escape ข้อมูลทุกจุดที่แสดงผล (`htmlspecialchars`)
- ตรวจสอบชนิดไฟล์จริง (MIME type) ก่อนอัปโหลดรูป/สลิป และจำกัดขนาดไฟล์ไม่เกิน 5MB
- ปิดการรันไฟล์ PHP ในโฟลเดอร์ `uploads/` ผ่าน `.htaccess`
- ใช้ Database Transaction + `FOR UPDATE` ตอนซื้อสินค้า ป้องกันการซื้อสินค้าเดียวกันซ้ำซ้อน (race condition)
- ข้อมูลบัญชีไอดี (username/password ของไอดี Roblox) จะถูกซ่อนไว้ แสดงเฉพาะผู้ที่ซื้อสำเร็จเท่านั้น

## ⚠️ ข้อควรทำต่อก่อนใช้งานจริง (Production)
- เปลี่ยนรหัสผ่านแอดมินเริ่มต้นทันที
- ปิด `display_errors` ใน `config.php` (ตั้งเป็น production)
- เปลี่ยนไปใช้ HTTPS และตั้งค่า session cookie ให้ปลอดภัย (`secure`, `httponly`)
- พิจารณาเพิ่มระบบยืนยันอีเมล/OTP และ rate-limit การเข้าสู่ระบบ
- สำรองฐานข้อมูลสม่ำเสมอ โดยเฉพาะก่อนอนุมัติยอดเติมเงินจำนวนมาก


## ตั้งค่า Thunder API ตรวจสลิปอัตโนมัติ

1. เปิด `config/config.php` แล้วกำหนด `THUNDER_ENABLED` เป็น `true` และใส่ API key จริงใน `THUNDER_API_KEY`
2. ต้องเปิดใช้งาน PHP cURL และตั้งค่าบัญชีผู้รับในระบบ Thunder ให้ถูกต้อง
3. นำเข้า `config/migration_slip_verify.sql` เฉพาะกรณีที่ฐานข้อมูลเดิมยังไม่มีคอลัมน์ตรวจสลิป (ถ้าสร้างฐานข้อมูลใหม่ ให้ใช้ `config/database.sql` เวอร์ชันนี้)
4. ทดสอบด้วยสลิปจริงจำนวนเล็กน้อยก่อนเปิดใช้งานจริง ระบบจะส่งรายการที่ตรวจไม่ครบเงื่อนไขให้แอดมินตรวจเอง ไม่ควรใส่ API key ลงในโค้ดที่เผยแพร่สาธารณะ

ค่าเริ่มต้นปิด Thunder API ไว้ (`THUNDER_ENABLED = false`) เพื่อป้องกันการเรียก API จนกว่าจะใส่ API key
