<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // ไฟล์ใหญ่เกิน post_max_size: PHP จะทิ้งข้อมูลฟอร์มทั้งหมด (รวม CSRF) ต้องดักก่อน ไม่งั้นจะขึ้น "คำขอไม่ถูกต้อง" ที่ชวนงง
        $errors[] = 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ไม่เกิน ' . ini_get('post_max_size') . ') กรุณาลดขนาดรูปแล้วลองใหม่';
    } elseif (!verifyCsrf($_POST['csrf_token'] ?? '')) {
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

        if (empty($errors)) {
            $uploadResult = uploadImage('slip', UPLOAD_SLIP_DIR);
            if (!$uploadResult['success']) {
                $errors[] = $uploadResult['error'];
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO topups (user_id, amount, slip_image, note) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('idss', $_SESSION['user_id'], $amount, $uploadResult['filename'], $note);
            if ($stmt->execute()) {
                logActivity($conn, 'topup_request', 'topup', $conn->insert_id,
                    ['amount' => $amount, 'slip' => $uploadResult['filename'], 'note' => $note]);
                setFlash('success', 'แจ้งเติมเงินสำเร็จ! กรุณารอแอดมินตรวจสอบและอนุมัติ');
                header('Location: topup.php');
                exit;
            } else {
                $errors[] = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
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

        <div class="alert alert-info" style="background:var(--surface-light);border:none;color:var(--text-main);border-radius:10px">
          <strong><i class="fa-solid fa-circle-info"></i> ขั้นตอนการเติมเงิน</strong>
          <ol class="mb-0 mt-2 ps-3">
            <li>โอนเงินเข้าบัญชีที่กำหนด (ตัวอย่าง: ธนาคารกสิกรไทย 123-4-56789-0 ชื่อบัญชี TUNG TUNG SHOP)</li>
            <li>อัปโหลดสลิปการโอนเงิน</li>
            <li>กรอกจำนวนเงินให้ตรงกับสลิป</li>
            <li>รอแอดมินตรวจสอบและอนุมัติ (ปกติภายใน 5-15 นาที)</li>
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
          <small class="text-muted d-block mt-1">รองรับ JPG, PNG, WEBP ขนาดไม่เกิน <?php echo round(MAX_UPLOAD_SIZE / 1048576); ?>MB</small>

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

<script>
// เช็กขนาดไฟล์ฝั่งเบราว์เซอร์ก่อนส่ง จะได้ไม่ต้องรออัปโหลดเสร็จแล้วค่อยรู้ว่าใหญ่เกิน
document.querySelector('input[name="slip"]').addEventListener('change', function () {
  var f = this.files[0];
  if (f && f.size > <?php echo (int)MAX_UPLOAD_SIZE; ?>) {
    alert('ไฟล์ใหญ่เกิน <?php echo round(MAX_UPLOAD_SIZE / 1048576); ?>MB กรุณาลดขนาดรูปหรือแคปหน้าจอสลิปใหม่');
    this.value = '';
  }
});
</script>

<?php include 'includes/footer.php'; ?>
