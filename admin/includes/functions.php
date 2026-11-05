<?php
/**
 * ฟังก์ชันช่วยเหลือที่ใช้ทั่วทั้งระบบ
 */

// ป้องกัน XSS เวลาแสดงผลข้อมูลที่มาจากผู้ใช้
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// เช็คว่าล็อกอินอยู่หรือไม่
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// เช็คว่าเป็นแอดมินหรือไม่
function isAdmin() {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
}

// บังคับให้ล็อกอินก่อนเข้าหน้านี้ ไม่งั้นเด้งไปหน้า login
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

// บังคับให้เป็นแอดมินเท่านั้น
function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

// จัดรูปแบบราคาเป็นบาท
function formatMoney($amount) {
    return number_format((float)$amount, 2) . ' บาท';
}

// ดึงข้อมูล user ปัจจุบันแบบสดจาก DB (ยอด wallet อัปเดตล่าสุด)
function getCurrentUser($conn) {
    if (!isLoggedIn()) return null;
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// สร้างข้อความแจ้งเตือนแบบ flash message (เก็บใน session แล้วลบทิ้งหลังแสดงครั้งเดียว)
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// อัปโหลดไฟล์รูปภาพอย่างปลอดภัย คืน ['success'=>true,'filename'=>...] หรือ ['success'=>false,'error'=>ข้อความภาษาไทย]
// สาเหตุที่ล้มเหลวจะบอกให้ชัด และบันทึกรายละเอียดฝั่งเซิร์ฟเวอร์ลง error.log ของ Apache/PHP
function uploadImage($fileInputName, $targetDir, $errorMsg = null) {
    $fail = function ($msg) { return ['success' => false, 'error' => $msg]; };

    if (!isset($_FILES[$fileInputName])) {
        return $fail('กรุณาเลือกไฟล์รูปภาพ');
    }
    $file = $_FILES[$fileInputName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_NO_FILE:
                return $fail('กรุณาเลือกไฟล์รูปภาพ');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $fail('ไฟล์ใหญ่เกินไป (เซิร์ฟเวอร์รับได้ไม่เกิน ' . ini_get('upload_max_filesize') . ') กรุณาลดขนาดรูปแล้วลองใหม่');
            case UPLOAD_ERR_PARTIAL:
                return $fail('อัปโหลดไม่สมบูรณ์ กรุณาลองใหม่อีกครั้ง');
            default: // NO_TMP_DIR / CANT_WRITE / EXTENSION = ตั้งค่าเซิร์ฟเวอร์ผิด
                error_log('uploadImage: PHP upload error code ' . $file['error'] . ' (upload_tmp_dir=' . ini_get('upload_tmp_dir') . ')');
                return $fail('เซิร์ฟเวอร์รับไฟล์ไม่ได้ (ตั้งค่าโฟลเดอร์ชั่วคราวของ PHP ไม่ถูกต้อง) กรุณาแจ้งแอดมิน');
        }
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return $fail('ขนาดไฟล์ต้องไม่เกิน ' . round(MAX_UPLOAD_SIZE / 1048576) . 'MB');
    }

    // ตรวจชนิดไฟล์จากเนื้อไฟล์จริง (ไม่เชื่อนามสกุลที่ผู้ใช้ส่งมา)
    $mime = false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }
    if (!$mime) { // เผื่อเซิร์ฟเวอร์ไม่ได้เปิด extension fileinfo
        $info = @getimagesize($file['tmp_name']);
        $mime = $info['mime'] ?? false;
    }

    $extMap = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$mime || !isset($extMap[$mime])) {
        $msg = 'รองรับเฉพาะไฟล์รูปภาพ JPG, PNG, WEBP เท่านั้น';
        if (in_array($mime, ['image/heic', 'image/heif'], true)) {
            $msg .= ' (ไฟล์ HEIC จาก iPhone ใช้ไม่ได้ ให้แคปหน้าจอสลิปแล้วอัปโหลดรูปแคปแทน)';
        }
        return $fail($msg);
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return $fail('ไฟล์รูปภาพเสียหายหรืออ่านไม่ได้ กรุณาเลือกไฟล์ใหม่');
    }

    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        error_log('uploadImage: cannot create directory ' . $targetDir);
        return $fail('เซิร์ฟเวอร์สร้างโฟลเดอร์เก็บไฟล์ไม่ได้ กรุณาแจ้งแอดมิน');
    }
    if (!is_writable($targetDir)) {
        error_log('uploadImage: directory not writable ' . $targetDir);
        return $fail('เซิร์ฟเวอร์ไม่มีสิทธิ์บันทึกไฟล์ลงโฟลเดอร์ปลายทาง กรุณาแจ้งแอดมิน');
    }

    // ตั้งนามสกุลจากชนิดไฟล์ที่ตรวจแล้ว (ไม่ใช้นามสกุลที่ผู้ใช้ส่งมา กันอัปโหลดไฟล์ .php ปลอมเป็นรูป)
    $newName = uniqid('img_', true) . '.' . $extMap[$mime];
    $destination = rtrim($targetDir, '/\\') . '/' . $newName;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => true, 'filename' => $newName];
    }
    $last = error_get_last();
    error_log('uploadImage: move_uploaded_file failed to ' . $destination . ' — ' . ($last['message'] ?? 'unknown'));
    return $fail('บันทึกไฟล์ไม่สำเร็จ กรุณาลองใหม่ ถ้ายังไม่ได้ให้แจ้งแอดมิน');
}

