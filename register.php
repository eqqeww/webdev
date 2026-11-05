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
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($username) < 3 || strlen($username) > 50) {
            $errors[] = 'ชื่อผู้ใช้ต้องมีความยาว 3-50 ตัวอักษร';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = 'ชื่อผู้ใช้ใช้ได้เฉพาะตัวอักษรอังกฤษ ตัวเลข และ _ เท่านั้น';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
        }

        if (strlen($password) < 6) {
            $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif ($password !== $confirmPassword) {
            $errors[] = 'รหัสผ่านยืนยันไม่ตรงกัน';
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->bind_param('ss', $username, $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้งานแล้ว';
            }
        }

        if (empty($errors)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $username, $email, $hashedPassword);
            if ($stmt->execute()) {
                $newUserId = $conn->insert_id;
                logActivity($conn, 'register', 'user', $newUserId, ['email' => $email],
                    ['id' => $newUserId, 'username' => $username, 'role' => 'user']);
                setFlash('success', 'สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ');
                header('Location: login.php');
                exit;
            } else {
                $errors[] = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
            }
        }
    }
}

$pageTitle = 'สมัครสมาชิก';
include 'includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-box">
    <h3><i class="fa-solid fa-user-plus"></i> สมัครสมาชิก</h3>
    <p class="text-muted mb-4">สร้างบัญชีเพื่อเริ่มซื้อขายไอดี Roblox</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" style="border-radius:10px;border:none">
        <ul class="mb-0 ps-3">
          <?php foreach ($errors as $err) echo '<li>' . h($err) . '</li>'; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" action="register.php">
      <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
      <label class="form-label">ชื่อผู้ใช้</label>
      <input type="text" class="form-control" name="username" placeholder="เช่น player123" required value="<?php echo h($_POST['username'] ?? ''); ?>">

      <label class="form-label">อีเมล</label>
      <input type="email" class="form-control" name="email" placeholder="example@email.com" required value="<?php echo h($_POST['email'] ?? ''); ?>">

      <label class="form-label">รหัสผ่าน</label>
      <input type="password" class="form-control" name="password" placeholder="อย่างน้อย 6 ตัวอักษร" required>

      <label class="form-label">ยืนยันรหัสผ่าน</label>
      <input type="password" class="form-control" name="confirm_password" placeholder="กรอกรหัสผ่านอีกครั้ง" required>

      <button type="submit" class="btn btn-primary-custom w-100 mt-2">สมัครสมาชิก</button>
    </form>
    <p class="text-center mt-4 mb-0 text-muted">มีบัญชีอยู่แล้ว? <a href="login.php" style="color:var(--accent)">เข้าสู่ระบบ</a></p>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
