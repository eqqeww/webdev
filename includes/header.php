<?php
// header.php ต้องถูกเรียกหลังจาก include config/config.php และ functions.php แล้วเท่านั้น
require_once __DIR__ . '/chat.php';
$currentUser = isLoggedIn() ? getCurrentUser($conn) : null;
$chatUnread = (isLoggedIn() && !isAdmin()) ? chatUserUnread($conn, $_SESSION['user_id']) : 0;
$flash = getFlash();
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? h($pageTitle) . ' - ' . SITE_NAME : SITE_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-custom">
  <div class="container">
    <a class="navbar-brand" href="<?php echo SITE_URL; ?>/index.php"><i class="fa-solid fa-cube"></i> TUNG TUNG SHOP</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" style="border-color:var(--border-color)">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto ms-lg-4">
        <li class="nav-item"><a class="nav-link <?php echo $currentPage=='index.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/index.php">หน้าแรก</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $currentPage=='shop.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/shop.php">ร้านค้า</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $currentPage=='map.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/map.php">แมพ</a></li>
        <?php if (isLoggedIn()): ?>
        <li class="nav-item"><a class="nav-link <?php echo $currentPage=='profile.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/profile.php">คำสั่งซื้อของฉัน</a></li>
        <?php if (!isAdmin()): ?>
        <li class="nav-item"><a class="nav-link <?php echo $currentPage=='chat.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/chat.php"><i class="fa-solid fa-comments"></i> แชทแอดมิน <span id="chatBadge" class="badge bg-danger" style="<?php echo $chatUnread > 0 ? '' : 'display:none'; ?>"><?php echo $chatUnread; ?></span></a></li>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <li class="nav-item"><a class="nav-link" href="<?php echo SITE_URL; ?>/admin/index.php"><i class="fa-solid fa-user-shield"></i> แผงแอดมิน</a></li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav align-items-lg-center gap-2">
        <?php if (isLoggedIn()): ?>
          <li class="nav-item">
            <a href="<?php echo SITE_URL; ?>/topup.php" class="wallet-badge">
              <i class="fa-solid fa-coins"></i> <?php echo number_format($currentUser['wallet_balance'], 2); ?> ฿
            </a>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
              <i class="fa-solid fa-circle-user"></i> <?php echo h($currentUser['username']); ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" style="background:var(--surface);border:1px solid var(--border-color)">
              <li><a class="dropdown-item" style="color:var(--text-main)" href="<?php echo SITE_URL; ?>/profile.php"><i class="fa-solid fa-box"></i> คำสั่งซื้อของฉัน</a></li>
              <li><a class="dropdown-item" style="color:var(--text-main)" href="<?php echo SITE_URL; ?>/topup.php"><i class="fa-solid fa-wallet"></i> เติมเงิน</a></li>
              <li><hr class="dropdown-divider" style="border-color:var(--border-color)"></li>
              <li><a class="dropdown-item text-danger" href="<?php echo SITE_URL; ?>/logout.php"><i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="<?php echo SITE_URL; ?>/login.php">เข้าสู่ระบบ</a></li>
          <li class="nav-item"><a class="btn btn-primary-custom" href="<?php echo SITE_URL; ?>/register.php">สมัครสมาชิก</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<?php if (isLoggedIn() && !isAdmin() && $currentPage !== 'chat.php'): ?>
<script>
(function () {
  var badge = document.getElementById('chatBadge');
  if (!badge) return;
  function tick() {
    if (!document.hidden) {
      fetch('<?php echo SITE_URL; ?>/chat_api.php?action=unread', { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) return;
          badge.textContent = d.unread;
          badge.style.display = d.unread > 0 ? '' : 'none';
        }).catch(function () {});
    }
    setTimeout(tick, 20000);
  }
  setTimeout(tick, 20000);
})();
</script>
<?php endif; ?>

<?php if ($flash): ?>
<div class="container mt-3">
  <div class="alert alert-<?php echo $flash['type']==='error'?'danger':$flash['type']; ?> alert-dismissible fade show" role="alert" style="border-radius:12px;border:none">
    <?php echo h($flash['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>
