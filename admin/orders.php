<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireAdmin();

$orders = $conn->query("SELECT o.*, u.username, u.email, p.title, s.name AS shop_name, m.name AS map_name
                         FROM orders o
                         JOIN users u ON o.user_id = u.id
                         JOIN products p ON o.product_id = p.id
                         JOIN shops s ON p.shop_id = s.id
                         JOIN maps m ON p.map_id = m.id
                         ORDER BY o.created_at DESC");

$pageTitle = 'คำสั่งซื้อ';
include 'inc_header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-bag-shopping"></i> คำสั่งซื้อทั้งหมด (<?php echo $orders->num_rows; ?>)</h3>

<div class="table-responsive">
  <table class="table table-custom">
    <thead><tr><th>#</th><th>ผู้ซื้อ</th><th>สินค้า</th><th>ร้าน/แมพ</th><th>ราคา</th><th>สถานะ</th><th>วันที่</th></tr></thead>
    <tbody>
      <?php if ($orders->num_rows === 0): ?><tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีคำสั่งซื้อ</td></tr><?php endif; ?>
      <?php while ($o = $orders->fetch_assoc()): ?>
      <tr>
        <td><?php echo $o['id']; ?></td>
        <td><?php echo h($o['username']); ?><br><small class="text-muted"><?php echo h($o['email']); ?></small></td>
        <td><?php echo h($o['title']); ?></td>
        <td><small><?php echo h($o['shop_name']); ?> / <?php echo h($o['map_name']); ?></small></td>
        <td><?php echo number_format($o['price'],2); ?> บาท</td>
        <td><span class="status-badge status-<?php echo $o['status']; ?>"><?php echo $o['status']==='completed'?'สำเร็จ':'ยกเลิก'; ?></span></td>
        <td><small><?php echo date('d/m/Y H:i', strtotime($o['created_at'])); ?></small></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<?php include 'inc_footer.php'; ?>
