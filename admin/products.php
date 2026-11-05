<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $shopId = (int)$_POST['shop_id'];
        $mapId = (int)$_POST['map_id'];
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $accountDetail = trim($_POST['account_detail'] ?? '');
        $status = $_POST['status'] ?? 'available';

        if ($title === '' || $price <= 0 || $shopId <= 0 || $mapId <= 0) {
            setFlash('error', 'กรุณากรอกข้อมูลให้ครบถ้วน (ชื่อ, ราคา, ร้านค้า, แมพ)');
        } else {
            $imageName = null;
            if (!empty($_FILES['image']['name'])) {
                $upload = uploadImage('image', UPLOAD_PRODUCT_DIR);
                if ($upload['success']) $imageName = $upload['filename'];
            }

            if ($id > 0) {
                $stmt = $conn->prepare("SELECT shop_id, map_id, title, description, price, account_detail, status FROM products WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc() ?: [];
                if ($imageName) {
                    $stmt = $conn->prepare("UPDATE products SET shop_id=?, map_id=?, title=?, description=?, price=?, account_detail=?, status=?, image=? WHERE id=?");
                    $stmt->bind_param('iissdsssi', $shopId, $mapId, $title, $description, $price, $accountDetail, $status, $imageName, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE products SET shop_id=?, map_id=?, title=?, description=?, price=?, account_detail=?, status=? WHERE id=?");
                    $stmt->bind_param('iissdssi', $shopId, $mapId, $title, $description, $price, $accountDetail, $status, $id);
                }
                $stmt->execute();
                $changes = diffFields($old,
                    ['shop_id' => $shopId, 'map_id' => $mapId, 'title' => $title, 'description' => $description,
                     'price' => $price, 'status' => $status],
                    ['shop_id', 'map_id', 'title', 'description', 'price', 'status']);
                // ข้อมูลบัญชีไอดี (user/pass) ไม่เก็บลง log บันทึกแค่ว่ามีการแก้ไข
                if (($old['account_detail'] ?? '') !== $accountDetail) $changes['account_detail'] = 'มีการแก้ไข (ไม่แสดงค่า)';
                if ($imageName) $changes['image'] = 'เปลี่ยนรูป';
                logActivity($conn, 'product_update', 'product', $id, ['title' => $title, 'changes' => $changes]);
                setFlash('success', 'แก้ไขสินค้าเรียบร้อยแล้ว');
            } else {
                $stmt = $conn->prepare("INSERT INTO products (shop_id, map_id, title, description, price, account_detail, status, image) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iissdsss', $shopId, $mapId, $title, $description, $price, $accountDetail, $status, $imageName);
                $stmt->execute();
                logActivity($conn, 'product_create', 'product', $conn->insert_id,
                    ['title' => $title, 'price' => $price, 'status' => $status, 'shop_id' => $shopId, 'map_id' => $mapId]);
                setFlash('success', 'เพิ่มสินค้าใหม่เรียบร้อยแล้ว');
            }
        }
    }
    header('Location: products.php');
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("SELECT title, price, status FROM products WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($old && $stmt->affected_rows > 0) {
        logActivity($conn, 'product_delete', 'product', $id,
            ['title' => $old['title'], 'price' => (float)$old['price'], 'status' => $old['status']]);
    }
    setFlash('success', 'ลบสินค้าเรียบร้อยแล้ว');
    header('Location: products.php');
    exit;
}

$shopsForSelect = $conn->query("SELECT * FROM shops ORDER BY name");
$shopsArr = [];
while ($row = $shopsForSelect->fetch_assoc()) $shopsArr[] = $row;

$mapsForSelect = $conn->query("SELECT * FROM maps ORDER BY name");
$mapsArr = [];
while ($row = $mapsForSelect->fetch_assoc()) $mapsArr[] = $row;

$products = $conn->query("SELECT p.*, s.name AS shop_name, m.name AS map_name
                           FROM products p JOIN shops s ON p.shop_id=s.id JOIN maps m ON p.map_id=m.id
                           ORDER BY p.id DESC");

$pageTitle = 'จัดการสินค้า';
include 'inc_header.php';
$statusLabel = ['available' => 'พร้อมขาย', 'sold' => 'ขายแล้ว', 'hidden' => 'ซ่อน'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fa-solid fa-cube"></i> จัดการสินค้า/ไอดี</h3>
  <button class="btn btn-accent-custom" data-bs-toggle="modal" data-bs-target="#productModal" onclick="resetForm()">
    <i class="fa-solid fa-plus"></i> เพิ่มสินค้าใหม่
  </button>
</div>

<div class="table-responsive">
  <table class="table table-custom">
    <thead><tr><th>#</th><th>รูป</th><th>ชื่อสินค้า</th><th>ร้าน/แมพ</th><th>ราคา</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
    <tbody>
      <?php if ($products->num_rows === 0): ?><tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีสินค้า</td></tr><?php endif; ?>
      <?php while ($p = $products->fetch_assoc()): ?>
      <tr>
        <td><?php echo $p['id']; ?></td>
        <td><?php if ($p['image']): ?><img src="../uploads/products/<?php echo h($p['image']); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:8px"><?php else: ?><i class="fa-solid fa-cube"></i><?php endif; ?></td>
        <td><?php echo h($p['title']); ?></td>
        <td><small><?php echo h($p['shop_name']); ?> / <?php echo h($p['map_name']); ?></small></td>
        <td><?php echo number_format($p['price'],2); ?></td>
        <td><span class="status-badge status-<?php echo $p['status']; ?>"><?php echo $statusLabel[$p['status']]; ?></span></td>
        <td>
          <a href="?edit=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-custom" data-bs-toggle="modal" data-bs-target="#productModal" onclick='fillForm(<?php echo json_encode($p); ?>)'><i class="fa-solid fa-pen"></i></a>
          <a href="?delete=<?php echo $p['id']; ?>" class="btn btn-sm" style="background:var(--danger);color:#fff" onclick="return confirm('ยืนยันการลบสินค้านี้?')"><i class="fa-solid fa-trash"></i></a>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="productModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="background:var(--surface);color:var(--text-main);border:1px solid var(--border-color)">
      <form method="POST" action="products.php" enctype="multipart/form-data">
        <div class="modal-header" style="border-color:var(--border-color)">
          <h5 class="modal-title" id="modalTitle">เพิ่มสินค้าใหม่</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
          <input type="hidden" name="id" id="p_id" value="">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">ร้านค้า</label>
              <select class="form-select" name="shop_id" id="p_shop_id" required>
                <?php foreach ($shopsArr as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo h($s['name']); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">แมพ</label>
              <select class="form-select" name="map_id" id="p_map_id" required>
                <?php foreach ($mapsArr as $m): ?><option value="<?php echo $m['id']; ?>"><?php echo h($m['name']); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">ชื่อสินค้า/หัวข้อประกาศ</label>
              <input type="text" class="form-control" name="title" id="p_title" required>
            </div>
            <div class="col-12">
              <label class="form-label">รายละเอียดสินค้า (แสดงให้ทุกคนเห็น)</label>
              <textarea class="form-control" name="description" id="p_description" rows="2"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">ราคา (บาท)</label>
              <input type="number" step="0.01" min="0" class="form-control" name="price" id="p_price" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">สถานะ</label>
              <select class="form-select" name="status" id="p_status">
                <option value="available">พร้อมขาย</option>
                <option value="sold">ขายแล้ว</option>
                <option value="hidden">ซ่อน</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">ข้อมูลบัญชี (username/password) - จะแสดงเฉพาะผู้ที่ซื้อแล้วเท่านั้น</label>
              <textarea class="form-control" name="account_detail" id="p_account_detail" rows="2" placeholder="user: xxx / pass: xxx"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">รูปภาพสินค้า (ถ้าต้องการเปลี่ยน)</label>
              <input type="file" class="form-control" name="image" accept="image/*">
            </div>
          </div>
        </div>
        <div class="modal-footer" style="border-color:var(--border-color)">
          <button type="submit" name="save" value="1" class="btn btn-primary-custom w-100">บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function resetForm() {
  document.getElementById('modalTitle').innerText = 'เพิ่มสินค้าใหม่';
  document.getElementById('p_id').value = '';
  document.getElementById('p_title').value = '';
  document.getElementById('p_description').value = '';
  document.getElementById('p_price').value = '';
  document.getElementById('p_status').value = 'available';
  document.getElementById('p_account_detail').value = '';
}
function fillForm(p) {
  document.getElementById('modalTitle').innerText = 'แก้ไขสินค้า';
  document.getElementById('p_id').value = p.id;
  document.getElementById('p_shop_id').value = p.shop_id;
  document.getElementById('p_map_id').value = p.map_id;
  document.getElementById('p_title').value = p.title;
  document.getElementById('p_description').value = p.description || '';
  document.getElementById('p_price').value = p.price;
  document.getElementById('p_status').value = p.status;
  document.getElementById('p_account_detail').value = p.account_detail || '';
}
</script>

<?php include 'inc_footer.php'; ?>
