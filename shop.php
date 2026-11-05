<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

$shops = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM products WHERE shop_id=s.id AND status='available') AS product_count FROM shops s WHERE status='active' ORDER BY name");

$pageTitle = 'ร้านค้าทั้งหมด';
include 'includes/header.php';
?>

<div class="container my-5">
  <h2 class="section-title"><i class="fa-solid fa-store"></i> ร้านค้าทั้งหมด</h2>
  <div class="row g-4">
    <?php if ($shops->num_rows === 0): ?>
      <div class="empty-state"><div class="icon"><i class="fa-solid fa-store-slash"></i></div><p>ยังไม่มีร้านค้าในระบบ</p></div>
    <?php endif; ?>
    <?php while ($s = $shops->fetch_assoc()): ?>
    <div class="col-md-4">
      <div class="card-custom p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="font-size:2.2rem;color:var(--primary-light)"><i class="fa-solid fa-shop"></i></div>
          <div>
            <div class="card-title mb-0"><?php echo h($s['name']); ?></div>
            <small class="text-muted"><?php echo $s['product_count']; ?> ไอดีพร้อมขาย</small>
          </div>
        </div>
        <p class="text-muted" style="min-height:48px"><?php echo h($s['description']); ?></p>
        <a href="index.php?shop_id=<?php echo $s['id']; ?>" class="btn btn-primary-custom w-100">ดูสินค้าในร้านนี้</a>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
