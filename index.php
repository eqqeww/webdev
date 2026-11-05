<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

// ---------- ดึงค่าตัวกรอง ----------
$shopId = isset($_GET['shop_id']) ? (int)$_GET['shop_id'] : 0;
$mapId = isset($_GET['map_id']) ? (int)$_GET['map_id'] : 0;
$keyword = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'newest';

// ---------- ดึงร้านค้าและแมพสำหรับ dropdown ----------
$shops = $conn->query("SELECT * FROM shops WHERE status='active' ORDER BY name");
$maps = $conn->query("SELECT * FROM maps WHERE status='active' ORDER BY name");

// ---------- สร้างคำสั่ง SQL แบบไดนามิกตามตัวกรอง ----------
$sql = "SELECT p.*, s.name AS shop_name, m.name AS map_name
        FROM products p
        JOIN shops s ON p.shop_id = s.id
        JOIN maps m ON p.map_id = m.id
        WHERE p.status = 'available'";
$params = [];
$types = '';

if ($shopId > 0) { $sql .= " AND p.shop_id = ?"; $params[] = $shopId; $types .= 'i'; }
if ($mapId > 0) { $sql .= " AND p.map_id = ?"; $params[] = $mapId; $types .= 'i'; }
if ($keyword !== '') {
    $sql .= " AND (p.title LIKE ? OR p.description LIKE ?)";
    $like = '%' . $keyword . '%';
    $params[] = $like; $params[] = $like; $types .= 'ss';
}

switch ($sort) {
    case 'price_asc': $sql .= " ORDER BY p.price ASC"; break;
    case 'price_desc': $sql .= " ORDER BY p.price DESC"; break;
    default: $sql .= " ORDER BY p.created_at DESC";
}

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result();

$pageTitle = 'หน้าแรก';
include 'includes/header.php';
?>

<div class="hero-section">
  <div class="container">
    <h1>ยินดีต้อนรับสู่ <span>TUNG TUNG SHOP</span></h1>
    <p>เลือกร้านค้าที่ไว้ใจได้ เลือกแมพที่ชอบ เติมเงินง่าย ซื้อขายสะดวกในที่เดียว</p>
  </div>
</div>

<div class="container mb-5">

  <!-- ---------- ตัวกรองร้านค้า/แมพ/ค้นหา ---------- -->
  <div class="filter-bar">
    <form method="GET" action="index.php" class="row g-2 align-items-center">
      <div class="col-md-3">
        <select name="shop_id" class="form-select">
          <option value="0">ร้านค้าทั้งหมด</option>
          <?php while ($s = $shops->fetch_assoc()): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo $shopId==$s['id']?'selected':''; ?>><?php echo h($s['name']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="map_id" class="form-select">
          <option value="0">แมพทั้งหมด</option>
          <?php while ($m = $maps->fetch_assoc()): ?>
            <option value="<?php echo $m['id']; ?>" <?php echo $mapId==$m['id']?'selected':''; ?>><?php echo h($m['name']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <input type="text" name="q" class="form-control" placeholder="ค้นหาไอดี..." value="<?php echo h($keyword); ?>">
      </div>
      <div class="col-md-2">
        <select name="sort" class="form-select">
          <option value="newest" <?php echo $sort=='newest'?'selected':''; ?>>ใหม่ล่าสุด</option>
          <option value="price_asc" <?php echo $sort=='price_asc'?'selected':''; ?>>ราคาต่ำ-สูง</option>
          <option value="price_desc" <?php echo $sort=='price_desc'?'selected':''; ?>>ราคาสูง-ต่ำ</option>
        </select>
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-primary-custom w-100"><i class="fa-solid fa-filter"></i></button>
      </div>
    </form>
  </div>

  <h2 class="section-title"><i class="fa-solid fa-store"></i> ร้านค้าแนะนำ</h2>
  <div class="row g-3 mb-5">
    <?php
    $shopsList = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM products WHERE shop_id=s.id AND status='available') AS product_count FROM shops s WHERE status='active' ORDER BY id LIMIT 4");
    while ($s = $shopsList->fetch_assoc()):
    ?>
    <div class="col-6 col-md-3">
      <a href="index.php?shop_id=<?php echo $s['id']; ?>">
        <div class="card-custom text-center p-3">
          <div class="mb-2" style="font-size:2.2rem;color:var(--primary-light)"><i class="fa-solid fa-shop"></i></div>
          <div class="card-title mb-1"><?php echo h($s['name']); ?></div>
          <small class="text-muted"><?php echo $s['product_count']; ?> ไอดีพร้อมขาย</small>
        </div>
      </a>
    </div>
    <?php endwhile; ?>
  </div>

  <h2 class="section-title"><i class="fa-solid fa-map"></i> แมพยอดนิยม</h2>
  <div class="row g-3 mb-5">
    <?php
    $mapsList = $conn->query("SELECT m.*, (SELECT COUNT(*) FROM products WHERE map_id=m.id AND status='available') AS product_count FROM maps m WHERE status='active' ORDER BY id LIMIT 5");
    while ($m = $mapsList->fetch_assoc()):
    ?>
    <div class="col-6 col-md-2-4" style="flex:0 0 20%;max-width:20%">
      <a href="index.php?map_id=<?php echo $m['id']; ?>">
        <div class="card-custom text-center p-3">
          <div class="mb-2" style="font-size:2rem;color:var(--accent)"><i class="fa-solid fa-gamepad"></i></div>
          <div class="card-title mb-1" style="font-size:0.95rem"><?php echo h($m['name']); ?></div>
          <small class="text-muted"><?php echo $m['product_count']; ?> ไอดี</small>
        </div>
      </a>
    </div>
    <?php endwhile; ?>
  </div>

  <h2 class="section-title"><i class="fa-solid fa-bag-shopping"></i> ไอดีพร้อมขาย</h2>
  <div class="row g-4">
    <?php if ($products->num_rows === 0): ?>
      <div class="empty-state">
        <div class="icon"><i class="fa-solid fa-box-open"></i></div>
        <p>ไม่พบไอดีที่ตรงกับเงื่อนไขการค้นหา</p>
      </div>
    <?php else: ?>
      <?php while ($p = $products->fetch_assoc()): ?>
      <div class="col-6 col-md-4 col-lg-3">
        <div class="card-custom">
          <div class="card-img-wrap">
            <?php if ($p['image']): ?>
              <img src="uploads/products/<?php echo h($p['image']); ?>" alt="<?php echo h($p['title']); ?>">
            <?php else: ?>
              <i class="fa-solid fa-cube"></i>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <span class="badge-shop"><?php echo h($p['shop_name']); ?> · <?php echo h($p['map_name']); ?></span>
            <div class="card-title"><?php echo h($p['title']); ?></div>
            <div class="price-tag mb-2"><?php echo formatMoney($p['price']); ?></div>
            <a href="product.php?id=<?php echo $p['id']; ?>" class="btn btn-outline-custom w-100 btn-sm">ดูรายละเอียด</a>
          </div>
        </div>
      </div>
      <?php endwhile; ?>
    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
