<?php
/**
 * ระบบแชทผู้ใช้ <-> แอดมิน (ฟังก์ชันกลาง)
 * - 1 ผู้ใช้ = 1 ห้อง (chat_threads) มีข้อความหลายอัน (chat_messages)
 * - ใช้ AJAX polling ไม่ต้องใช้ WebSocket จึงรันบน XAMPP/Apache ธรรมดาได้
 */

define('CHAT_MAX_LEN', 1000);        // ความยาวข้อความสูงสุด (ตัวอักษร)
define('CHAT_USER_RATE_PER_MIN', 15);  // ผู้ใช้ส่งได้ไม่เกินกี่ข้อความ/นาที
define('CHAT_ADMIN_RATE_PER_MIN', 40); // แอดมินส่งได้ไม่เกินกี่ข้อความ/นาที

// จำนวนข้อความจากแอดมินที่ผู้ใช้ยังไม่อ่าน (คืน 0 ถ้ายังไม่ได้ import ตาราง จะได้ไม่ทำให้ทั้งเว็บพัง)
function chatUserUnread($conn, $userId) {
    try {
        $stmt = $conn->prepare("SELECT user_unread FROM chat_threads WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['user_unread'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

// จำนวนข้อความจากผู้ใช้ทั้งหมดที่แอดมินยังไม่อ่าน
function chatAdminUnreadTotal($conn) {
    try {
        $res = $conn->query("SELECT COALESCE(SUM(admin_unread),0) AS s FROM chat_threads");
        return (int)$res->fetch_assoc()['s'];
    } catch (Throwable $e) {
        return 0;
    }
}

function chatFindThreadByUser($conn, $userId) {
    $stmt = $conn->prepare("SELECT * FROM chat_threads WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function chatGetOrCreateThread($conn, $userId) {
    $stmt = $conn->prepare("INSERT IGNORE INTO chat_threads (user_id) VALUES (?)");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return chatFindThreadByUser($conn, $userId);
}

// ดึงข้อความ: after=0 → 100 ข้อความล่าสุด, after>0 → เฉพาะข้อความใหม่กว่า id นั้น
function chatFetchMessages($conn, $threadId, $after) {
    if ($after <= 0) {
        $stmt = $conn->prepare("SELECT * FROM (SELECT * FROM chat_messages WHERE thread_id = ? ORDER BY id DESC LIMIT 100) t ORDER BY id ASC");
        $stmt->bind_param('i', $threadId);
    } else {
        $stmt = $conn->prepare("SELECT * FROM chat_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC LIMIT 100");
        $stmt->bind_param('ii', $threadId, $after);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'id'   => (int)$row['id'],
            'role' => $row['sender_role'],
            'text' => $row['message'],
            'time' => date('d/m H:i', strtotime($row['created_at'])),
        ];
    }
    return $out;
}

// ตรวจและทำความสะอาดข้อความ คืน [true, ข้อความ] หรือ [false, ข้อผิดพลาด]
function chatValidateMessage($raw) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$raw);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
    $text = trim($text);
    if ($text === '') return [false, 'กรุณาพิมพ์ข้อความ'];
    if (mb_strlen($text) > CHAT_MAX_LEN) return [false, 'ข้อความยาวเกินไป (สูงสุด ' . CHAT_MAX_LEN . ' ตัวอักษร)'];
    // ตัดบรรทัดว่างติดกันเกิน 2 บรรทัด
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return [true, $text];
}

// กันสแปม: นับข้อความที่ผู้ส่งคนนี้ส่งใน 60 วินาทีที่ผ่านมา
function chatRateLimited($conn, $senderId, $limit) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM chat_messages WHERE sender_id = ? AND created_at > NOW() - INTERVAL 60 SECOND");
    $stmt->bind_param('i', $senderId);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['c'] >= $limit;
}

// บันทึกข้อความ + อัปเดตห้อง (ในทรานแซกชันเดียว) คืน ['id'=>..., 'time'=>...] ของข้อความใหม่
function chatInsertMessage($conn, $threadId, $senderId, $role, $text) {
    $preview = mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 120);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO chat_messages (thread_id, sender_id, sender_role, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiss', $threadId, $senderId, $role, $text);
        if (!$stmt->execute()) throw new RuntimeException('insert message failed: ' . $stmt->error);
        $newId = (int)$conn->insert_id;

        if ($role === 'user') {
            $stmt = $conn->prepare("UPDATE chat_threads SET last_message_at = NOW(), last_preview = ?, admin_unread = admin_unread + 1, status = 'open' WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE chat_threads SET last_message_at = NOW(), last_preview = ?, user_unread = user_unread + 1, status = 'open' WHERE id = ?");
        }
        $stmt->bind_param('si', $preview, $threadId);
        if (!$stmt->execute()) throw new RuntimeException('update thread failed: ' . $stmt->error);

        // อ่านเวลาจาก DB เพื่อให้รูปแบบ/เขตเวลาตรงกับข้อความอื่นที่ดึงมา
        $stmt = $conn->prepare("SELECT created_at FROM chat_messages WHERE id = ?");
        $stmt->bind_param('i', $newId);
        $stmt->execute();
        $createdAt = $stmt->get_result()->fetch_assoc()['created_at'];

        $conn->commit();
        return ['id' => $newId, 'time' => date('d/m H:i', strtotime($createdAt))];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// ตอบกลับเป็น JSON แล้วจบการทำงาน
function chatJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
