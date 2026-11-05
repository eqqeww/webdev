<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
require_once 'includes/chat.php';

requireLogin();
if (isAdmin()) {
    header('Location: admin/chat.php');
    exit;
}

$pageTitle = 'แชทกับแอดมิน';
include 'includes/header.php';
?>

<div class="container my-4" style="max-width:820px">
  <div class="chat-box">
    <div class="chat-head">
      <div class="chat-avatar"><i class="fa-solid fa-headset"></i></div>
      <div class="flex-grow-1">
        <div class="fw-bold">แชทกับแอดมิน</div>
        <small class="text-muted" id="chatStatusText">ติดต่อสอบถามปัญหาเรื่องไอดี การเติมเงิน หรือการสั่งซื้อ</small>
      </div>
    </div>

    <div class="chat-messages" id="chatMessages" aria-live="polite">
      <div class="chat-empty" id="chatEmpty">
        <div style="font-size:2.4rem;color:var(--primary-light)"><i class="fa-solid fa-comments"></i></div>
        <p class="mb-2">เริ่มต้นสนทนากับแอดมินได้เลย</p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
          <button type="button" class="chat-chip" data-text="สอบถามเรื่องการเติมเงิน: ">เติมเงิน</button>
          <button type="button" class="chat-chip" data-text="แจ้งปัญหาไอดีที่ซื้อ (เลขคำสั่งซื้อ): ">ปัญหาไอดีที่ซื้อ</button>
          <button type="button" class="chat-chip" data-text="สอบถามก่อนซื้อสินค้า: ">สอบถามก่อนซื้อ</button>
        </div>
      </div>
    </div>

    <form id="chatForm" class="chat-input" autocomplete="off">
      <div class="chat-error" id="chatError" style="display:none"></div>
      <div class="d-flex gap-2 align-items-end">
        <textarea id="chatText" rows="1" maxlength="<?php echo CHAT_MAX_LEN; ?>" placeholder="พิมพ์ข้อความ… (Enter ส่ง, Shift+Enter ขึ้นบรรทัดใหม่)"></textarea>
        <button type="submit" class="btn btn-primary-custom" id="chatSend"><i class="fa-solid fa-paper-plane"></i></button>
      </div>
      <div class="d-flex justify-content-between mt-1">
        <small class="text-muted"><i class="fa-solid fa-shield-halved"></i> ห้ามส่งรหัสผ่านบัญชีของคุณ แอดมินจะไม่ขอรหัสผ่านจากคุณ</small>
        <small class="text-muted" id="chatCount">0/<?php echo CHAT_MAX_LEN; ?></small>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var API = 'chat_api.php';
  var CSRF = <?php echo json_encode(csrfToken()); ?>;
  var MAX = <?php echo (int)CHAT_MAX_LEN; ?>;
  var box = document.getElementById('chatMessages');
  var empty = document.getElementById('chatEmpty');
  var form = document.getElementById('chatForm');
  var ta = document.getElementById('chatText');
  var sendBtn = document.getElementById('chatSend');
  var errBox = document.getElementById('chatError');
  var counter = document.getElementById('chatCount');
  var statusText = document.getElementById('chatStatusText');
  var seen = {};
  var lastId = 0;

  function nearBottom() { return box.scrollHeight - box.scrollTop - box.clientHeight < 90; }
  function toBottom() { box.scrollTop = box.scrollHeight; }

  function render(m) {
    if (seen[m.id]) return false;
    seen[m.id] = true;
    var row = document.createElement('div');
    row.className = 'msg-row ' + (m.role === 'user' ? 'mine' : 'theirs');
    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    bubble.textContent = m.text;          // textContent = กัน XSS
    var meta = document.createElement('div');
    meta.className = 'msg-meta';
    meta.textContent = (m.role === 'user' ? '' : 'แอดมิน · ') + m.time;
    row.appendChild(bubble);
    row.appendChild(meta);
    box.appendChild(row);
    if (empty) { empty.style.display = 'none'; }
    if (m.id > lastId) lastId = m.id;
    return true;
  }

  function showError(msg) {
    errBox.textContent = msg;
    errBox.style.display = msg ? 'block' : 'none';
  }

  function poll(first) {
    var mark = document.hidden ? 0 : 1;
    fetch(API + '?action=fetch&after=' + lastId + '&mark=' + mark, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        var stick = first || nearBottom();
        d.messages.forEach(render);
        if (stick) toBottom();
        if (d.status === 'closed') statusText.textContent = 'แอดมินปิดเรื่องนี้แล้ว หากยังมีคำถามพิมพ์ข้อความใหม่ได้เลย';
      })
      .catch(function () {})
      .then(function () { setTimeout(function () { poll(false); }, document.hidden ? 15000 : 3000); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = ta.value.trim();
    if (!text || sendBtn.disabled) return;
    showError('');
    sendBtn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'send');
    fd.append('message', text);
    fd.append('csrf_token', CSRF);
    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          render(d.message);
          ta.value = '';
          autosize();
          toBottom();
        } else {
          showError(d.error || 'ส่งข้อความไม่สำเร็จ');
        }
      })
      .catch(function () { showError('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่'); })
      .then(function () { sendBtn.disabled = false; ta.focus(); });
  });

  // Enter = ส่ง, Shift+Enter = ขึ้นบรรทัดใหม่ (ไม่ส่งตอนกำลังพิมพ์ภาษาไทย/IME)
  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  function autosize() {
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, 140) + 'px';
    counter.textContent = ta.value.length + '/' + MAX;
  }
  ta.addEventListener('input', autosize);

  Array.prototype.forEach.call(document.querySelectorAll('.chat-chip'), function (b) {
    b.addEventListener('click', function () {
      ta.value = b.getAttribute('data-text');
      autosize();
      ta.focus();
    });
  });

  poll(true);
})();
</script>

<?php include 'includes/footer.php'; ?>
