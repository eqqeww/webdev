<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireAdmin();

// ---------- ประมวลผลการอนุมัติ/ปฏิเสธ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง');
        header('Location: topups.php');
        exit;
    }

    $topupId = (int)$_POST['topup_id'];
    $action = $_POST['action'];
    $adminNote = trim($_POST['admin_note'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM topups WHERE id = ?");
    $stmt->bind_param('i', $topupId);
    $stmt->execute();
    $topup = $stmt->get_result()->fetch_assoc();

    if (!$topup) {
        setFlash('error', 'ไม่พบรายการที่ต้องการ');
    } elseif ($topup['status'] !== 'pending') {
        setFlash('error', 'รายการนี้ถูกดำเนินการไปแล้ว');
    } elseif ($action === 'approve') {
        $conn->begin_transaction();
        try {
            // เพิ่มเงินเข้า wallet ผู้ใช้
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->bind_param('di', $topup['amount'], $topup['user_id']);
            $stmt->execute();

            // อัปเดตสถานะ topup
            $stmt = $conn->prepare("UPDATE topups SET status='approved', admin_note=?, reviewed_at=NOW() WHERE id=?");
            $stmt->bind_param('si', $adminNote, $topupId);
            $stmt->execute();

            $conn->commit();
            logActivity($conn, 'topup_approve', 'topup', $topupId, [
                'user_id' => (int)$topup['user_id'],
                'amount'  => (float)$topup['amount'],
                'admin_note' => $adminNote,
            ]);
            setFlash('success', 'อนุมัติการเติมเงินสำเร็จ เพิ่มยอด ' . number_format($topup['amount'],2) . ' บาท เข้า wallet ผู้ใช้แล้ว');
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE topups SET status='rejected', admin_note=?, reviewed_at=NOW() WHERE id=?");
        $stmt->bind_param('si', $adminNote, $topupId);
        $stmt->execute();
        logActivity($conn, 'topup_reject', 'topup', $topupId, [
            'user_id' => (int)$topup['user_id'],
            'amount'  => (float)$topup['amount'],
            'admin_note' => $adminNote,
        ]);
        setFlash('success', 'ปฏิเสธคำขอเติมเงินเรียบร้อยแล้ว');
    }

    header('Location: topups.php');
    exit;
}

// ---------- ดึงรายการคำขอเติมเงิน ----------
$filterStatus = $_GET['status'] ?? 'pending';
$sql = "SELECT t.*, u.username, u.email FROM topups t JOIN users u ON t.user_id = u.id";
if (in_array($filterStatus, ['pending','approved','rejected'])) {
    $sql .= " WHERE t.status = '" . $conn->real_escape_string($filterStatus) . "'";
}
$sql .= " ORDER BY t.created_at DESC";
$topups = $conn->query($sql);

$pageTitle = 'คำขอเติมเงิน';
include 'inc_header.php';

$statusLabel = ['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ปฏิเสธ'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fa-solid fa-money-bill-transfer"></i> คำขอเติมเงิน</h3>
  <div class="btn-group">
    <a href="?status=pending" class="btn btn-sm <?php echo $filterStatus=='pending'?'btn-accent-custom':'btn-outline-custom'; ?>">รอตรวจสอบ</a>
    <a href="?status=approved" class="btn btn-sm <?php echo $filterStatus=='approved'?'btn-accent-custom':'btn-outline-custom'; ?>">อนุมัติแล้ว</a>
    <a href="?status=rejected" class="btn btn-sm <?php echo $filterStatus=='rejected'?'btn-accent-custom':'btn-outline-custom'; ?>">ปฏิเสธ</a>
    <a href="?status=all" class="btn btn-sm <?php echo $filterStatus=='all'?'btn-accent-custom':'btn-outline-custom'; ?>">ทั้งหมด</a>
  </div>
</div>

<div class="row g-3">
  <?php if ($topups->num_rows === 0): ?>
    <div class="empty-state"><div class="icon"><i class="fa-solid fa-inbox"></i></div><p>ไม่มีรายการในหมวดนี้</p></div>
  <?php endif; ?>

  <?php while ($t = $topups->fetch_assoc()): ?>
  <div class="col-md-6">
    <div class="card-custom p-3">
      <div class="d-flex justify-content-between">
        <div>
          <strong><?php echo h($t['username']); ?></strong> <small class="text-muted">(<?php echo h($t['email']); ?>)</small>
          <div class="text-muted small">แจ้งเมื่อ <?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></div>
        </div>
        <span class="status-badge status-<?php echo $t['status']; ?>"><?php echo $statusLabel[$t['status']]; ?></span>
      </div>

      <div class="row mt-3 g-3">
        <div class="col-6">
          <div class="slip-preview">
            <a href="../uploads/slips/<?php echo h($t['slip_image']); ?>" target="_blank">
              <img src="../uploads/slips/<?php echo h($t['slip_image']); ?>" alt="สลิป">
            </a>
          </div>
        </div>
        <div class="col-6">
          <div class="mb-2"><small class="text-muted">จำนวนเงินที่แจ้ง</small>
            <div class="fw-bold" style="color:var(--accent);font-size:1.3rem"><?php echo number_format($t['amount'],2); ?> บาท</div>
          </div>
          <?php
            $vr = $t['verify_result'] ?? '';
            $vd = json_decode($t['verify_detail'] ?? '', true) ?: [];
            $vLabels = ['auto_approved' => ['ตรวจอัตโนมัติ: ผ่าน', 'success'], 'auto_rejected' => ['ระบบปฏิเสธอัตโนมัติ', 'danger'],
                        'manual_review' => ['ตรวจอัตโนมัติไม่ผ่าน — รอแอดมินตรวจ', 'warning']];
            if (isset($vLabels[$vr])):
          ?>
          <div class="mb-2">
            <span class="badge bg-<?php echo $vLabels[$vr][1]; ?><?php echo $vLabels[$vr][1] === 'warning' ? ' text-dark' : ''; ?>"><?php echo h($vLabels[$vr][0]); ?></span>
            <?php if (!empty($vd['reason']) && $vr !== 'auto_approved'): ?><div class="small mt-1"><?php echo h($vd['reason']); ?></div><?php endif; ?>
            <?php if (isset($vd['slip_amount']) && $vd['slip_amount'] > 0): ?>
              <div class="small mt-1">ยอดในสลิป: <strong><?php echo number_format($vd['slip_amount'], 2); ?></strong> บาท
                <?php if (abs($vd['slip_amount'] - $t['amount']) >= 0.01 && $t['status'] === 'pending'): ?>
                  <span class="text-warning">⚠ ไม่ตรงกับที่แจ้ง ตรวจให้แน่ใจก่อนอนุมัติ</span>
                <?php endif; ?>
              </div>
              <div class="small text-muted">
                <?php echo h(trim(($vd['sender'] ?? '-') . ' (' . ($vd['sender_bank'] ?? '-') . ') → ' . ($vd['receiver'] ?? '-') . ' (' . ($vd['receiver_bank'] ?? '-') . ')')); ?>
                <?php if (!empty($vd['slip_date'])): ?><br>เวลาในสลิป: <?php echo h(date('d/m/Y H:i', strtotime($vd['slip_date']))); ?><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($t['note']): ?><div class="mb-2"><small class="text-muted">หมายเหตุผู้ใช้</small><div><?php echo h($t['note']); ?></div></div><?php endif; ?>
          <?php if ($t['admin_note']): ?><div class="mb-2"><small class="text-muted">หมายเหตุแอดมิน</small><div><?php echo h($t['admin_note']); ?></div></div><?php endif; ?>
        </div>
      </div>

      <?php if ($t['status'] === 'pending'): ?>
      <form method="POST" action="topups.php" class="mt-3" onsubmit="return confirm('ยืนยันการดำเนินการ?');">
        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
        <input type="hidden" name="topup_id" value="<?php echo $t['id']; ?>">
        <input type="text" name="admin_note" class="form-control form-control-sm mb-2" placeholder="หมายเหตุ (ไม่บังคับ)">
        <div class="d-flex gap-2">
          <button type="submit" name="action" value="approve" class="btn btn-sm flex-fill" style="background:var(--success);color:#fff"><i class="fa-solid fa-check"></i> อนุมัติ</button>
          <button type="submit" name="action" value="reject" class="btn btn-sm flex-fill" style="background:var(--danger);color:#fff"><i class="fa-solid fa-xmark"></i> ปฏิเสธ</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endwhile; ?>
</div>

<?php include 'inc_footer.php'; ?>
