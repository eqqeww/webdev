<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

$maps = $conn->query("SELECT m.*, (SELECT COUNT(*) FROM products WHERE map_id=m.id AND status='available') AS product_count FROM maps m WHERE status='active' ORDER BY name");

$pageTitle = 'แมพทั้งหมด';
include 'includes/header.php';
?>

<div class="container my-5">
  <h2 class="section-title"><i class="fa-solid fa-map"></i> แมพทั้งหมด</h2>
  <div class="row g-4">
    <?php if ($maps->num_rows === 0): ?>
      <div class="empty-state"><div class="icon"><i class="fa-solid fa-map"></i></div><p>ยังไม่มีแมพในระบบ</p></div>
    <?php endif; ?>
    <?php while ($m = $maps->fetch_assoc()): ?>
    <div class="col-md-4">
      <div class="card-custom p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="font-size:2.2rem;color:var(--accent)"><i class="fa-solid fa-gamepad"></i></div>
          <div>
            <div class="card-title mb-0"><?php echo h($m['name']); ?></div>
            <small class="text-muted"><?php echo $m['product_count']; ?> ไอดีพร้อมขาย</small>
          </div>
        </div>
        <p class="text-muted" style="min-height:48px"><?php echo h($m['description']); ?></p>
        <a href="index.php?map_id=<?php echo $m['id']; ?>" class="btn btn-accent-custom w-100">ดูไอดีในแมพนี้</a>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
