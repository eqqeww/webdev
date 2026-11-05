<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireAdmin();

$actionMeta = logActionMeta();
$categoryLabels = logCategoryLabels();

// ---------- ตรวจว่ามีตาราง activity_logs แล้วหรือยัง ----------
$tableExists = $conn->query("SHOW TABLES LIKE 'activity_logs'")->num_rows > 0;

// ---------- ตัวกรอง ----------
$fCategory = $_GET['category'] ?? '';
$fAction   = $_GET['action'] ?? '';
$fRole     = $_GET['role'] ?? '';
$fQ        = trim($_GET['q'] ?? '');
$fFrom     = $_GET['from'] ?? '';
$fTo       = $_GET['to'] ?? '';

$validDate = function ($d) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
};
if (!$validDate($fFrom)) $fFrom = '';
if (!$validDate($fTo))   $fTo = '';

$where = [];
$types = '';
$params = [];

if ($fCategory !== '' && isset($categoryLabels[$fCategory])) {
    $actionsInCat = [];
    foreach ($actionMeta as $code => $meta) {
        if ($meta[1] === $fCategory) $actionsInCat[] = $code;
    }
    if ($actionsInCat) {
        $where[] = 'action IN (' . implode(',', array_fill(0, count($actionsInCat), '?')) . ')';
        $types .= str_repeat('s', count($actionsInCat));
        array_push($params, ...$actionsInCat);
    }
} else {
    $fCategory = '';
}
if ($fAction !== '' && isset($actionMeta[$fAction])) {
    $where[] = 'action = ?';
    $types .= 's';
    $params[] = $fAction;
} else {
    $fAction = '';
}
if (in_array($fRole, ['user', 'admin'], true)) {
    $where[] = 'role = ?';
    $types .= 's';
    $params[] = $fRole;
} else {
    $fRole = '';
}
if ($fQ !== '') {
    $like = '%' . $fQ . '%';
    $where[] = '(username LIKE ? OR ip_address LIKE ? OR details LIKE ? OR CAST(target_id AS CHAR) = ?)';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $fQ);
}
if ($fFrom !== '') {
    $where[] = 'created_at >= ?';
    $types .= 's';
    $params[] = $fFrom . ' 00:00:00';
}
if ($fTo !== '') {
    $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $types .= 's';
    $params[] = $fTo;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// ---------- แปลง details (JSON) เป็นข้อความอ่านง่าย ----------
function logValueToText($v) {
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null || $v === '') return '-';
    if (is_array($v)) {
        if (isset($v['from']) || isset($v['to'])) {
            return logValueToText($v['from'] ?? null) . ' → ' . logValueToText($v['to'] ?? null);
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string)$v;
}

function logDetailsToText($json) {
    if ($json === null || $json === '') return '';
    $data = json_decode($json, true);
    if (!is_array($data)) return (string)$json;
    $labels = [
        'attempted_username' => 'ชื่อที่ใช้ล็อกอิน', 'email' => 'อีเมล', 'amount' => 'จำนวนเงิน',
        'balance_before' => 'ยอดก่อน', 'balance_after' => 'ยอดหลัง', 'product' => 'สินค้า',
        'product_id' => 'รหัสสินค้า', 'price' => 'ราคา', 'reason' => 'สาเหตุ', 'admin_note' => 'หมายเหตุแอดมิน',
        'note' => 'หมายเหตุ', 'slip' => 'สลิป', 'user_id' => 'รหัสผู้ใช้', 'target_username' => 'ผู้ใช้เป้าหมาย',
        'title' => 'ชื่อ', 'name' => 'ชื่อ', 'status' => 'สถานะ', 'from' => 'จาก', 'to' => 'เป็น',
        'shop_id' => 'ร้าน', 'map_id' => 'แมพ', 'description' => 'คำอธิบาย', 'account_detail' => 'ข้อมูลบัญชี',
        'image' => 'รูป', 'logo' => 'โลโก้',
    ];
    $parts = [];
    foreach ($data as $k => $v) {
        if ($k === 'changes') {
            if (empty($v)) { $parts[] = 'ไม่มีข้อมูลเปลี่ยนแปลง'; continue; }
            foreach ($v as $field => $chg) {
                $parts[] = ($labels[$field] ?? $field) . ': ' . logValueToText($chg);
            }
            continue;
        }
        $parts[] = ($labels[$k] ?? $k) . ': ' . logValueToText($v);
    }
    return implode(' | ', $parts);
}

// ---------- Export CSV ----------
if ($tableExists && isset($_GET['export'])) {
    $sql = "SELECT * FROM activity_logs" . $whereSql . " ORDER BY id DESC LIMIT 10000";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM ให้ Excel อ่านภาษาไทยถูก
    fputcsv($out, ['ID', 'เวลา', 'ผู้ใช้', 'บทบาท', 'การกระทำ', 'เป้าหมาย', 'รายละเอียด', 'IP']);

    // กัน CSV/Formula Injection (ค่าที่ขึ้นต้นด้วย = + - @ จะถูกตีความเป็นสูตรใน Excel)
    $safe = function ($v) {
        $v = (string)$v;
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['id'], $r['created_at'], $safe($r['username'] ?? 'guest'), $r['role'],
            $actionMeta[$r['action']][0] ?? $r['action'],
            $r['target_type'] ? $r['target_type'] . '#' . $r['target_id'] : '',
            $safe(logDetailsToText($r['details'])), $r['ip_address'],
        ]);
    }
    fclose($out);
    exit;
}

