<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

requireLogin();
$currentUser = getCurrentUser($conn);

$stmt = $conn->prepare("SELECT o.*, p.title, p.image, p.account_detail, s.name AS shop_name, m.name AS map_name
                         FROM orders o
                         JOIN products p ON o.product_id = p.id
                         JOIN shops s ON p.shop_id = s.id
                         JOIN maps m ON p.map_id = m.id
                         WHERE o.user_id = ?
                         ORDER BY o.created_at DESC");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$orders = $stmt->get_result();

$pageTitle = 'คำสั่งซื้อของฉัน';
include 'includes/header.php';
?>

<div class="container my-5">
  <div class="card-custom p-4 mb-4">
    <div class="row align-items-center">
      <div class="col-md-8">
        <h4 class="mb-1"><i class="fa-solid fa-circle-user"></i> <?php echo h($currentUser['username']); ?></h4>
        <p class="text-muted mb-0"><?php echo h($currentUser['email']); ?> · สมัครเมื่อ <?php echo date('d/m/Y', strtotime($currentUser['created_at'])); ?></p>
      </div>
      <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <div class="wallet-badge" style="font-size:1.1rem"><i class="fa-solid fa-coins"></i> <?php echo number_format($currentUser['wallet_balance'],2); ?> บาท</div>
        <a href="topup.php" class="d-block mt-2" style="color:var(--accent)">+ เติมเงินเพิ่ม</a>
      </div>
    </div>
  </div>

  <h4 class="section-title"><i class="fa-solid fa-bag-shopping"></i> ประวัติคำสั่งซื้อ (<?php echo $orders->num_rows; ?>)</h4>
  <div class="row g-4">
    <?php if ($orders->num_rows === 0): ?>
      <div class="empty-state">
        <div class="icon"><i class="fa-solid fa-box-open"></i></div>
        <p>คุณยังไม่มีคำสั่งซื้อ <a href="index.php" style="color:var(--accent)">เลือกซื้อไอดีเลย</a></p>
      </div>
    <?php endif; ?>
    <?php while ($o = $orders->fetch_assoc()): ?>
    <div class="col-md-6">
      <div class="card-custom p-3">
        <div class="d-flex gap-3">
          <div class="card-img-wrap" style="width:90px;height:90px;flex-shrink:0;border-radius:10px">
            <?php if ($o['image']): ?><img src="uploads/products/<?php echo h($o['image']); ?>" alt=""><?php else: ?><i class="fa-solid fa-cube"></i><?php endif; ?>
          </div>
          <div class="flex-grow-1">
            <span class="badge-shop"><?php echo h($o['shop_name']); ?> · <?php echo h($o['map_name']); ?></span>
            <div class="card-title mb-1"><?php echo h($o['title']); ?></div>
            <div class="text-muted small mb-1">ซื้อเมื่อ <?php echo date('d/m/Y H:i', strtotime($o['created_at'])); ?></div>
            <div class="price-tag" style="font-size:1rem"><?php echo formatMoney($o['price']); ?></div>
          </div>
        </div>
        <div class="mt-3 p-2" style="background:var(--bg-darker);border-radius:8px">
          <small class="text-muted d-block mb-1"><i class="fa-solid fa-key"></i> ข้อมูลบัญชี:</small>
          <small><?php echo nl2br(h($o['account_detail'])); ?></small>
        </div>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
