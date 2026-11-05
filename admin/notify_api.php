<?php
/**
 * API แจ้งเตือนฝั่งแอดมิน (JSON) — เฉพาะแอดมิน
 *   GET  ?action=poll&since=<topup id ล่าสุดที่เห็น>   รายการเติมเงินใหม่ + จำนวนที่รอตรวจ
 *   POST action=test, csrf_token                      ส่งข้อความทดสอบไป Discord
 */
require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function notifyJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isAdmin()) notifyJson(['ok' => false, 'error' => 'ไม่มีสิทธิ์'], 401);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$csrfOk = verifyCsrf($_POST['csrf_token'] ?? '');
session_write_close(); // ปล่อย session lock กันการ polling ทำให้หน้าอื่นค้าง

if ($action === 'poll') {
    $since = max(0, (int)($_GET['since'] ?? 0));

    $row = $conn->query("SELECT COALESCE(MAX(id),0) AS latest, SUM(status='pending') AS pending FROM topups")->fetch_assoc();
    $stmt = $conn->prepare("SELECT t.id, t.amount, t.status, t.verify_result, u.username
                            FROM topups t JOIN users u ON u.id = t.user_id
                            WHERE t.id > ? ORDER BY t.id ASC LIMIT 5");
    $stmt->bind_param('i', $since);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($r = $res->fetch_assoc()) {
        $items[] = [
            'id'       => (int)$r['id'],
            'username' => $r['username'],
            'amount'   => (float)$r['amount'],
            'status'   => $r['status'],
            'auto'     => ($r['verify_result'] ?? '') === 'auto_approved',
        ];
    }
    notifyJson(['ok' => true, 'latest_id' => (int)$row['latest'], 'pending' => (int)$row['pending'], 'items' => $items]);
}

if ($action === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfOk) notifyJson(['ok' => false, 'error' => 'คำขอไม่ถูกต้อง'], 403);
    require_once '../includes/notify.php';
    if (!discordWebhookEnabled()) {
        notifyJson(['ok' => true, 'discord' => 'disabled']);
    }
    $payload = [
        'embeds'  => [[
            'title'       => '🔔 ทดสอบการแจ้งเตือน',
            'description' => 'ถ้าเห็นข้อความนี้ แปลว่าตั้งค่า Discord Webhook ถูกต้องแล้ว',
            'color'       => 0x6C5CE7,
            'footer'      => ['text' => SITE_NAME],
            'timestamp'   => gmdate('c'),
        ]],
    ];
    if (DISCORD_MENTION !== '') $payload['content'] = DISCORD_MENTION;
    $sent = discordSend($payload);
    notifyJson(['ok' => true, 'discord' => $sent ? 'sent' : 'failed']);
}

notifyJson(['ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'], 400);
