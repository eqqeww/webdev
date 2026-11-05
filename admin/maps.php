<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($name === '') {
            setFlash('error', 'กรุณากรอกชื่อแมพ');
        } else {
            $imageName = null;
            if (!empty($_FILES['image']['name'])) {
                $upload = uploadImage('image', UPLOAD_PRODUCT_DIR);
                if ($upload['success']) $imageName = $upload['filename'];
            }

            if ($id > 0) {
                $stmt = $conn->prepare("SELECT name, description, status FROM maps WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc() ?: [];
                if ($imageName) {
                    $stmt = $conn->prepare("UPDATE maps SET name=?, description=?, status=?, image=? WHERE id=?");
                    $stmt->bind_param('ssssi', $name, $description, $status, $imageName, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE maps SET name=?, description=?, status=? WHERE id=?");
                    $stmt->bind_param('sssi', $name, $description, $status, $id);
                }
                $stmt->execute();
                $changes = diffFields($old, ['name' => $name, 'description' => $description, 'status' => $status],
                                      ['name', 'description', 'status']);
                if ($imageName) $changes['image'] = 'เปลี่ยนรูป';
                logActivity($conn, 'map_update', 'map', $id, ['name' => $name, 'changes' => $changes]);
                setFlash('success', 'แก้ไขแมพเรียบร้อยแล้ว');
            } else {
                $stmt = $conn->prepare("INSERT INTO maps (name, description, status, image) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $name, $description, $status, $imageName);
                $stmt->execute();
                logActivity($conn, 'map_create', 'map', $conn->insert_id, ['name' => $name, 'status' => $status]);
                setFlash('success', 'เพิ่มแมพใหม่เรียบร้อยแล้ว');
            }
        }
    }
    header('Location: maps.php');
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $check = $conn->prepare("SELECT COUNT(*) c FROM products WHERE map_id = ?");
    $check->bind_param('i', $id);
    $check->execute();
    $count = $check->get_result()->fetch_assoc()['c'];
    if ($count > 0) {
        setFlash('error', 'ไม่สามารถลบแมพนี้ได้ เนื่องจากมีสินค้าผูกอยู่ ' . $count . ' รายการ');
    } else {
        $stmt = $conn->prepare("SELECT name FROM maps WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();

        $stmt = $conn->prepare("DELETE FROM maps WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($old && $stmt->affected_rows > 0) {
            logActivity($conn, 'map_delete', 'map', $id, ['name' => $old['name']]);
        }
        setFlash('success', 'ลบแมพเรียบร้อยแล้ว');
    }
    header('Location: maps.php');
    exit;
}

$maps = $conn->query("SELECT m.*, (SELECT COUNT(*) FROM products WHERE map_id=m.id) AS product_count FROM maps m ORDER BY m.id DESC");

$pageTitle = 'จัดการแมพ';
include 'inc_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fa-solid fa-map"></i> จัดการแมพ</h3>
  <button class="btn btn-accent-custom" data-bs-toggle="modal" data-bs-target="#mapModal" onclick="resetForm()">
    <i class="fa-solid fa-plus"></i> เพิ่มแมพใหม่
  </button>
</div>

<div class="table-responsive">
  <table class="table table-custom">
    <thead><tr><th>#</th><th>รูป</th><th>ชื่อแมพ</th><th>คำอธิบาย</th><th>สินค้า</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
    <tbody>
      <?php if ($maps->num_rows === 0): ?><tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีแมพ</td></tr><?php endif; ?>
      <?php while ($m = $maps->fetch_assoc()): ?>
      <tr>
        <td><?php echo $m['id']; ?></td>
        <td><?php if ($m['image']): ?><img src="../uploads/products/<?php echo h($m['image']); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:8px"><?php else: ?><i class="fa-solid fa-gamepad"></i><?php endif; ?></td>
        <td><?php echo h($m['name']); ?></td>
        <td><small class="text-muted"><?php echo h(mb_strimwidth($m['description'] ?? '', 0, 60, '...')); ?></small></td>
        <td><?php echo $m['product_count']; ?></td>
        <td><span class="status-badge status-<?php echo $m['status']; ?>"><?php echo $m['status']==='active'?'เปิดใช้งาน':'ปิด'; ?></span></td>
        <td>
          <a href="?edit=<?php echo $m['id']; ?>" class="btn btn-sm btn-outline-custom" data-bs-toggle="modal" data-bs-target="#mapModal" onclick='fillForm(<?php echo json_encode($m); ?>)'><i class="fa-solid fa-pen"></i></a>
          <a href="?delete=<?php echo $m['id']; ?>" class="btn btn-sm" style="background:var(--danger);color:#fff" onclick="return confirm('ยืนยันการลบแมพนี้?')"><i class="fa-solid fa-trash"></i></a>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="mapModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="background:var(--surface);color:var(--text-main);border:1px solid var(--border-color)">
      <form method="POST" action="maps.php" enctype="multipart/form-data">
        <div class="modal-header" style="border-color:var(--border-color)">
          <h5 class="modal-title" id="modalTitle">เพิ่มแมพใหม่</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
          <input type="hidden" name="id" id="map_id" value="">
          <label class="form-label">ชื่อแมพ</label>
          <input type="text" class="form-control mb-3" name="name" id="map_name" required>
          <label class="form-label">คำอธิบาย</label>
          <textarea class="form-control mb-3" name="description" id="map_description" rows="3"></textarea>
          <label class="form-label">รูปภาพ (ถ้าต้องการเปลี่ยน)</label>
          <input type="file" class="form-control mb-3" name="image" accept="image/*">
          <label class="form-label">สถานะ</label>
          <select class="form-select" name="status" id="map_status">
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
  document.getElementById('modalTitle').innerText = 'เพิ่มแมพใหม่';
  document.getElementById('map_id').value = '';
  document.getElementById('map_name').value = '';
  document.getElementById('map_description').value = '';
  document.getElementById('map_status').value = 'active';
}
function fillForm(map) {
  document.getElementById('modalTitle').innerText = 'แก้ไขแมพ';
  document.getElementById('map_id').value = map.id;
  document.getElementById('map_name').value = map.name;
  document.getElementById('map_description').value = map.description || '';
  document.getElementById('map_status').value = map.status;
}
</script>

<?php include 'inc_footer.php'; ?>