// ---------- แบ่งหน้า ----------
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = 0;
$logs = null;
$summary = ['today' => 0, 'failed_login_24h' => 0, 'admin_actions_24h' => 0];

if ($tableExists) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM activity_logs" . $whereSql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT * FROM activity_logs" . $whereSql . " ORDER BY id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $t2 = $types . 'ii';
    $p2 = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($t2, ...$p2);
    $stmt->execute();
    $logs = $stmt->get_result();

    // สรุปด้านบน (ไม่ขึ้นกับตัวกรอง)
    $summary['today'] = (int)$conn->query("SELECT COUNT(*) c FROM activity_logs WHERE created_at >= CURDATE()")->fetch_assoc()['c'];
    $summary['failed_login_24h'] = (int)$conn->query("SELECT COUNT(*) c FROM activity_logs WHERE action='login_failed' AND created_at >= NOW() - INTERVAL 1 DAY")->fetch_assoc()['c'];
    $summary['admin_actions_24h'] = (int)$conn->query("SELECT COUNT(*) c FROM activity_logs WHERE role='admin' AND action NOT IN ('login_success','logout') AND created_at >= NOW() - INTERVAL 1 DAY")->fetch_assoc()['c'];
} else {
    $totalPages = 1;
}

// สร้างลิงก์แบ่งหน้าโดยคงตัวกรองไว้
function logsUrl($overrides = []) {
    $q = array_merge($_GET, $overrides);
    unset($q['export']);
    foreach ($q as $k => $v) if ($v === '' || $v === null) unset($q[$k]);
    return '?' . http_build_query($q);
}

$pageTitle = 'Log ระบบ';
include 'inc_header.php';
?>

<style>
  /* .card-custom ในธีมมี height:100% ซึ่งทำให้การ์ดยืดเต็มจอ และ text-muted ของ Bootstrap เข้มเกินบนพื้นหลังดำ */
  .log-page .text-muted { color: var(--text-muted) !important; }
  .log-page .card-custom { height: auto; }
  .log-page .card-custom:hover { transform: none; }
  .log-page .filter-bar { margin-bottom: 1.5rem; }
  .log-page .filter-bar .form-label { color: var(--text-main); }
  .log-page .filter-bar input[type=date] { color-scheme: dark; }
</style>
<div class="log-page">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h3 class="mb-0"><i class="fa-solid fa-clipboard-list"></i> Log ระบบ (Audit Log)</h3>
  <?php if ($tableExists): ?>
  <a href="<?php echo h(logsUrl(['export' => 1, 'page' => null])); ?>&export=1" class="btn btn-outline-custom btn-sm">
    <i class="fa-solid fa-file-csv"></i> ดาวน์โหลด CSV (ตามตัวกรอง)
  </a>
  <?php endif; ?>
</div>

<?php if (!$tableExists): ?>
  <div class="alert alert-warning" style="border-radius:12px;border:none">
    <strong>ยังไม่พบตาราง <code>activity_logs</code></strong><br>
    กรุณา import ไฟล์ <code>config/migration_activity_logs.sql</code> ผ่าน phpMyAdmin แล้วรีเฟรชหน้านี้
  </div>
<?php else: ?>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="card-custom p-3"><small class="text-muted">กิจกรรมวันนี้</small><div class="fw-bold fs-4"><?php echo number_format($summary['today']); ?></div></div></div>
  <div class="col-md-4"><div class="card-custom p-3"><small class="text-muted">ล็อกอินไม่สำเร็จ (24 ชม.)</small><div class="fw-bold fs-4 <?php echo $summary['failed_login_24h'] >= 10 ? 'text-danger' : ''; ?>"><?php echo number_format($summary['failed_login_24h']); ?></div></div></div>
  <div class="col-md-4"><div class="card-custom p-3"><small class="text-muted">การดำเนินการของแอดมิน (24 ชม.)</small><div class="fw-bold fs-4"><?php echo number_format($summary['admin_actions_24h']); ?></div></div></div>
