<?php
/**
 * API แชทฝั่งแอดมิน (ตอบเป็น JSON) — เฉพาะแอดมินเท่านั้น
 *   GET  ?action=unread                         จำนวนที่ยังไม่อ่านทั้งหมด
 *   GET  ?action=threads&filter=&q=             รายการห้องแชท
 *   GET  ?action=fetch&thread_id=&after=&mark=  ข้อความในห้อง + ข้อมูลผู้ใช้
 *   POST action=send, thread_id, message, csrf_token
 *   POST action=set_status, thread_id, status(open|closed), csrf_token
 */
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/chat.php';

if (!isAdmin()) chatJson(['ok' => false, 'error' => 'ไม่มีสิทธิ์'], 401);

$adminId  = (int)$_SESSION['user_id'];
$action   = $_GET['action'] ?? ($_POST['action'] ?? '');
$isPost   = $_SERVER['REQUEST_METHOD'] === 'POST';
$csrfSent = $_POST['csrf_token'] ?? '';

session_write_close(); // ปล่อย session lock กันการ polling ทำให้หน้าอื่นค้าง

function findThreadWithUser($conn, $threadId) {
    $stmt = $conn->prepare("SELECT t.*, u.username, u.email, u.wallet_balance, u.status AS user_status, u.created_at AS user_since
                            FROM chat_threads t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

try {
    if ($action === 'unread') {
        chatJson(['ok' => true, 'unread' => chatAdminUnreadTotal($conn)]);
    }

    if ($action === 'threads') {
        $filter = $_GET['filter'] ?? 'all';
        $q = trim($_GET['q'] ?? '');
        $where = ['t.last_preview IS NOT NULL'];
        $params = []; $types = '';
        if ($filter === 'unread') $where[] = 't.admin_unread > 0';
        elseif ($filter === 'open') $where[] = "t.status = 'open'";
        elseif ($filter === 'closed') $where[] = "t.status = 'closed'";
        if ($q !== '') {
            $where[] = 'u.username LIKE ?';
            $params[] = '%' . $q . '%'; $types .= 's';
        }
        $sql = "SELECT t.id, t.status, t.last_message_at, t.last_preview, t.admin_unread, u.username
                FROM chat_threads t JOIN users u ON u.id = t.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY t.last_message_at DESC LIMIT 100";
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $threads = [];
        while ($r = $res->fetch_assoc()) {
            $threads[] = [
                'id' => (int)$r['id'], 'username' => $r['username'], 'status' => $r['status'],
                'preview' => $r['last_preview'], 'unread' => (int)$r['admin_unread'],
                'time' => date('d/m H:i', strtotime($r['last_message_at'])),
            ];
        }
        chatJson(['ok' => true, 'threads' => $threads, 'total_unread' => chatAdminUnreadTotal($conn)]);
    }

    if ($action === 'fetch') {
        $threadId = (int)($_GET['thread_id'] ?? 0);
        $after = max(0, (int)($_GET['after'] ?? 0));
        $mark = ($_GET['mark'] ?? '') === '1';
        $thread = findThreadWithUser($conn, $threadId);
        if (!$thread) chatJson(['ok' => false, 'error' => 'ไม่พบห้องแชท'], 404);

        $messages = chatFetchMessages($conn, $threadId, $after);
        if ($mark && (int)$thread['admin_unread'] > 0) {
            $stmt = $conn->prepare("UPDATE chat_threads SET admin_unread = 0 WHERE id = ?");
            $stmt->bind_param('i', $threadId);
            $stmt->execute();
        }
        chatJson(['ok' => true, 'messages' => $messages, 'thread' => [
            'id' => (int)$thread['id'], 'user_id' => (int)$thread['user_id'], 'username' => $thread['username'],
            'email' => $thread['email'], 'wallet' => number_format((float)$thread['wallet_balance'], 2),
            'user_status' => $thread['user_status'], 'status' => $thread['status'],
            'since' => date('d/m/Y', strtotime($thread['user_since'])),
        ]]);
    }

    if ($action === 'send') {
        if (!$isPost) chatJson(['ok' => false, 'error' => 'วิธีการเรียกไม่ถูกต้อง'], 405);
        if (!verifyCsrf($csrfSent)) chatJson(['ok' => false, 'error' => 'คำขอไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่'], 403);

        $threadId = (int)($_POST['thread_id'] ?? 0);
        $thread = findThreadWithUser($conn, $threadId);
        if (!$thread) chatJson(['ok' => false, 'error' => 'ไม่พบห้องแชท'], 404);

        [$ok, $textOrError] = chatValidateMessage($_POST['message'] ?? '');
        if (!$ok) chatJson(['ok' => false, 'error' => $textOrError], 422);
        if (chatRateLimited($conn, $adminId, CHAT_ADMIN_RATE_PER_MIN)) {
            chatJson(['ok' => false, 'error' => 'ส่งข้อความถี่เกินไป กรุณารอสักครู่'], 429);
        }

        $saved = chatInsertMessage($conn, $threadId, $adminId, 'admin', $textOrError);
        chatJson(['ok' => true, 'message' => [
            'id' => $saved['id'], 'role' => 'admin', 'text' => $textOrError, 'time' => $saved['time'],
        ]]);
    }

    if ($action === 'set_status') {
        if (!$isPost) chatJson(['ok' => false, 'error' => 'วิธีการเรียกไม่ถูกต้อง'], 405);
        if (!verifyCsrf($csrfSent)) chatJson(['ok' => false, 'error' => 'คำขอไม่ถูกต้อง'], 403);

        $threadId = (int)($_POST['thread_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['open', 'closed'], true)) chatJson(['ok' => false, 'error' => 'สถานะไม่ถูกต้อง'], 422);
        $thread = findThreadWithUser($conn, $threadId);
        if (!$thread) chatJson(['ok' => false, 'error' => 'ไม่พบห้องแชท'], 404);

        $stmt = $conn->prepare("UPDATE chat_threads SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $threadId);
        $stmt->execute();
        logActivity($conn, $status === 'closed' ? 'chat_close' : 'chat_reopen', 'chat', $threadId,
            ['user' => $thread['username']]);
        chatJson(['ok' => true, 'status' => $status]);
    }

    chatJson(['ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'], 400);
} catch (Throwable $e) {
    error_log('admin chat_api error: ' . $e->getMessage());
    chatJson(['ok' => false, 'error' => 'เกิดข้อผิดพลาดของระบบ'], 500);
}
