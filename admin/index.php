<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireAdmin();

$totalUsers = $conn->query("SELECT COUNT(*) c FROM users WHERE role='user'")->fetch_assoc()['c'];
$totalProducts = $conn->query("SELECT COUNT(*) c FROM products WHERE status='available'")->fetch_assoc()['c'];
$totalOrders = $conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'];
$totalRevenue = $conn->query("SELECT COALESCE(SUM(price),0) s FROM orders WHERE status='completed'")->fetch_assoc()['s'];
$pendingTopups = $conn->query("SELECT COUNT(*) c FROM topups WHERE status='pending'")->fetch_assoc()['c'];
$totalTopupApproved = $conn->query("SELECT COALESCE(SUM(amount),0) s FROM topups WHERE status='approved'")->fetch_assoc()['s'];

$recentOrders = $conn->query("SELECT o.*, u.username, p.title FROM orders o
                               JOIN users u ON o.user_id=u.id
                               JOIN products p ON o.product_id=p.id
                               ORDER BY o.created_at DESC LIMIT 5");

$recentTopups = $conn->query("SELECT t.*, u.username FROM topups t
                               JOIN users u ON t.user_id=u.id
                               ORDER BY t.created_at DESC LIMIT 5");

$pageTitle = 'แดชบอร์ด';
include 'inc_header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-gauge"></i> แดชบอร์ดภาพรวม</h3>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="admin-stat-card">
      <div class="stat-num"><?php echo number_format($totalUsers); ?></div>
      <div class="stat-label"><i class="fa-solid fa-users"></i> สมาชิกทั้งหมด</div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="admin-stat-card">
      <div class="stat-num"><?php echo number_format($totalProducts); ?></div>
      <div class="stat-label"><i class="fa-solid fa-cube"></i> ไอดีพร้อมขาย</div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="admin-stat-card">
      <div class="stat-num"><?php echo number_format($totalOrders); ?></div>
      <div class="stat-label"><i class="fa-solid fa-bag-shopping"></i> คำสั่งซื้อทั้งหมด</div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="admin-stat-card">
      <div class="stat-num"><?php echo number_format($totalRevenue,2); ?></div>
      <div class="stat-label"><i class="fa-solid fa-sack-dollar"></i> ยอดขายรวม (บาท)</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="admin-stat-card d-flex justify-content-between align-items-center">
      <div>
        <div class="stat-num" style="color:var(--warning)"><?php echo number_format($pendingTopups); ?></div>
        <div class="stat-label">คำขอเติมเงินรอตรวจสอบ</div>
      </div>
      <a href="topups.php" class="btn btn-accent-custom btn-sm">ตรวจสอบเลย</a>
    </div>
  </div>
  <div class="col-md-6">
    <div class="admin-stat-card">
      <div class="stat-num" style="color:var(--success)"><?php echo number_format($totalTopupApproved,2); ?></div>
      <div class="stat-label">ยอดเติมเงินที่อนุมัติแล้ว (บาท)</div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-md-6">
    <h5 class="mb-3">คำสั่งซื้อล่าสุด</h5>
    <div class="table-responsive">
      <table class="table table-custom">
        <thead><tr><th>ผู้ซื้อ</th><th>สินค้า</th><th>ราคา</th><th>เวลา</th></tr></thead>
        <tbody>
          <?php if ($recentOrders->num_rows === 0): ?><tr><td colspan="4" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr><?php endif; ?>
          <?php while ($o = $recentOrders->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($o['username']); ?></td>
            <td><?php echo h($o['title']); ?></td>
            <td><?php echo number_format($o['price'],2); ?></td>
            <td><small><?php echo date('d/m H:i', strtotime($o['created_at'])); ?></small></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-md-6">
    <h5 class="mb-3">คำขอเติมเงินล่าสุด</h5>
    <div class="table-responsive">
      <table class="table table-custom">
        <thead><tr><th>ผู้ใช้</th><th>จำนวน</th><th>สถานะ</th><th>เวลา</th></tr></thead>
        <tbody>
          <?php if ($recentTopups->num_rows === 0): ?><tr><td colspan="4" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr><?php endif; ?>
          <?php while ($t = $recentTopups->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($t['username']); ?></td>
            <td><?php echo number_format($t['amount'],2); ?></td>
            <td><span class="status-badge status-<?php echo $t['status']; ?>"><?php echo $t['status']; ?></span></td>
            <td><small><?php echo date('d/m H:i', strtotime($t['created_at'])); ?></small></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include 'inc_footer.php'; ?>
