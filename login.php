<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'banned') {
                $errors[] = 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อแอดมิน';
                logActivity($conn, 'login_banned', 'user', $user['id'], [],
                    ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']]);
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                logActivity($conn, 'login_success', 'user', $user['id']);
                setFlash('success', 'เข้าสู่ระบบสำเร็จ ยินดีต้อนรับ ' . $user['username']);
                header('Location: ' . ($user['role'] === 'admin' ? 'admin/index.php' : 'index.php'));
                exit;
            }
        } else {
            $errors[] = 'ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง';
            // ไม่บันทึกรหัสผ่านที่กรอก บันทึกเฉพาะชื่อที่พยายามเข้า
            logActivity($conn, 'login_failed', $user ? 'user' : null, $user['id'] ?? null,
                ['attempted_username' => mb_substr($username, 0, 100)]);
        }
    }
}

$pageTitle = 'เข้าสู่ระบบ';
include 'includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-box">
    <h3><i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ</h3>
    <p class="text-muted mb-4">เข้าสู่ระบบเพื่อซื้อขายไอดี Roblox</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" style="border-radius:10px;border:none">
        <ul class="mb-0 ps-3">
          <?php foreach ($errors as $err) echo '<li>' . h($err) . '</li>'; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
      <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
      <label class="form-label">ชื่อผู้ใช้ หรือ อีเมล</label>
      <input type="text" class="form-control" name="username" required value="<?php echo h($_POST['username'] ?? ''); ?>">

      <label class="form-label">รหัสผ่าน</label>
      <input type="password" class="form-control" name="password" required>

      <button type="submit" class="btn btn-primary-custom w-100 mt-2">เข้าสู่ระบบ</button>
      <p class="text-end mt-2 mb-0"><a href="forgot_password.php" style="color:var(--accent)"><small>ลืมรหัสผ่าน?</small></a></p>
    </form>
    <p class="text-center mt-4 mb-0 text-muted">ยังไม่มีบัญชี? <a href="register.php" style="color:var(--accent)">สมัครสมาชิก</a></p>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
