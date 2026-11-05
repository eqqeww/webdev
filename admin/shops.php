<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireAdmin();

// ---------- เพิ่ม/แก้ไข ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($name === '') {
            setFlash('error', 'กรุณากรอกชื่อร้านค้า');
        } else {
            $logoName = null;
            if (!empty($_FILES['logo']['name'])) {
                $upload = uploadImage('logo', UPLOAD_PRODUCT_DIR);
                if ($upload['success']) $logoName = $upload['filename'];
            }

            if ($id > 0) {
                $stmt = $conn->prepare("SELECT name, description, status FROM shops WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc() ?: [];
                if ($logoName) {
                    $stmt = $conn->prepare("UPDATE shops SET name=?, description=?, status=?, logo=? WHERE id=?");
                    $stmt->bind_param('ssssi', $name, $description, $status, $logoName, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE shops SET name=?, description=?, status=? WHERE id=?");
                    $stmt->bind_param('sssi', $name, $description, $status, $id);
                }
                $stmt->execute();
                $changes = diffFields($old, ['name' => $name, 'description' => $description, 'status' => $status],
                                      ['name', 'description', 'status']);
                if ($logoName) $changes['logo'] = 'เปลี่ยนรูปโลโก้';
                logActivity($conn, 'shop_update', 'shop', $id, ['name' => $name, 'changes' => $changes]);
                setFlash('success', 'แก้ไขร้านค้าเรียบร้อยแล้ว');
            } else {
                $stmt = $conn->prepare("INSERT INTO shops (name, description, status, logo) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $name, $description, $status, $logoName);
                $stmt->execute();
                logActivity($conn, 'shop_create', 'shop', $conn->insert_id, ['name' => $name, 'status' => $status]);
                setFlash('success', 'เพิ่มร้านค้าใหม่เรียบร้อยแล้ว');
            }
        }
    }
    header('Location: shops.php');
    exit;
}

// ---------- ลบ ----------
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $check = $conn->prepare("SELECT COUNT(*) c FROM products WHERE shop_id = ?");
    $check->bind_param('i', $id);
    $check->execute();
    $count = $check->get_result()->fetch_assoc()['c'];
    if ($count > 0) {
        setFlash('error', 'ไม่สามารถลบร้านค้านี้ได้ เนื่องจากมีสินค้าผูกอยู่ ' . $count . ' รายการ');
    } else {
        $stmt = $conn->prepare("SELECT name FROM shops WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();

        $stmt = $conn->prepare("DELETE FROM shops WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($old && $stmt->affected_rows > 0) {
            logActivity($conn, 'shop_delete', 'shop', $id, ['name' => $old['name']]);
        }
        setFlash('success', 'ลบร้านค้าเรียบร้อยแล้ว');
    }
    header('Location: shops.php');
    exit;
}

$shops = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM products WHERE shop_id=s.id) AS product_count FROM shops s ORDER BY s.id DESC");

$editShop = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM shops WHERE id = ?");
    $stmt->bind_param('i', (int)$_GET['edit']);
    $stmt->execute();
    $editShop = $stmt->get_result()->fetch_assoc();
}

$pageTitle = 'จัดการร้านค้า';
include 'inc_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fa-solid fa-store"></i> จัดการร้านค้า</h3>
  <button class="btn btn-accent-custom" data-bs-toggle="modal" data-bs-target="#shopModal" onclick="resetForm()">
    <i class="fa-solid fa-plus"></i> เพิ่มร้านค้าใหม่
  </button>
</div>

<div class="table-responsive">
  <table class="table table-custom">
    <thead><tr><th>#</th><th>โลโก้</th><th>ชื่อร้าน</th><th>คำอธิบาย</th><th>สินค้า</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
    <tbody>
      <?php if ($shops->num_rows === 0): ?><tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีร้านค้า</td></tr><?php endif; ?>
      <?php while ($s = $shops->fetch_assoc()): ?>
      <tr>
        <td><?php echo $s['id']; ?></td>
        <td>
          <?php if ($s['logo']): ?><img src="../uploads/products/<?php echo h($s['logo']); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:8px"><?php else: ?><i class="fa-solid fa-shop"></i><?php endif; ?>
        </td>
        <td><?php echo h($s['name']); ?></td>
        <td><small class="text-muted"><?php echo h(mb_strimwidth($s['description'] ?? '', 0, 60, '...')); ?></small></td>
        <td><?php echo $s['product_count']; ?></td>
        <td><span class="status-badge status-<?php echo $s['status']; ?>"><?php echo $s['status']==='active'?'เปิดใช้งาน':'ปิด'; ?></span></td>
        <td>
          <a href="?edit=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-custom" data-bs-toggle="modal" data-bs-target="#shopModal" onclick='fillForm(<?php echo json_encode($s); ?>)'><i class="fa-solid fa-pen"></i></a>
          <a href="?delete=<?php echo $s['id']; ?>" class="btn btn-sm" style="background:var(--danger);color:#fff" onclick="return confirm('ยืนยันการลบร้านค้านี้?')"><i class="fa-solid fa-trash"></i></a>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<!-- Modal เพิ่ม/แก้ไขร้านค้า -->
<div class="modal fade" id="shopModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="background:var(--surface);color:var(--text-main);border:1px solid var(--border-color)">
      <form method="POST" action="shops.php" enctype="multipart/form-data">
        <div class="modal-header" style="border-color:var(--border-color)">
          <h5 class="modal-title" id="modalTitle">เพิ่มร้านค้าใหม่</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
          <input type="hidden" name="id" id="shop_id" value="">
          <label class="form-label">ชื่อร้านค้า</label>
          <input type="text" class="form-control mb-3" name="name" id="shop_name" required>
          <label class="form-label">คำอธิบาย</label>
          <textarea class="form-control mb-3" name="description" id="shop_description" rows="3"></textarea>
          <label class="form-label">โลโก้ร้าน (ถ้าต้องการเปลี่ยน)</label>
          <input type="file" class="form-control mb-3" name="logo" accept="image/*">
          <label class="form-label">สถานะ</label>
          <select class="form-select" name="status" id="shop_status">
            <option value="active">เปิดใช้งาน</option>
            <option value="inactive">ปิดใช้งาน</option>
          </select>
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
  document.getElementById('modalTitle').innerText = 'เพิ่มร้านค้าใหม่';
  document.getElementById('shop_id').value = '';
  document.getElementById('shop_name').value = '';
  document.getElementById('shop_description').value = '';
  document.getElementById('shop_status').value = 'active';
}
function fillForm(shop) {
  document.getElementById('modalTitle').innerText = 'แก้ไขร้านค้า';
  document.getElementById('shop_id').value = shop.id;
  document.getElementById('shop_name').value = shop.name;
  document.getElementById('shop_description').value = shop.description || '';
  document.getElementById('shop_status').value = shop.status;
}
</script>

<?php include 'inc_footer.php'; ?>