</div>

<form method="GET" class="filter-bar">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small mb-1">หมวดหมู่</label>
      <select name="category" class="form-select form-select-sm">
        <option value="">ทั้งหมด</option>
        <?php foreach ($categoryLabels as $code => $label): ?>
          <option value="<?php echo h($code); ?>" <?php echo $fCategory === $code ? 'selected' : ''; ?>><?php echo h($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">การกระทำ</label>
      <select name="action" class="form-select form-select-sm">
        <option value="">ทั้งหมด</option>
        <?php foreach ($actionMeta as $code => $meta): ?>
          <option value="<?php echo h($code); ?>" <?php echo $fAction === $code ? 'selected' : ''; ?>><?php echo h($meta[0]); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">บทบาท</label>
      <select name="role" class="form-select form-select-sm">
        <option value="">ทั้งหมด</option>
        <option value="user" <?php echo $fRole === 'user' ? 'selected' : ''; ?>>ผู้ใช้</option>
        <option value="admin" <?php echo $fRole === 'admin' ? 'selected' : ''; ?>>แอดมิน</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">ตั้งแต่วันที่</label>
      <input type="date" name="from" value="<?php echo h($fFrom); ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">ถึงวันที่</label>
      <input type="date" name="to" value="<?php echo h($fTo); ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-8">
      <label class="form-label small mb-1">ค้นหา (ชื่อผู้ใช้ / IP / รายละเอียด / รหัสเป้าหมาย)</label>
      <input type="text" name="q" value="<?php echo h($fQ); ?>" class="form-control form-control-sm" placeholder="เช่น player123 หรือ 192.168.1.5">
    </div>
    <div class="col-md-4 d-flex gap-2">
      <button type="submit" class="btn btn-accent-custom btn-sm flex-fill"><i class="fa-solid fa-magnifying-glass"></i> ค้นหา</button>
      <a href="logs.php" class="btn btn-outline-custom btn-sm">ล้าง</a>
    </div>
  </div>
</form>

<div class="text-muted small mb-2">พบ <?php echo number_format($total); ?> รายการ (หน้า <?php echo $page; ?>/<?php echo $totalPages; ?>)</div>

<div class="table-responsive">
  <table class="table table-custom align-middle">
    <thead>
      <tr><th>เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>เป้าหมาย</th><th>รายละเอียด</th><th>IP</th></tr>
    </thead>
    <tbody>
      <?php if ($logs->num_rows === 0): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">ไม่พบรายการ log</td></tr>
      <?php endif; ?>
      <?php while ($l = $logs->fetch_assoc()):
          $meta = $actionMeta[$l['action']] ?? [$l['action'], 'other', 'secondary'];
      ?>
      <tr>
        <td><small><?php echo date('d/m/Y H:i:s', strtotime($l['created_at'])); ?></small></td>
        <td>
          <?php if ($l['username']): ?>
            <strong><?php echo h($l['username']); ?></strong>
            <?php if ($l['role'] === 'admin'): ?><span class="badge bg-warning text-dark">admin</span><?php endif; ?>
          <?php else: ?>
            <span class="text-muted">ผู้เยี่ยมชม</span>
          <?php endif; ?>
        </td>
        <td><span class="badge bg-<?php echo h($meta[2]); ?>"><?php echo h($meta[0]); ?></span></td>
        <td>
          <?php if ($l['target_type']): ?>
            <small class="text-muted"><?php echo h($l['target_type']); ?> #<?php echo (int)$l['target_id']; ?></small>
          <?php else: ?>-<?php endif; ?>
        </td>
        <td style="max-width:420px"><small><?php echo h(logDetailsToText($l['details'])) ?: '-'; ?></small></td>
        <td><small class="text-muted" title="<?php echo h($l['user_agent']); ?>"><?php echo h($l['ip_address']); ?></small></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1):
    $start = max(1, $page - 3);
    $end = min($totalPages, $page + 3);
?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center flex-wrap">
    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo h(logsUrl(['page' => $page - 1])); ?>">&laquo;</a></li>
    <?php if ($start > 1): ?>
      <li class="page-item"><a class="page-link" href="<?php echo h(logsUrl(['page' => 1])); ?>">1</a></li>
      <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
    <?php endif; ?>
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo h(logsUrl(['page' => $i])); ?>"><?php echo $i; ?></a></li>
    <?php endfor; ?>
    <?php if ($end < $totalPages): ?>
      <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
      <li class="page-item"><a class="page-link" href="<?php echo h(logsUrl(['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a></li>
    <?php endif; ?>
    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo h(logsUrl(['page' => $page + 1])); ?>">&raquo;</a></li>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>
</div>

<?php include 'inc_footer.php'; ?>