// ป้องกัน CSRF แบบง่าย
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

// ============================================================
// ระบบบันทึก Log (Audit Log) สำหรับให้แอดมินตรวจสอบย้อนหลัง
// ============================================================

// ดึง IP ของผู้ใช้ (ใช้ REMOTE_ADDR เท่านั้น เพราะ X-Forwarded-For ปลอมได้ง่าย
// ถ้าเว็บอยู่หลัง proxy/Cloudflare ให้ปรับตรงนี้ให้เชื่อ header เฉพาะจาก proxy ที่ไว้ใจ)
function getClientIp() {
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

/**
 * บันทึกกิจกรรมลงตาราง activity_logs
 *
 * @param mysqli      $conn
 * @param string      $action      รหัสการกระทำ เช่น 'login_success', 'topup_approve'
 * @param string|null $targetType  ประเภทข้อมูลที่ถูกกระทำ เช่น 'user','product','topup','order'
 * @param int|null    $targetId    id ของข้อมูลนั้น
 * @param array       $details     รายละเอียดเพิ่มเติม (เก็บเป็น JSON) ห้ามใส่รหัสผ่าน/ข้อมูลบัญชีไอดี
 * @param array|null  $actor       กำหนดผู้กระทำเอง ['id'=>..,'username'=>..,'role'=>..] (ปกติดึงจาก session)
 *
 * ฟังก์ชันนี้จะไม่ throw error — ถ้าบันทึก log ไม่ได้ ระบบหลักต้องทำงานต่อได้
 */
function logActivity($conn, $action, $targetType = null, $targetId = null, array $details = [], $actor = null) {
    try {
        $userId   = $actor['id']       ?? ($_SESSION['user_id']  ?? null);
        $username = $actor['username'] ?? ($_SESSION['username'] ?? null);
        $role     = $actor['role']     ?? ($_SESSION['role']     ?? null);
        $ip       = getClientIp();
        $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $detailJson = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $stmt = $conn->prepare(
            "INSERT INTO activity_logs (user_id, username, role, action, target_type, target_id, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('issssisss', $userId, $username, $role, $action, $targetType, $targetId, $detailJson, $ip, $ua);
        $ok = $stmt->execute();
        if ($ok && function_exists('notifyOnActivity')) {
            notifyOnActivity($conn, $action, $targetType, $targetId); // แจ้ง Discord (ดู includes/notify.php)
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('logActivity failed: ' . $e->getMessage());
        return false;
    }
}

// เปรียบเทียบข้อมูลเก่า/ใหม่ คืนเฉพาะ field ที่เปลี่ยน รูปแบบ ['price' => ['from'=>100,'to'=>200]]
function diffFields(array $old, array $new, array $fields) {
    $changes = [];
    foreach ($fields as $f) {
        $o = $old[$f] ?? null;
        $n = $new[$f] ?? null;
        if ((string)$o !== (string)$n) {
            $changes[$f] = ['from' => $o, 'to' => $n];
        }
    }
    return $changes;
}

// ชื่อการกระทำภาษาไทย + หมวดหมู่ + สี badge สำหรับหน้า admin/logs.php
function logActionMeta() {
    return [
        // [ป้ายชื่อ, หมวด, สี bootstrap]
        'register'            => ['สมัครสมาชิก',            'auth',    'info'],
        'login_success'       => ['เข้าสู่ระบบสำเร็จ',        'auth',    'success'],
        'login_failed'        => ['เข้าสู่ระบบไม่สำเร็จ',      'auth',    'danger'],
        'login_banned'        => ['พยายามเข้าด้วยบัญชีที่ถูกระงับ', 'auth',  'warning'],
        'logout'              => ['ออกจากระบบ',             'auth',    'secondary'],
        'password_reset_request' => ['ขอรีเซ็ตรหัสผ่าน',      'auth',    'info'],
        'password_reset_done' => ['ตั้งรหัสผ่านใหม่สำเร็จ',    'auth',    'success'],
        'admin_reset_link'    => ['แอดมินสร้างลิงก์รีเซ็ตรหัส', 'admin',   'warning'],
        'topup_request'       => ['แจ้งเติมเงิน',            'money',   'info'],
        'topup_approve'       => ['อนุมัติเติมเงิน',          'money',   'success'],
        'topup_reject'        => ['ปฏิเสธเติมเงิน',           'money',   'danger'],
        'topup_auto_approve'  => ['เติมเงินอัตโนมัติ (ตรวจสลิปผ่าน)', 'money', 'success'],
        'topup_auto_reject'   => ['ระบบปฏิเสธสลิปอัตโนมัติ',     'money',   'danger'],
        'wallet_adjust'       => ['แอดมินปรับยอด Wallet',    'money',   'warning'],
        'order_created'       => ['ซื้อสินค้าสำเร็จ',         'order',   'success'],
        'order_failed'        => ['ซื้อสินค้าไม่สำเร็จ',       'order',   'danger'],
        'user_ban'            => ['ระงับบัญชีผู้ใช้',          'admin',   'danger'],
        'user_unban'          => ['ปลดระงับบัญชีผู้ใช้',       'admin',   'success'],
        'shop_create'         => ['เพิ่มร้านค้า',             'admin',   'primary'],
        'shop_update'         => ['แก้ไขร้านค้า',             'admin',   'primary'],
        'shop_delete'         => ['ลบร้านค้า',               'admin',   'danger'],
        'map_create'          => ['เพิ่มแมพ',                'admin',   'primary'],
        'map_update'          => ['แก้ไขแมพ',                'admin',   'primary'],
        'map_delete'          => ['ลบแมพ',                  'admin',   'danger'],
        'product_create'      => ['เพิ่มสินค้า',              'admin',   'primary'],
        'product_update'      => ['แก้ไขสินค้า',              'admin',   'primary'],
        'product_delete'      => ['ลบสินค้า',                'admin',   'danger'],
        'chat_start'          => ['เริ่มแชทกับแอดมิน',        'chat',    'info'],
        'chat_close'          => ['แอดมินปิดเรื่องแชท',        'admin',   'secondary'],
        'chat_reopen'         => ['แอดมินเปิดเรื่องแชทอีกครั้ง', 'admin',   'primary'],
    ];
}

function logCategoryLabels() {
    return [
        'auth'     => 'การเข้าสู่ระบบ',
        'money'    => 'การเงิน/Wallet',
        'order'    => 'คำสั่งซื้อ',
        'admin'    => 'การจัดการของแอดมิน',
        'chat'     => 'แชท',
        'security' => 'ความปลอดภัย',
    ];
}

// ============================================================
// ระบบลืมรหัสผ่าน / ส่งอีเมล
// ============================================================

/**
 * สร้างลิงก์รีเซ็ตรหัสผ่าน คืน token จริง (เก็บใน DB เฉพาะ hash)
 * token เก่าที่ยังไม่ถูกใช้ของผู้ใช้คนนี้จะถูกยกเลิกทั้งหมด
 */
function createPasswordReset($conn, $userId, $ttlMinutes, $adminId = null) {
    $stmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ip = getClientIp();
    $ttl = (int)$ttlMinutes;
    $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip, created_by_admin)
                            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?)");
    $stmt->bind_param('isisi', $userId, $hash, $ttl, $ip, $adminId);
    $stmt->execute();
    return $token;
}

function passwordResetUrl($token) {
    return SITE_URL . '/reset_password.php?token=' . $token;
}

// หา token ที่ยังใช้ได้ (ไม่หมดอายุ ไม่เคยใช้) คืนแถวพร้อมข้อมูล user หรือ null
function findValidPasswordReset($conn, $token) {
    if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) return null;
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT r.*, u.username, u.email FROM password_resets r
                            JOIN users u ON u.id = r.user_id
                            WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

// ส่งอีเมลผ่าน SMTP (PHPMailer) คืน true/false ไม่ throw
function sendMail($to, $subject, $htmlBody) {
    if (!MAIL_ENABLED || MAIL_USER === '') return false;
    try {
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USER;
        $mail->Password = MAIL_PASS;
        $mail->SMTPSecure = MAIL_SECURE === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = MAIL_PORT;
        $mail->Timeout = 10;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $htmlBody));
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('sendMail failed: ' . $e->getMessage());
        return false;
    }
}
require_once __DIR__ . '/notify.php';
