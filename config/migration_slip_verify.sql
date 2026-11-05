-- ============================================================
-- Migration: ตรวจสลิปอัตโนมัติ (Thunder API) — เพิ่มคอลัมน์ในตาราง topups
-- รันครั้งเดียวผ่าน phpMyAdmin (แท็บ SQL) ถ้ารันซ้ำจะ error "Duplicate column" ซึ่งไม่เป็นอันตราย
-- ============================================================
USE roblox_market;

ALTER TABLE topups
    ADD COLUMN verify_result VARCHAR(20) DEFAULT NULL COMMENT 'auto_approved / auto_rejected / manual_review / skipped',
    ADD COLUMN verify_detail TEXT DEFAULT NULL COMMENT 'JSON ผลตรวจสลิป',
    ADD COLUMN slip_trans_ref VARCHAR(64) DEFAULT NULL COMMENT 'เลขอ้างอิงรายการจากสลิป ใช้กันสลิปซ้ำ',
    ADD UNIQUE KEY uq_slip_trans_ref (slip_trans_ref);
