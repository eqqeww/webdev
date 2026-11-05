<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT p.*, s.name AS shop_name, m.name AS map_name
                         FROM products p
                         JOIN shops s ON p.shop_id = s.id
                         JOIN maps m ON p.map_id = m.id
                         WHERE p.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    setFlash('error', 'ไม่พบสินค้าที่คุณต้องการ');
    header('Location: index.php');
    exit;
}

// เช็คว่าผู้ใช้ปัจจุบันเคยซื้อสินค้านี้ไปแล้วหรือไม่ (เพื่อแสดงข้อมูลบัญชี)
$alreadyBought = false;
if (isLoggedIn()) {
    $stmt2 = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND product_id = ? AND status='completed'");
    $stmt2->bind_param('ii', $_SESSION['user_id'], $id);
    $stmt2->execute();
    $alreadyBought = $stmt2->get_result()->num_rows > 0;
}

// สินค้าที่เกี่ยวข้อง (แมพเดียวกัน)
$related = $conn->prepare("SELECT * FROM products WHERE map_id = ? AND id != ? AND status='available' LIMIT 4");
$related->bind_param('ii', $product['map_id'], $id);
$related->execute();
$relatedProducts = $related->get_result();

$pageTitle = $product['title'];
include 'includes/header.php';
?>

<div class="container my-5">
  <div class="row g-4">
    <div class="col-md-5">
      <div class="card-custom">
        <div class="card-img-wrap" style="height:320px">
          <?php if ($product['image']): ?>
            <img src="uploads/products/<?php echo h($product['image']); ?>" alt="">
          <?php else: ?>
            <i class="fa-solid fa-cube" style="font-size:5rem"></i>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-7">
      <span class="badge-shop"><?php echo h($product['shop_name']); ?></span>
      <span class="badge-shop"><?php echo h($product['map_name']); ?></span>
      <h2 class="fw-bold mt-2"><?php echo h($product['title']); ?></h2>
      <div class="price-tag mb-3" style="font-size:1.8rem"><?php echo formatMoney($product['price']); ?></div>
      <p class="text-muted"><?php echo nl2br(h($product['description'])); ?></p>

      <?php if ($product['status'] === 'sold' && !$alreadyBought): ?>
        <div class="alert alert-secondary" style="border-radius:10px">สินค้านี้ถูกขายไปแล้ว</div>
      <?php elseif ($alreadyBought): ?>
        <div class="alert alert-success" style="border-radius:10px;border:none">
          <strong><i class="fa-solid fa-circle-check"></i> คุณซื้อสินค้านี้แล้ว</strong><br>
          ข้อมูลบัญชี: <?php echo nl2br(h($product['account_detail'])); ?>
        </div>
      <?php else: ?>
        <form method="POST" action="buy.php" id="purchaseForm">
          <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
          <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
          <?php if (isLoggedIn()): ?>
            <button type="submit" class="btn btn-accent-custom btn-lg"><i class="fa-solid fa-cart-shopping"></i> ซื้อสินค้านี้เลย</button>
          <?php else: ?>
            <a href="login.php" class="btn btn-primary-custom btn-lg">เข้าสู่ระบบเพื่อซื้อสินค้า</a>
          <?php endif; ?>
        </form>
        <small class="text-muted d-block mt-2"><i class="fa-solid fa-shield-halved"></i> ระบบจะหักเงินจาก Wallet ของคุณโดยอัตโนมัติ</small>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Custom purchase confirmation modal -->
<div class="purchase-modal-backdrop" id="purchaseModal" aria-hidden="true">
  <section class="purchase-modal" role="dialog" aria-modal="true" aria-labelledby="purchaseModalTitle" aria-describedby="purchaseModalIntro">
    <div class="purchase-modal-header">
      <span class="purchase-modal-icon"><i class="fa-solid fa-cart-shopping"></i></span>
      <button type="button" class="purchase-modal-close" id="closePurchaseModal" aria-label="ปิดหน้าต่าง">&times;</button>
    </div>
    <h3 id="purchaseModalTitle">ยืนยันคำสั่งซื้อ</h3>
    <p id="purchaseModalIntro" class="purchase-modal-muted">โปรดตรวจสอบรายละเอียดสินค้าและยอดเงินก่อนยืนยัน</p>
    <div class="purchase-modal-details">
      <div class="purchase-detail-row"><span>สินค้า</span><strong><?php echo h($product['title']); ?></strong></div>
      <div class="purchase-detail-row"><span>ร้านค้า</span><strong><?php echo h($product['shop_name']); ?></strong></div>
      <div class="purchase-detail-row"><span>เกม / แมพ</span><strong><?php echo h($product['map_name']); ?></strong></div>
      <div class="purchase-detail-row purchase-total"><span>ยอดชำระ</span><strong><?php echo formatMoney($product['price']); ?></strong></div>
      <div class="purchase-description"><span>รายละเอียดสินค้า</span><p><?php echo nl2br(h($product['description'] ?: 'ไม่มีรายละเอียดเพิ่มเติม')); ?></p></div>
    </div>
    <label class="purchase-consent" for="purchaseConfirmCheck">
      <input type="checkbox" id="purchaseConfirmCheck">
      <span>ฉันอ่านรายละเอียดแล้ว และยืนยันให้หักเงินจาก Wallet ตามยอดข้างต้นเพื่อสั่งซื้อสินค้านี้</span>
    </label>
    <p class="purchase-modal-warning"><i class="fa-solid fa-circle-info"></i> เมื่อยืนยันแล้ว ระบบจะหักเงินและดำเนินการสั่งซื้อทันที</p>
    <div class="purchase-modal-actions">
      <button type="button" class="purchase-btn-cancel" id="cancelPurchase">ยกเลิก</button>
      <button type="button" class="purchase-btn-confirm" id="confirmPurchase" disabled>ตกลงสั่งซื้อ</button>
    </div>
  </section>
