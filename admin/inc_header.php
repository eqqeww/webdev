<?php
// ไฟล์นี้ต้อง include หลังจาก require config และเช็ค requireAdmin() แล้วเท่านั้น
require_once __DIR__ . '/../includes/chat.php';
$flash = getFlash();
$adminChatUnread = chatAdminUnreadTotal($conn);
$adminPage = basename($_SERVER['PHP_SELF']);
$pendingTopupCount = $conn->query("SELECT COUNT(*) AS c FROM topups WHERE status='pending'")->fetch_assoc()['c'];
$latestTopupId = (int)$conn->query("SELECT COALESCE(MAX(id),0) AS m FROM topups")->fetch_assoc()['m']; // ใช้เทียบหารายการเติมเงินใหม่ (notify_api.php)
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? h($pageTitle) . ' - Admin' : 'Admin'; ?> | <?php echo SITE_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="d-flex">
  <div class="admin-sidebar" style="width:250px;position:sticky;top:0;height:100vh">
    <div class="px-4 mb-4">
      <a href="index.php" class="navbar-brand" style="font-size:1.3rem"><i class="fa-solid fa-cube"></i> RBX Admin</a>
    </div>
    <nav class="nav flex-column">
      <a class="nav-link <?php echo $adminPage=='index.php'?'active':''; ?>" href="index.php"><i class="fa-solid fa-gauge"></i> แดชบอร์ด</a>
      <a class="nav-link <?php echo $adminPage=='topups.php'?'active':''; ?>" href="topups.php">
        <i class="fa-solid fa-money-bill-transfer"></i> คำขอเติมเงิน
        <span id="topupBadge" class="badge bg-danger ms-1" style="<?php echo $pendingTopupCount > 0 ? '' : 'display:none'; ?>"><?php echo (int)$pendingTopupCount; ?></span>
      </a>
      <a class="nav-link <?php echo $adminPage=='shops.php'?'active':''; ?>" href="shops.php"><i class="fa-solid fa-store"></i> จัดการร้านค้า</a>
      <a class="nav-link <?php echo $adminPage=='maps.php'?'active':''; ?>" href="maps.php"><i class="fa-solid fa-map"></i> จัดการแมพ</a>
      <a class="nav-link <?php echo $adminPage=='products.php'?'active':''; ?>" href="products.php"><i class="fa-solid fa-cube"></i> จัดการสินค้า/ไอดี</a>
      <a class="nav-link <?php echo $adminPage=='orders.php'?'active':''; ?>" href="orders.php"><i class="fa-solid fa-bag-shopping"></i> คำสั่งซื้อ</a>
      <a class="nav-link <?php echo $adminPage=='users.php'?'active':''; ?>" href="users.php"><i class="fa-solid fa-users"></i> ผู้ใช้งาน</a>
      <a class="nav-link <?php echo $adminPage=='chat.php'?'active':''; ?>" href="chat.php"><i class="fa-solid fa-comments"></i> แชทผู้ใช้ <span id="adminChatBadge" class="badge bg-danger ms-1" style="<?php echo $adminChatUnread > 0 ? '' : 'display:none'; ?>"><?php echo $adminChatUnread; ?></span></a>
      <a class="nav-link <?php echo $adminPage=='logs.php'?'active':''; ?>" href="logs.php"><i class="fa-solid fa-clipboard-list"></i> Log ระบบ</a>
      <hr style="border-color:var(--border-color)">
      <a class="nav-link" href="<?php echo SITE_URL; ?>/index.php"><i class="fa-solid fa-house"></i> กลับหน้าเว็บหลัก</a>
      <a class="nav-link text-danger" href="<?php echo SITE_URL; ?>/logout.php"><i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ</a>
    </nav>
    <div class="px-3 mt-3">
      <button type="button" id="soundToggle" class="btn btn-sm btn-outline-custom w-100 mb-2"></button>
      <button type="button" id="notifyTest" class="btn btn-sm btn-outline-custom w-100">ทดสอบแจ้งเตือน</button>
    </div>
    <div id="toastBox" style="position:fixed;top:16px;right:16px;z-index:2000;display:flex;flex-direction:column;gap:8px;max-width:340px"></div>
    <script>
    // ---------- แจ้งเตือนเมื่อมีลูกค้าเติมเงิน (เสียง + ป้ายเด้ง + ชื่อแท็บ) ----------
    (function () {
      var SOUND_KEY = 'rbxAdminSound';
      var lastId = <?php echo $latestTopupId; ?>;
      var csrf = <?php echo json_encode(csrfToken()); ?>;
      var baseTitle = document.title;
      var soundBtn = document.getElementById('soundToggle');
      var testBtn = document.getElementById('notifyTest');
      var toastBox = document.getElementById('toastBox');
      var ctx = null;

      function soundOn() { try { return localStorage.getItem(SOUND_KEY) === '1'; } catch (e) { return false; } }
      function renderSoundBtn() { soundBtn.textContent = soundOn() ? '🔔 เสียงแจ้งเตือน: เปิด' : '🔕 เสียงแจ้งเตือน: ปิด'; }
      function getCtx() {
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return null;
        if (!ctx) ctx = new AC();
        return ctx;
      }
      // เบราว์เซอร์บล็อกเสียงจนกว่าผู้ใช้จะคลิกหน้านั้นสักครั้ง จึงปลุก AudioContext เมื่อคลิกครั้งแรก
      document.addEventListener('click', function () {
        var c = soundOn() ? getCtx() : null;
        if (c && c.state === 'suspended') c.resume();
      });

      function beep() {
        var c = getCtx();
        if (!c) return;
        if (c.state === 'suspended') c.resume();
        [[880, 0], [1175, 0.18]].forEach(function (p) {
          var o = c.createOscillator(), g = c.createGain(), t = c.currentTime + p[1];
          o.type = 'sine';
          o.frequency.value = p[0];
          g.gain.setValueAtTime(0.0001, t);
          g.gain.exponentialRampToValueAtTime(0.25, t + 0.02);
          g.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);
          o.connect(g);
          g.connect(c.destination);
          o.start(t);
          o.stop(t + 0.25);
        });
      }

      function toast(text, href) {
        var el = document.createElement('div');
        el.textContent = text;
        el.style.cssText = 'background:#2b2250;color:#fff;border:1px solid #ffb020;border-radius:12px;padding:12px 16px;box-shadow:0 6px 20px rgba(0,0,0,.4);cursor:pointer';
        el.addEventListener('click', function () { if (href) location.href = href; else el.remove(); });
        toastBox.appendChild(el);
        setTimeout(function () { el.remove(); }, 8000);
      }

      function setTopupBadge(n) {
        var b = document.getElementById('topupBadge');
        b.textContent = n;
        b.style.display = n > 0 ? '' : 'none';
      }

      document.addEventListener('visibilitychange', function () { if (!document.hidden) document.title = baseTitle; });

      soundBtn.addEventListener('click', function () {
        var on = !soundOn();
        try { localStorage.setItem(SOUND_KEY, on ? '1' : '0'); } catch (e) {}
        renderSoundBtn();
        if (on) beep(); // คลิกครั้งนี้ถือเป็น user gesture ทำให้เบราว์เซอร์อนุญาตเสียง และให้ลองฟังเลย
      });

      testBtn.addEventListener('click', function () {
        beep();
        var fd = new FormData();
        fd.append('action', 'test');
        fd.append('csrf_token', csrf);
        fetch('notify_api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var m = { sent: 'ส่งข้อความทดสอบไป Discord แล้ว ✅', failed: 'ส่ง Discord ไม่สำเร็จ ❌ (ตรวจ URL webhook / error log)', disabled: 'ยังไม่ได้ตั้งค่า DISCORD_WEBHOOK_URL ใน config.php' };
            toast(d.ok ? (m[d.discord] || 'เรียบร้อย') : (d.error || 'เกิดข้อผิดพลาด'));
          })
          .catch(function () { toast('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'); });
      });

      function poll() {
        fetch('notify_api.php?action=poll&since=' + lastId, { cache: 'no-store', credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) return;
            setTopupBadge(d.pending);
            if (d.latest_id < lastId) { lastId = d.latest_id; return; } // ตารางถูกล้าง/รีเซ็ต
            if (d.items.length) {
              d.items.forEach(function (it) {
                var amt = it.amount.toLocaleString('th-TH', { minimumFractionDigits: 2 });
                toast((it.auto ? '✅ เติมอัตโนมัติ: ' : '💰 แจ้งเติมเงิน: ') + it.username + ' ' + amt + ' บาท', 'topups.php');
              });
              lastId = d.items[d.items.length - 1].id;
              if (d.latest_id > lastId) lastId = d.latest_id;
              if (soundOn()) beep();
              if (document.hidden) document.title = '💰 มีรายการเติมเงินใหม่ - ' + baseTitle;
            }
          })
          .catch(function () {})
          .then(function () { setTimeout(poll, 10000); });
      }

      renderSoundBtn();
      setTimeout(poll, 10000);
    })();
    </script>
    <script>
    (function () {
      var badge = document.getElementById('adminChatBadge');
      window.updateAdminChatBadge = function (n) {
        badge.textContent = n;
        badge.style.display = n > 0 ? '' : 'none';
      };
      <?php if ($adminPage !== 'chat.php'): ?>
      function tick() {
        if (!document.hidden) {
          fetch('chat_api.php?action=unread', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.ok) window.updateAdminChatBadge(d.unread); })
            .catch(function () {});
        }
        setTimeout(tick, 15000);
      }
      setTimeout(tick, 15000);
      <?php endif; ?>
    })();
    </script>
  </div>

  <div class="flex-grow-1 p-4" style="min-width:0">
    <?php if ($flash): ?>
      <div class="alert alert-<?php echo $flash['type']==='error'?'danger':$flash['type']; ?> alert-dismissible fade show" style="border-radius:12px;border:none">
        <?php echo h($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>
