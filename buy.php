<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'คำขอไม่ถูกต้อง');
    header('Location: index.php');
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);
$userId = $_SESSION['user_id'];

$conn->begin_transaction();

try {
    // ล็อกแถวสินค้าและผู้ใช้เพื่อป้องกันการซื้อซ้ำซ้อน (race condition)
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if (!$product || $product['status'] !== 'available') {
        throw new Exception('สินค้านี้ไม่พร้อมขายแล้ว หรือถูกซื้อไปก่อนหน้านี้');
    }

    $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ((float)$user['wallet_balance'] < (float)$product['price']) {
        throw new Exception('ยอดเงินใน Wallet ไม่เพียงพอ กรุณาเติมเงินก่อนทำรายการ');
    }

    // หักเงินจาก wallet
    $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
    $stmt->bind_param('di', $product['price'], $userId);
    $stmt->execute();

    // เปลี่ยนสถานะสินค้าเป็นขายแล้ว
    $stmt = $conn->prepare("UPDATE products SET status = 'sold' WHERE id = ?");
    $stmt->bind_param('i', $productId);
    $stmt->execute();

    // บันทึกคำสั่งซื้อ
    $stmt = $conn->prepare("INSERT INTO orders (user_id, product_id, price, status) VALUES (?, ?, ?, 'completed')");
    $stmt->bind_param('iid', $userId, $productId, $product['price']);
    $stmt->execute();

    $orderId = $conn->insert_id;
    $conn->commit();
    logActivity($conn, 'order_created', 'order', $orderId, [
        'product_id' => $productId,
        'product'    => $product['title'],
        'price'      => (float)$product['price'],
        'balance_before' => (float)$user['wallet_balance'],
        'balance_after'  => (float)$user['wallet_balance'] - (float)$product['price'],
    ]);
    setFlash('success', 'ซื้อสินค้าสำเร็จ! ดูข้อมูลบัญชีได้ที่หน้าคำสั่งซื้อของฉัน');
    header('Location: profile.php');
    exit;

} catch (Exception $e) {
    $conn->rollback();
    logActivity($conn, 'order_failed', 'product', $productId, ['reason' => $e->getMessage()]);
    setFlash('error', $e->getMessage());
    header('Location: product.php?id=' . $productId);
    exit;
}
