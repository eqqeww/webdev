<?php
/**
 * แจ้งเตือนแอดมินเมื่อมีการเติมเงิน ผ่าน Discord Webhook
 *
 * ทำงานอัตโนมัติผ่าน logActivity(): เมื่อมี log ของ topup_request / topup_auto_approve / topup_auto_reject
 * ระบบจะอ่านรายการจากฐานข้อมูลแล้วส่งข้อความเข้าห้อง Discord ที่ตั้งไว้
 * (จึงใช้ได้ทั้งแบบแอดมินอนุมัติเอง และแบบตรวจสลิปอัตโนมัติ โดยไม่ต้องแก้ topup.php)
 *
 * ตั้งค่าใน config/config.php:
 *   define('DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/xxxx/yyyy');
 *   define('DISCORD_MENTION', '');   // ไม่บังคับ เช่น '@here' หรือ '<@123456789012345678>' (id ผู้ใช้) หรือ '<@&id role>'
 * ถ้า DISCORD_WEBHOOK_URL ว่าง = ปิดการส่ง Discord (แจ้งเตือนในหน้าแอดมินยังทำงานปกติ)
 */

if (!defined('DISCORD_WEBHOOK_URL')) define('DISCORD_WEBHOOK_URL', '');
if (!defined('DISCORD_MENTION'))     define('DISCORD_MENTION', '');

// URL ของ webhook ต้องเป็นของ Discord จริงเท่านั้น (กันตั้งค่าผิด/กันส่งข้อมูลออกไปที่อื่น)
function discordWebhookEnabled() {
    return DISCORD_WEBHOOK_URL !== ''
        && preg_match('#^https://(?:canary\.|ptb\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#', DISCORD_WEBHOOK_URL) === 1;
}

// ส่ง payload ไป Discord คืน true/false (ไม่ throw) timeout สั้น เพื่อไม่ให้หน้าเว็บของลูกค้าค้างถ้า Discord ช้า
function discordSend(array $payload) {
    if (!discordWebhookEnabled() || !function_exists('curl_init')) return false;

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        // errno 60 = SSL certificate problem (XAMPP Windows ต้องตั้ง curl.cainfo ใน php.ini)
        error_log("discordSend curl error #$errno: $err");
        return false;
    }
    if ($http < 200 || $http >= 300) {
        error_log("discordSend failed: HTTP $http"); // 401/404 = URL webhook ผิดหรือถูกลบ, 429 = ส่งถี่เกิน
        return false;
    }
    return true;
}

function notifyCut($s, $max) {
    $s = (string)$s;
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/**
 * สร้างข้อความ Discord (ฟังก์ชันล้วน ๆ ทดสอบง่าย)
 * @param array  $t    แถวจากตาราง topups + username
 * @param string $kind new | review | approved | rejected
 */
function buildTopupNotification(array $t, $kind) {
    $styles = [
        'new'      => ['💰 มีคำขอเติมเงินใหม่ (รอตรวจสอบ)',        0xFFB020],
        'review'   => ['⚠️ สลิปตรวจอัตโนมัติไม่ผ่าน — ต้องตรวจเอง', 0xFF8C00],
        'approved' => ['✅ เติมเงินอัตโนมัติสำเร็จ',               0x2ECC71],
        'rejected' => ['🚫 ระบบปฏิเสธสลิปอัตโนมัติ',              0xFF5470],
    ];
    [$title, $color] = $styles[$kind] ?? $styles['new'];

    $fields = [
        ['name' => 'ผู้ใช้',     'value' => notifyCut($t['username'] ?? '-', 100), 'inline' => true],
        ['name' => 'จำนวนเงิน', 'value' => number_format((float)$t['amount'], 2) . ' บาท', 'inline' => true],
        ['name' => 'รายการ #',  'value' => (string)(int)$t['id'], 'inline' => true],
    ];
    if (!empty($t['admin_note']) && $kind !== 'new') {
        $fields[] = ['name' => 'รายละเอียด', 'value' => notifyCut($t['admin_note'], 500), 'inline' => false];
    }
    if (!empty($t['note']) && $kind === 'new') {
        $fields[] = ['name' => 'หมายเหตุผู้ใช้', 'value' => notifyCut($t['note'], 300), 'inline' => false];
    }

    $payload = [
        'embeds' => [[
            'title'     => $title,
            'url'       => SITE_URL . '/admin/topups.php?status=' . ($kind === 'approved' ? 'approved' : ($kind === 'rejected' ? 'rejected' : 'pending')),
            'color'     => $color,
            'fields'    => $fields,
            'footer'    => ['text' => SITE_NAME],
            'timestamp' => gmdate('c'),
        ]],
    ];
    // ไม่ใส่รูปสลิป/อีเมลลง Discord โดยตั้งใจ (มีข้อมูลบัญชีธนาคาร) ให้กดลิงก์เข้าหน้าแอดมินดูแทน
    if (DISCORD_MENTION !== '') $payload['content'] = DISCORD_MENTION;
    return $payload;
}

// เลือกประเภทข้อความจาก event + สถานะรายการ คืน null ถ้าไม่ต้องแจ้ง (กันแจ้งซ้ำ)
function topupNotifyKind($event, array $t) {
    if ($event === 'topup_auto_approve') return 'approved';
    if ($event === 'topup_auto_reject')  return 'rejected';
    if ($event === 'topup_request') {
        // ถ้าอนุมัติ/ปฏิเสธอัตโนมัติไปแล้ว จะมี event ของมันแจ้งเอง ไม่ต้องแจ้งซ้ำ
        if ($t['status'] !== 'pending') return null;
        return ($t['verify_result'] ?? '') === 'manual_review' ? 'review' : 'new';
    }
    return null;
}

// ถูกเรียกจาก logActivity() ทุกครั้งที่บันทึก log สำเร็จ
function notifyOnActivity($conn, $action, $targetType, $targetId) {
    if ($targetType !== 'topup' || !$targetId) return;
    if (!in_array($action, ['topup_request', 'topup_auto_approve', 'topup_auto_reject'], true)) return;
    if (!discordWebhookEnabled()) return;

    try {
        $id = (int)$targetId;
        $stmt = $conn->prepare("SELECT t.*, u.username FROM topups t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        if (!$t) return;

        $kind = topupNotifyKind($action, $t);
        if ($kind === null) return;
        discordSend(buildTopupNotification($t, $kind));
    } catch (Throwable $e) {
        error_log('notifyOnActivity failed: ' . $e->getMessage()); // ห้ามกระทบการเติมเงินของลูกค้า
    }
}