</div>

<style>
.purchase-modal-backdrop{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(5,4,15,.78);backdrop-filter:blur(7px)}
.purchase-modal-backdrop.is-open{display:flex}
.purchase-modal{width:min(100%,520px);max-height:calc(100vh - 36px);overflow-y:auto;background:#171329;color:#f8f7ff;border:1px solid #6d4acb;border-radius:20px;padding:24px;box-shadow:0 24px 80px rgba(0,0,0,.55);animation:purchaseModalIn .18s ease-out}
@keyframes purchaseModalIn{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
.purchase-modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.purchase-modal-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:14px;background:#35245f;color:#c4b5fd;font-size:20px}.purchase-modal-close{border:0;background:transparent;color:#d7d1eb;font-size:30px;line-height:1;cursor:pointer}
.purchase-modal h3{font-size:1.45rem;font-weight:800;margin:0 0 6px;color:#fff}.purchase-modal-muted{color:#c7c1da;margin-bottom:18px;font-size:.94rem}
.purchase-modal-details{background:#211b38;border:1px solid #393052;border-radius:14px;padding:14px 16px}.purchase-detail-row{display:flex;justify-content:space-between;gap:16px;padding:8px 0;border-bottom:1px solid #39314e}.purchase-detail-row span,.purchase-description>span{color:#c7c1da;flex-shrink:0}.purchase-detail-row strong{color:#fff;text-align:right;overflow-wrap:anywhere}.purchase-total{border-bottom:0;padding-top:13px}.purchase-total strong{font-size:1.25rem;color:#c4b5fd}.purchase-description{border-top:1px solid #39314e;padding-top:12px;margin-top:3px}.purchase-description p{color:#f0edfa;margin:7px 0 0;white-space:normal;overflow-wrap:anywhere;max-height:120px;overflow:auto}
.purchase-consent{display:flex;align-items:flex-start;gap:10px;margin:17px 0 12px;padding:13px;border:1px solid #4a3c6c;border-radius:12px;background:#201a35;color:#f5f2ff;cursor:pointer;line-height:1.55}.purchase-consent input{width:18px;height:18px;flex-shrink:0;margin-top:3px;accent-color:#8b5cf6}.purchase-modal-warning{font-size:.83rem;color:#d2c9e9;margin:0 0 18px}.purchase-modal-warning i{color:#c4b5fd;margin-right:5px}.purchase-modal-actions{display:flex;justify-content:flex-end;gap:10px}.purchase-btn-cancel,.purchase-btn-confirm{border-radius:11px;padding:11px 17px;font-weight:700;cursor:pointer;border:1px solid transparent}.purchase-btn-cancel{background:#302a43;color:#f4f0ff;border-color:#49415e}.purchase-btn-confirm{background:#7c3aed;color:#fff}.purchase-btn-confirm:disabled{background:#4b4260;color:#aaa2bc;cursor:not-allowed;opacity:.8}.purchase-btn-confirm:not(:disabled):hover{background:#6d28d9}
@media(max-width:480px){.purchase-modal{padding:18px;border-radius:16px}.purchase-detail-row{font-size:.9rem}.purchase-modal-actions>*{flex:1}.purchase-modal h3{font-size:1.3rem}}
</style>
<script>
(function(){
  const form=document.getElementById('purchaseForm');
  const modal=document.getElementById('purchaseModal');
  const checkbox=document.getElementById('purchaseConfirmCheck');
  const confirmBtn=document.getElementById('confirmPurchase');
  if(!form||!modal||!checkbox||!confirmBtn)return;
  let approved=false;
  function openModal(){modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';checkbox.checked=false;confirmBtn.disabled=true;document.getElementById('cancelPurchase').focus();}
  function closeModal(){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';form.querySelector('button[type="submit"]').focus();}
  form.addEventListener('submit',function(e){if(!approved){e.preventDefault();openModal();}});
  checkbox.addEventListener('change',function(){confirmBtn.disabled=!checkbox.checked;});
  confirmBtn.addEventListener('click',function(){if(!checkbox.checked)return;approved=true;confirmBtn.disabled=true;form.submit();});
  document.getElementById('cancelPurchase').addEventListener('click',closeModal);
  document.getElementById('closePurchaseModal').addEventListener('click',closeModal);
  modal.addEventListener('click',function(e){if(e.target===modal)closeModal();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal.classList.contains('is-open'))closeModal();});
})();
</script>

<?php if ($relatedProducts->num_rows > 0): ?>
  <h4 class="section-title mt-5"><i class="fa-solid fa-layer-group"></i> ไอดีอื่นในแมพเดียวกัน</h4>
  <div class="row g-4">
    <?php while ($rp = $relatedProducts->fetch_assoc()): ?>
    <div class="col-6 col-md-3">
      <div class="card-custom">
        <div class="card-img-wrap">
          <?php if ($rp['image']): ?><img src="uploads/products/<?php echo h($rp['image']); ?>" alt=""><?php else: ?><i class="fa-solid fa-cube"></i><?php endif; ?>
        </div>
        <div class="card-body">
          <div class="card-title"><?php echo h($rp['title']); ?></div>
          <div class="price-tag mb-2"><?php echo formatMoney($rp['price']); ?></div>
          <a href="product.php?id=<?php echo $rp['id']; ?>" class="btn btn-outline-custom w-100 btn-sm">ดูรายละเอียด</a>
        </div>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
  <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
