<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/chat.php';
requireAdmin();

$initialThread = (int)($_GET['thread'] ?? 0);

$pageTitle = 'แชทผู้ใช้';
include 'inc_header.php';
?>

<h3 class="mb-3"><i class="fa-solid fa-comments"></i> แชทผู้ใช้</h3>

<div class="chat-admin">
  <!-- ซ้าย: รายการห้องแชท -->
  <div class="chat-list">
    <div class="p-2 d-flex gap-2">
      <input type="text" id="threadSearch" class="form-control form-control-sm" placeholder="ค้นหาชื่อผู้ใช้...">
      <select id="threadFilter" class="form-select form-select-sm" style="width:auto">
        <option value="all">ทั้งหมด</option>
        <option value="unread">ยังไม่อ่าน</option>
        <option value="open">เปิดอยู่</option>
        <option value="closed">ปิดแล้ว</option>
      </select>
    </div>
    <div id="threadList" class="chat-list-items">
      <div class="text-center text-muted py-4">กำลังโหลด...</div>
    </div>
  </div>

  <!-- ขวา: บทสนทนา -->
  <div class="chat-box chat-panel">
    <div id="panelEmpty" class="chat-empty" style="margin:auto">
      <div style="font-size:2.4rem;color:var(--primary-light)"><i class="fa-solid fa-comments"></i></div>
      <p>เลือกห้องแชททางซ้ายเพื่อเริ่มตอบ</p>
    </div>

    <div id="panelMain" style="display:none;flex-direction:column;height:100%;min-height:0">
      <div class="chat-head">
        <div class="chat-avatar"><i class="fa-solid fa-user"></i></div>
        <div class="flex-grow-1" style="min-width:0">
          <div class="fw-bold" id="pName">-</div>
          <small class="text-muted" id="pInfo">-</small>
        </div>
        <button type="button" class="btn btn-sm btn-outline-custom" id="pToggle"></button>
      </div>
      <div class="chat-messages" id="chatMessages"></div>
      <form id="chatForm" class="chat-input" autocomplete="off">
        <div class="chat-error" id="chatError" style="display:none"></div>
        <div class="d-flex gap-2 align-items-end">
          <textarea id="chatText" rows="1" maxlength="<?php echo CHAT_MAX_LEN; ?>" placeholder="พิมพ์ข้อความตอบกลับ… (Enter ส่ง, Shift+Enter ขึ้นบรรทัดใหม่)"></textarea>
          <button type="submit" class="btn btn-primary-custom" id="chatSend"><i class="fa-solid fa-paper-plane"></i></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var API = 'chat_api.php';
  var CSRF = <?php echo json_encode(csrfToken()); ?>;
  var current = <?php echo $initialThread; ?>;   // thread id ที่เปิดอยู่
  var lastId = 0, seen = {}, currentStatus = 'open';

  var listEl = document.getElementById('threadList');
  var searchEl = document.getElementById('threadSearch');
  var filterEl = document.getElementById('threadFilter');
  var panelEmpty = document.getElementById('panelEmpty');
  var panelMain = document.getElementById('panelMain');
  var box = document.getElementById('chatMessages');
  var form = document.getElementById('chatForm');
  var ta = document.getElementById('chatText');
  var sendBtn = document.getElementById('chatSend');
  var errBox = document.getElementById('chatError');
  var toggleBtn = document.getElementById('pToggle');

  function nearBottom() { return box.scrollHeight - box.scrollTop - box.clientHeight < 90; }
  function toBottom() { box.scrollTop = box.scrollHeight; }
  function showError(msg) { errBox.textContent = msg; errBox.style.display = msg ? 'block' : 'none'; }

  function setBadge(n) {
    if (window.updateAdminChatBadge) window.updateAdminChatBadge(n);
  }

  /* ---------- รายการห้อง ---------- */
  function loadThreads() {
    var url = API + '?action=threads&filter=' + encodeURIComponent(filterEl.value) + '&q=' + encodeURIComponent(searchEl.value.trim());
    return fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        setBadge(d.total_unread);
        listEl.textContent = '';
        if (!d.threads.length) {
          var e = document.createElement('div');
          e.className = 'text-center text-muted py-4';
          e.textContent = 'ไม่มีห้องแชท';
          listEl.appendChild(e);
          return;
        }
        d.threads.forEach(function (t) {
          var item = document.createElement('div');
          item.className = 'thread-item' + (t.id === current ? ' active' : '');
          item.setAttribute('role', 'button');
          item.tabIndex = 0;

          var top = document.createElement('div');
          top.className = 'd-flex justify-content-between align-items-center';
          var name = document.createElement('span');
          name.className = 'fw-bold text-truncate';
          name.textContent = t.username;
          var time = document.createElement('small');
          time.className = 'text-muted ms-2';
          time.textContent = t.time;
          top.appendChild(name); top.appendChild(time);

          var bottom = document.createElement('div');
          bottom.className = 'd-flex justify-content-between align-items-center';
          var prev = document.createElement('small');
          prev.className = 'text-muted text-truncate';
          prev.textContent = t.preview;
          bottom.appendChild(prev);
          if (t.unread > 0) {
            var b = document.createElement('span');
            b.className = 'badge bg-danger ms-2';
            b.textContent = t.unread;
            bottom.appendChild(b);
          } else if (t.status === 'closed') {
            var c = document.createElement('span');
            c.className = 'status-badge status-sold ms-2';
            c.textContent = 'ปิดแล้ว';
            bottom.appendChild(c);
          }
          item.appendChild(top); item.appendChild(bottom);
          item.addEventListener('click', function () { openThread(t.id); });
          item.addEventListener('keydown', function (e) { if (e.key === 'Enter') openThread(t.id); });
          listEl.appendChild(item);
        });
      })
      .catch(function () {});
  }

  /* ---------- บทสนทนา ---------- */
  function render(m) {
    if (seen[m.id]) return;
    seen[m.id] = true;
    var row = document.createElement('div');
    row.className = 'msg-row ' + (m.role === 'admin' ? 'mine' : 'theirs');
    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    bubble.textContent = m.text;
    var meta = document.createElement('div');
    meta.className = 'msg-meta';
    meta.textContent = m.time;
    row.appendChild(bubble); row.appendChild(meta);
    box.appendChild(row);
    if (m.id > lastId) lastId = m.id;
  }

  function applyThreadInfo(t) {
    document.getElementById('pName').textContent = t.username + (t.user_status === 'banned' ? ' (ถูกระงับ)' : '');
    document.getElementById('pInfo').textContent = t.email + ' · Wallet ' + t.wallet + ' บาท · สมาชิกตั้งแต่ ' + t.since;
    currentStatus = t.status;
    toggleBtn.textContent = t.status === 'closed' ? 'เปิดเรื่องอีกครั้ง' : 'ปิดเรื่องนี้';
  }

  function openThread(id) {
    current = id; lastId = 0; seen = {};
    box.textContent = '';
    showError('');
    panelEmpty.style.display = 'none';
    panelMain.style.display = 'flex';
    if (history.replaceState) history.replaceState(null, '', 'chat.php?thread=' + id);
    pollMessages(true);
    loadThreads();
    ta.focus();
  }

  var msgTimer = null;
  function pollMessages(first) {
    if (!current) return;
    clearTimeout(msgTimer);
    var id = current;
    var mark = document.hidden ? 0 : 1;
    fetch(API + '?action=fetch&thread_id=' + id + '&after=' + lastId + '&mark=' + mark, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (id !== current) return;          // ผู้ใช้สลับห้องระหว่างรอ
        if (!d.ok) { showError(d.error || ''); return; }
        var stick = first || nearBottom();
        d.messages.forEach(render);
        applyThreadInfo(d.thread);
        if (stick) toBottom();
      })
      .catch(function () {})
      .then(function () {
        if (id === current) msgTimer = setTimeout(function () { pollMessages(false); }, document.hidden ? 15000 : 3000);
      });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = ta.value.trim();
    if (!text || !current || sendBtn.disabled) return;
    showError('');
    sendBtn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'send');
    fd.append('thread_id', current);
    fd.append('message', text);
    fd.append('csrf_token', CSRF);
    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          render(d.message);
          ta.value = ''; autosize(); toBottom();
          if (currentStatus === 'closed') { currentStatus = 'open'; toggleBtn.textContent = 'ปิดเรื่องนี้'; }
          loadThreads();
        } else { showError(d.error || 'ส่งข้อความไม่สำเร็จ'); }
      })
      .catch(function () { showError('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'); })
      .then(function () { sendBtn.disabled = false; ta.focus(); });
  });

  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
      e.preventDefault();
      form.requestSubmit();
    }
  });
  function autosize() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 140) + 'px'; }
  ta.addEventListener('input', autosize);

  toggleBtn.addEventListener('click', function () {
    if (!current) return;
    var next = currentStatus === 'closed' ? 'open' : 'closed';
    var fd = new FormData();
    fd.append('action', 'set_status');
    fd.append('thread_id', current);
    fd.append('status', next);
    fd.append('csrf_token', CSRF);
    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          currentStatus = d.status;
          toggleBtn.textContent = d.status === 'closed' ? 'เปิดเรื่องอีกครั้ง' : 'ปิดเรื่องนี้';
          loadThreads();
        } else { showError(d.error || 'เปลี่ยนสถานะไม่สำเร็จ'); }
      });
  });

  var searchTimer = null;
  searchEl.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(loadThreads, 300); });
  filterEl.addEventListener('change', loadThreads);

  (function listLoop() {
    loadThreads().then(function () { setTimeout(listLoop, document.hidden ? 20000 : 5000); });
  })();
  if (current) openThread(current);
})();
</script>

<?php include 'inc_footer.php'; ?>
