<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireAdmin();

// ---------- ปรับสถานะแบน/ปลดแบน ----------
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    if ($id !== $_SESSION['user_id']) { // ห้ามแบนตัวเอง
        $stmt = $conn->prepare("SELECT username, status FROM users WHERE id = ? AND role != 'admin'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();

        $stmt = $conn->prepare("UPDATE users SET status = IF(status='active','banned','active') WHERE id = ? AND role != 'admin'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($target && $stmt->affected_rows > 0) {
            $newStatus = $target['status'] === 'active' ? 'banned' : 'active';
            logActivity($conn, $newStatus === 'banned' ? 'user_ban' : 'user_unban', 'user', $id,
                ['target_username' => $target['username'], 'from' => $target['status'], 'to' => $newStatus]);
        }
        setFlash('success', 'เปลี่ยนสถานะผู้ใช้เรียบร้อยแล้ว');
    } else {
        setFlash('error', 'ไม่สามารถระงับบัญชีตัวเองได้');
    }
    header('Location: users.php');
    exit;
}

// ---------- ปรับยอด wallet โดยตรง (แอดมินเติมให้มือ) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_wallet'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง');
    } else {
        $userId = (int)$_POST['user_id'];
        $amount = (float)$_POST['adjust_amount'];
        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT username, wallet_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();

        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
        $stmt->bind_param('di', $amount, $userId);
        $stmt->execute();
        $conn->commit();

        if ($target) {
            logActivity($conn, 'wallet_adjust', 'user', $userId, [
                'target_username' => $target['username'],
                'amount'          => $amount,
                'balance_before'  => (float)$target['wallet_balance'],
                'balance_after'   => (float)$target['wallet_balance'] + $amount,
            ]);
        }
        setFlash('success', 'ปรับยอด Wallet เรียบร้อยแล้ว (' . ($amount >= 0 ? '+' : '') . number_format($amount,2) . ' บาท)');
    }
    header('Location: users.php');
    exit;
}

// ---------- สร้างลิงก์รีเซ็ตรหัสผ่านให้ผู้ใช้ (แอดมินส่งลิงก์ให้ผู้ใช้เอง เช่น ทางไลน์) ----------
// แอดมินไม่เห็นและไม่ตั้งรหัสผ่านแทนผู้ใช้ ผู้ใช้ตั้งเองผ่านลิงก์ และทำได้เฉพาะบัญชี role=user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_link'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง');
    } else {
        $userId = (int)$_POST['user_id'];
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'user'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        if ($target) {
            $token = createPasswordReset($conn, $userId, RESET_ADMIN_TTL_MIN, $_SESSION['user_id']);
            // ไม่เก็บ token/ลิงก์ลง log
            logActivity($conn, 'admin_reset_link', 'user', $userId, ['target_username' => $target['username']]);
            $_SESSION['reset_link_result'] = ['username' => $target['username'], 'url' => passwordResetUrl($token)];
        } else {
            setFlash('error', 'ไม่พบผู้ใช้');
        }
    }
    header('Location: users.php');
    exit;
}
$resetResult = $_SESSION['reset_link_result'] ?? null;
unset($_SESSION['reset_link_result']);

$users = $conn->query("SELECT * FROM users WHERE role='user' ORDER BY created_at DESC");

$pageTitle = 'ผู้ใช้งาน';
include 'inc_header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-users"></i> จัดการผู้ใช้งาน</h3>

<?php if ($resetResult): ?>
<div class="alert alert-success" style="border-radius:12px;border:none">
  <strong>สร้างลิงก์รีเซ็ตรหัสผ่านของ <?php echo h($resetResult['username']); ?> แล้ว</strong>
  (ใช้ได้ <?php echo (int)(RESET_ADMIN_TTL_MIN / 60); ?> ชม. ใช้ได้ครั้งเดียว และจะแสดงครั้งนี้ครั้งเดียว) ส่งลิงก์นี้ให้ผู้ใช้:
  <div class="input-group mt-2">
    <input type="text" class="form-control" id="resetLinkInput" readonly value="<?php echo h($resetResult['url']); ?>" onclick="this.select()">
    <button class="btn btn-outline-custom" type="button" onclick="navigator.clipboard.writeText(document.getElementById('resetLinkInput').value);this.textContent='คัดลอกแล้ว'">คัดลอก</button>
  </div>
  <small class="d-block mt-2">ควรตรวจสอบว่าเป็นเจ้าของบัญชีจริงก่อนส่งลิงก์ (เช่น ให้ยืนยันอีเมล/ข้อมูลการซื้อ)</small>
</div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-custom">
    <thead><tr><th>#</th><th>ชื่อผู้ใช้</th><th>อีเมล</th><th>Wallet</th><th>สถานะ</th><th>สมัครเมื่อ</th><th>จัดการ</th></tr></thead>
    <tbody>
      <?php if ($users->num_rows === 0): ?><tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีผู้ใช้งาน</td></tr><?php endif; ?>
      <?php while ($u = $users->fetch_assoc()): ?>
      <tr>
        <td><?php echo $u['id']; ?></td>
        <td><?php echo h($u['username']); ?></td>
        <td><?php echo h($u['email']); ?></td>
        <td><?php echo number_format($u['wallet_balance'],2); ?> บาท</td>
        <td><span class="status-badge status-<?php echo $u['status']; ?>"><?php echo $u['status']==='active'?'ปกติ':'ถูกระงับ'; ?></span></td>
        <td><small><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></small></td>
        <td>
          <button class="btn btn-sm btn-outline-custom" data-bs-toggle="modal" data-bs-target="#walletModal<?php echo $u['id']; ?>"><i class="fa-solid fa-wallet"></i> ปรับยอด</button>
          <form method="POST" action="users.php" class="d-inline" onsubmit="return confirm('สร้างลิงก์รีเซ็ตรหัสผ่านให้ <?php echo h($u['username']); ?> ? ลิงก์เก่าที่ยังไม่ใช้จะถูกยกเลิก');">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
            <button type="submit" name="reset_link" value="1" class="btn btn-sm btn-outline-custom"><i class="fa-solid fa-key"></i> รีเซ็ตรหัส</button>
          </form>
          <a href="?toggle_status=<?php echo $u['id']; ?>" class="btn btn-sm" style="background:<?php echo $u['status']==='active'?'var(--danger)':'var(--success)'; ?>;color:#fff" onclick="return confirm('ยืนยันการเปลี่ยนสถานะ?')">
            <?php echo $u['status']==='active' ? 'ระงับ' : 'ปลดระงับ'; ?>
          </a>
        </td>
      </tr>

      <!-- Modal ปรับยอด wallet รายบุคคล -->
      <div class="modal fade" id="walletModal<?php echo $u['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content" style="background:var(--surface);color:var(--text-main);border:1px solid var(--border-color)">
            <form method="POST" action="users.php">
              <div class="modal-header" style="border-color:var(--border-color)">
                <h5 class="modal-title">ปรับยอด Wallet: <?php echo h($u['username']); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                <p class="text-muted">ยอดปัจจุบัน: <?php echo number_format($u['wallet_balance'],2); ?> บาท</p>
                <label class="form-label">จำนวนที่ต้องการปรับ (ใส่ค่าลบเพื่อหักเงิน เช่น -50)</label>
                <input type="number" step="0.01" class="form-control" name="adjust_amount" required>
              </div>
              <div class="modal-footer" style="border-color:var(--border-color)">
                <button type="submit" name="adjust_wallet" value="1" class="btn btn-primary-custom w-100">บันทึก</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<?php include 'inc_footer.php'; ?>
