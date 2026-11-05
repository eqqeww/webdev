<?php
/**
 * API แชทฝั่งผู้ใช้ (ตอบเป็น JSON)
 *   GET  ?action=unread                      จำนวนข้อความจากแอดมินที่ยังไม่อ่าน
 *   GET  ?action=fetch&after=ID&mark=1       ดึงข้อความ (mark=1 = ทำเครื่องหมายว่าอ่านแล้ว)
 *   POST action=send, message, csrf_token    ส่งข้อความหาแอดมิน
 */
require_once 'config/config.php';
require_once 'includes/functions.php';
require_once 'includes/chat.php';

if (!isLoggedIn()) chatJson(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบ'], 401);
if (isAdmin()) chatJson(['ok' => false, 'error' => 'แอดมินใช้แชทที่หน้าแผงแอดมิน'], 403);

$userId   = (int)$_SESSION['user_id'];
$action   = $_GET['action'] ?? ($_POST['action'] ?? '');
$isPost   = $_SERVER['REQUEST_METHOD'] === 'POST';
$csrfSent = $_POST['csrf_token'] ?? '';

// ปล่อย session lock ทันที (ไม่งั้นการ polling จะทำให้ทุกหน้าของผู้ใช้คนนี้ช้า/ค้าง)
// หลังจากนี้ยังอ่าน $_SESSION ได้ตามปกติ แต่ห้ามเขียนค่าใหม่
session_write_close();

try {
    // บัญชีที่ถูกระงับห้ามใช้งานแชท
    $stmt = $conn->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    if (!$u || $u['status'] === 'banned') chatJson(['ok' => false, 'error' => 'บัญชีนี้ไม่สามารถใช้งานแชทได้'], 403);

    if ($action === 'unread') {
        chatJson(['ok' => true, 'unread' => chatUserUnread($conn, $userId)]);
    }

    if ($action === 'fetch') {
        $after  = max(0, (int)($_GET['after'] ?? 0));
        $mark   = ($_GET['mark'] ?? '') === '1';
        $thread = chatFindThreadByUser($conn, $userId);
        if (!$thread) chatJson(['ok' => true, 'messages' => [], 'status' => 'none']);

        $messages = chatFetchMessages($conn, (int)$thread['id'], $after);
        if ($mark && (int)$thread['user_unread'] > 0) {
            $stmt = $conn->prepare("UPDATE chat_threads SET user_unread = 0 WHERE id = ?");
            $stmt->bind_param('i', $thread['id']);
            $stmt->execute();
        }
        chatJson(['ok' => true, 'messages' => $messages, 'status' => $thread['status']]);
    }

    if ($action === 'send') {
        if (!$isPost) chatJson(['ok' => false, 'error' => 'วิธีการเรียกไม่ถูกต้อง'], 405);
        if (!verifyCsrf($csrfSent)) chatJson(['ok' => false, 'error' => 'คำขอไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่'], 403);

        [$ok, $textOrError] = chatValidateMessage($_POST['message'] ?? '');
        if (!$ok) chatJson(['ok' => false, 'error' => $textOrError], 422);

        if (chatRateLimited($conn, $userId, CHAT_USER_RATE_PER_MIN)) {
            chatJson(['ok' => false, 'error' => 'ส่งข้อความถี่เกินไป กรุณารอสักครู่'], 429);
        }

        $thread = chatGetOrCreateThread($conn, $userId);
        $isFirst = ($thread['last_preview'] === null);
        $saved = chatInsertMessage($conn, (int)$thread['id'], $userId, 'user', $textOrError);

        if ($isFirst) {
            logActivity($conn, 'chat_start', 'chat', (int)$thread['id']);
        }
        chatJson(['ok' => true, 'message' => [
            'id' => $saved['id'], 'role' => 'user', 'text' => $textOrError, 'time' => $saved['time'],
        ]]);
    }

    chatJson(['ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'], 400);
} catch (Throwable $e) {
    error_log('chat_api error: ' . $e->getMessage());
    chatJson(['ok' => false, 'error' => 'เกิดข้อผิดพลาดของระบบ (ตรวจสอบว่า import ตารางแชทแล้ว)'], 500);
}
