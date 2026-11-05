<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
require_once 'includes/thunder.php';

requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        if ($amount < 10) {
            $errors[] = 'จำนวนเงินขั้นต่ำในการเติมคือ 10 บาท';
        }
        if ($amount > 100000) {
            $errors[] = 'จำนวนเงินสูงสุดต่อครั้งคือ 100,000 บาท';
        }

        // จำกัดจำนวนครั้งการแจ้งเติม กันยิงสลิปรัว ๆ (และกันเปลือง quota API ตรวจสลิป)
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM topups WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
            $stmt->bind_param('i', $_SESSION['user_id']);
            $stmt->execute();
            if ((int)$stmt->get_result()->fetch_assoc()['c'] >= 10) {
                $errors[] = 'แจ้งเติมเงินบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่ หรือติดต่อแอดมิน';
            }
        }

        if (empty($errors)) {
            $uploadResult = uploadImage('slip', UPLOAD_SLIP_DIR);
            if (!$uploadResult['success']) {
                $errors[] = $uploadResult['error'];
            }
        }

        if (empty($errors)) {
            try {
                // ตรวจสลิปอัตโนมัติ (ถ้าเปิดใช้) แล้วบันทึกรายการ — ดู includes/thunder.php
                $result = processTopupSubmission($conn, $_SESSION['user_id'], $amount, $uploadResult['filename'], $note);
            } catch (Throwable $e) {
                error_log('processTopupSubmission failed: ' . $e->getMessage());
                $result = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง'];
            }

            if (in_array($result['status'], ['retry', 'error'], true)) {
                @unlink(UPLOAD_SLIP_DIR . $uploadResult['filename']); // ยังไม่ได้บันทึกรายการ ลบไฟล์ที่เพิ่งอัปโหลด
                $errors[] = $result['message'];
            } else {
                setFlash($result['status'] === 'rejected' ? 'error' : 'success', $result['message']);
                header('Location: topup.php');
                exit;
            }
        }
    }
}

// ประวัติการเติมเงินของผู้ใช้
$stmt = $conn->prepare("SELECT * FROM topups WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$topups = $stmt->get_result();

$currentUser = getCurrentUser($conn);
$pageTitle = 'เติมเงิน';
include 'includes/header.php';

$statusLabel = ['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ปฏิเสธ'];
?>

<div class="container my-5">
  <div class="row g-4">
    <div class="col-md-5">
      <div class="card-custom p-4">
        <h4 class="mb-1"><i class="fa-solid fa-wallet"></i> แจ้งเติมเงิน</h4>
        <p class="text-muted">ยอดเงินคงเหลือปัจจุบัน: <strong style="color:var(--accent)"><?php echo number_format($currentUser['wallet_balance'],2); ?> บาท</strong></p>

        <div class="text-center mb-4 p-3" style="background:var(--surface-light);border-radius:12px">
          <h5 class="mb-2"><i class="fa-solid fa-qrcode"></i> สแกน QR เพื่อโอนเงิน</h5>
          <p class="text-muted small mb-3">เปิดแอปธนาคารแล้วสแกน QR ด้านล่าง จากนั้นตรวจสอบชื่อผู้รับและจำนวนเงินก่อนยืนยัน</p>
          <img src="uploads/payment/promptpay-qr.jpeg" alt="QR Code สำหรับโอนเงิน PromptPay ให้ TUNG TUNG SHOP" class="img-fluid" style="width:100%;max-width:360px;border-radius:8px;background:#fff;padding:6px">
          <p class="small mt-2 mb-0">ชื่อบัญชีตาม QR: นาย พีรภัทร กลิ้งพุดชา</p>
        </div>

        <div class="alert alert-info" style="background:var(--surface-light);border:none;color:var(--text-main);border-radius:10px">
          <strong><i class="fa-solid fa-circle-info"></i> ขั้นตอนการเติมเงิน</strong>
          <ol class="mb-0 mt-2 ps-3">
            <li>สแกน QR PromptPay ด้านบนเพื่อโอนเงิน</li>
            <li>อัปโหลดสลิปการโอนเงิน (ต้องเห็น QR Code บนสลิปชัดเจน ห้ามครอปส่วน QR ออก)</li>
            <li>กรอกจำนวนเงินให้ตรงกับสลิป</li>
            <?php if (thunderEnabled()): ?>
            <li>ระบบตรวจสลิปอัตโนมัติ ถ้าผ่านเงินจะเข้า Wallet ทันที (ถ้าไม่ผ่านจะส่งให้แอดมินตรวจสอบ)</li>
            <?php else: ?>
            <li>รอแอดมินตรวจสอบและอนุมัติ (ปกติภายใน 5-15 นาที)</li>
            <?php endif; ?>
          </ol>
        </div>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger" style="border-radius:10px;border:none">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $err) echo '<li>' . h($err) . '</li>'; ?></ul>
          </div>
        <?php endif; ?>

        <form method="POST" action="topup.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">

          <label class="form-label">จำนวนเงินที่โอน (บาท)</label>
          <input type="number" step="0.01" min="10" class="form-control" name="amount" placeholder="เช่น 100.00" required>

          <label class="form-label">อัปโหลดสลิปการโอนเงิน</label>
          <input type="file" class="form-control" name="slip" accept="image/png, image/jpeg, image/webp" required>

          <label class="form-label mt-3">หมายเหตุ (ถ้ามี)</label>
          <textarea class="form-control" name="note" rows="2" placeholder="เช่น โอนเวลา 14:30 น."></textarea>

          <button type="submit" class="btn btn-accent-custom w-100 mt-3"><i class="fa-solid fa-paper-plane"></i> ส่งคำขอเติมเงิน</button>
        </form>
      </div>
    </div>

    <div class="col-md-7">
      <h4 class="mb-3"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติการเติมเงิน</h4>
      <div class="table-responsive">
        <table class="table table-custom align-middle">
          <thead>
            <tr><th>วันที่แจ้ง</th><th>จำนวนเงิน</th><th>สลิป</th><th>สถานะ</th><th>หมายเหตุแอดมิน</th></tr>
          </thead>
          <tbody>
            <?php if ($topups->num_rows === 0): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีประวัติการเติมเงิน</td></tr>
            <?php endif; ?>
            <?php while ($t = $topups->fetch_assoc()): ?>
            <tr>
              <td><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
              <td><?php echo number_format($t['amount'],2); ?> บาท</td>
              <td>
                <a href="uploads/slips/<?php echo h($t['slip_image']); ?>" target="_blank" class="text-decoration-underline" style="color:var(--primary-light)">ดูสลิป</a>
              </td>
              <td><span class="status-badge status-<?php echo $t['status']; ?>"><?php echo $statusLabel[$t['status']]; ?></span></td>
              <td><small class="text-muted"><?php echo h($t['admin_note'] ?? '-'); ?></small></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
