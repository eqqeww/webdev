<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

// กัน token รั่วผ่าน Referer และกัน cache
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$reset = findValidPasswordReset($conn, $token);
$errors = [];

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (strlen($password) < 6) {
            $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif ($password !== $confirm) {
            $errors[] = 'รหัสผ่านยืนยันไม่ตรงกัน';
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                // ทำเครื่องหมายใช้แล้วก่อน (เงื่อนไข used_at IS NULL กันการกดซ้ำพร้อมกัน)
                $stmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL AND expires_at > NOW()");
                $stmt->bind_param('i', $reset['id']);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) throw new Exception('ลิงก์นี้ถูกใช้ไปแล้วหรือหมดอายุ');

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param('si', $hash, $reset['user_id']);
                $stmt->execute();

                // ยกเลิกลิงก์อื่นที่ค้างอยู่ของบัญชีนี้
                $stmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                $stmt->bind_param('i', $reset['user_id']);
                $stmt->execute();
                $conn->commit();

                logActivity($conn, 'password_reset_done', 'user', $reset['user_id'],
                    ['by_admin_link' => $reset['created_by_admin'] !== null],
                    ['id' => $reset['user_id'], 'username' => $reset['username'], 'role' => null]);
                setFlash('success', 'ตั้งรหัสผ่านใหม่สำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่');
                header('Location: login.php');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = $e->getMessage();
                $reset = null;
            }
        }
    }
}

$pageTitle = 'ตั้งรหัสผ่านใหม่';
include 'includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-box">
    <h3><i class="fa-solid fa-lock"></i> ตั้งรหัสผ่านใหม่</h3>

    <?php if (!$reset): ?>
      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="border-radius:10px;border:none"><?php echo h($errors[0]); ?></div>
      <?php else: ?>
        <div class="alert alert-danger" style="border-radius:10px;border:none">ลิงก์ไม่ถูกต้อง ถูกใช้ไปแล้ว หรือหมดอายุ</div>
      <?php endif; ?>
      <a href="forgot_password.php" class="btn btn-primary-custom w-100">ขอลิงก์ใหม่</a>
    <?php else: ?>
      <p class="text-muted mb-4">บัญชี: <strong style="color:var(--accent)"><?php echo h($reset['username']); ?></strong></p>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="border-radius:10px;border:none">
          <ul class="mb-0 ps-3"><?php foreach ($errors as $err) echo '<li>' . h($err) . '</li>'; ?></ul>
        </div>
      <?php endif; ?>

      <form method="POST" action="reset_password.php">
        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
        <input type="hidden" name="token" value="<?php echo h($token); ?>">
        <label class="form-label">รหัสผ่านใหม่</label>
        <input type="password" class="form-control" name="password" placeholder="อย่างน้อย 6 ตัวอักษร" required autocomplete="new-password">
        <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
        <input type="password" class="form-control" name="confirm_password" required autocomplete="new-password">
        <button type="submit" class="btn btn-primary-custom w-100 mt-2">บันทึกรหัสผ่านใหม่</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
