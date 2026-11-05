<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$errors = [];
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $email = trim($_POST['email'] ?? '');
        $ip = getClientIp();

        // จำกัดจำนวนครั้ง: ต่อ IP ไม่เกิน 10 ครั้ง/ชม. (กันยิงสแปมอีเมล)
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM password_resets WHERE requested_ip = ? AND created_by_admin IS NULL AND created_at > NOW() - INTERVAL 1 HOUR");
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $ipCount = (int)$stmt->get_result()->fetch_assoc()['c'];

        if ($ipCount >= 10) {
            $errors[] = 'ส่งคำขอบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
        } else {
            $submitted = true;
            $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user) {
                // จำกัด 3 ครั้ง/ชม. ต่อบัญชี
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
                $stmt->bind_param('i', $user['id']);
                $stmt->execute();
                if ((int)$stmt->get_result()->fetch_assoc()['c'] < 3) {
                    $token = createPasswordReset($conn, $user['id'], RESET_TOKEN_TTL_MIN);
                    $url = passwordResetUrl($token);
                    $body = '<p>สวัสดีคุณ ' . h($user['username']) . '</p>'
                          . '<p>มีการขอรีเซ็ตรหัสผ่านของบัญชีนี้ที่ ' . h(SITE_NAME) . ' กดลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่ (ใช้ได้ ' . RESET_TOKEN_TTL_MIN . ' นาที และใช้ได้ครั้งเดียว)</p>'
                          . '<p><a href="' . h($url) . '">' . h($url) . '</a></p>'
                          . '<p>ถ้าคุณไม่ได้เป็นคนขอ ให้เพิกเฉยอีเมลนี้ รหัสผ่านเดิมจะไม่ถูกเปลี่ยน</p>';
                    $sent = sendMail($user['email'], 'รีเซ็ตรหัสผ่าน - ' . SITE_NAME, $body);
                    logActivity($conn, 'password_reset_request', 'user', $user['id'], ['email_sent' => $sent],
                        ['id' => $user['id'], 'username' => $user['username'], 'role' => 'user']);
                }
            } else {
                logActivity($conn, 'password_reset_request', null, null, ['email_not_found' => mb_substr($email, 0, 100)]);
            }
        }
    }
}

$pageTitle = 'ลืมรหัสผ่าน';
include 'includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-box">
    <h3><i class="fa-solid fa-key"></i> ลืมรหัสผ่าน</h3>

    <?php if ($submitted): ?>
      <div class="alert alert-success" style="border-radius:10px;border:none">
        ถ้าอีเมลนี้ลงทะเบียนไว้ ระบบได้ส่งลิงก์ตั้งรหัสผ่านใหม่ให้แล้ว (ลิงก์ใช้ได้ <?php echo (int)RESET_TOKEN_TTL_MIN; ?> นาที)
        กรุณาตรวจสอบกล่องจดหมายและโฟลเดอร์สแปม
      </div>
      <?php if (!MAIL_ENABLED): ?>
        <div class="alert alert-warning" style="border-radius:10px;border:none">
          ขณะนี้ระบบส่งอีเมลยังไม่เปิดใช้งาน กรุณาติดต่อแอดมินเพื่อขอลิงก์รีเซ็ตรหัสผ่าน
        </div>
      <?php endif; ?>
      <a href="login.php" class="btn btn-outline-custom w-100">กลับหน้าเข้าสู่ระบบ</a>
    <?php else: ?>
      <p class="text-muted mb-4">กรอกอีเมลที่ใช้สมัคร เราจะส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ให้</p>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="border-radius:10px;border:none">
          <ul class="mb-0 ps-3"><?php foreach ($errors as $err) echo '<li>' . h($err) . '</li>'; ?></ul>
        </div>
      <?php endif; ?>

      <?php if (!MAIL_ENABLED): ?>
        <div class="alert alert-warning" style="border-radius:10px;border:none">
          ระบบส่งอีเมลยังไม่เปิดใช้งาน หากลืมรหัสผ่านกรุณาติดต่อแอดมินเพื่อขอลิงก์รีเซ็ต
        </div>
      <?php endif; ?>

      <form method="POST" action="forgot_password.php">
        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
        <label class="form-label">อีเมล</label>
        <input type="email" class="form-control" name="email" required value="<?php echo h($_POST['email'] ?? ''); ?>">
        <button type="submit" class="btn btn-primary-custom w-100 mt-2">ส่งลิงก์รีเซ็ตรหัสผ่าน</button>
      </form>
      <p class="text-center mt-4 mb-0"><a href="login.php" style="color:var(--accent)">กลับหน้าเข้าสู่ระบบ</a></p>
    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
