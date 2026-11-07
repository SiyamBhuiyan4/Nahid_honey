<?php
require __DIR__ . '/inc/admin.php';
require_perm('orders.view');

$raw = $_GET['ids'] ?? $_GET['id'] ?? '';
$ids = is_array($raw) ? $raw : explode(',', (string)$raw);
$ids = array_values(array_unique(array_filter(array_map(fn($i) => strtoupper(trim((string)$i)), $ids))));
$ids = array_slice($ids, 0, 200);
$orders = [];
foreach ($ids as $id) if ($o = store_get('orders', $id)) $orders[] = $o;
$slip = ($_GET['type'] ?? '') === 'slip';
$docName = $slip ? 'Packing slip' : 'Invoice';

if ($orders) log_activity('order.print', $docName . ' printed for ' . implode(', ', array_column($orders, 'id')), ['ids' => array_column($orders, 'id')]);

$contact = array_filter([
    $SITE['phone'] ?? '',
    $SITE['email'] ?? '',
]);
$switch = admin_url('order-print.php?' . http_build_query(['ids' => implode(',', array_column($orders, 'id')), 'type' => $slip ? 'invoice' : 'slip']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($docName) ?><?= count($orders) === 1 ? ' ' . e($orders[0]['id']) : 's (' . count($orders) . ')' ?> · <?= e($SITE['brand_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  :root { --brand: #F28C28; --teal: #0F2A28; --border: #E4DFD4; --muted: #6E7775; --text: #1A1F1E; --cream: #FBF7EF; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #EDEAE3; color: var(--text); font: 13px/1.5 'Inter', 'Hind Siliguri', system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; padding: 12px 20px; background: var(--teal); color: #fff; }
  .bar a, .bar button { display: inline-flex; align-items: center; gap: 8px; height: 36px; padding: 0 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,.2); background: transparent; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
  .bar .primary { background: var(--brand); border-color: var(--brand); }
  .sheet { width: 210mm; min-height: 297mm; margin: 20px auto; padding: 16mm 15mm; background: #fff; box-shadow: 0 6px 24px rgba(0,0,0,.1); position: relative; }
  .sheet + .sheet { page-break-before: always; break-before: page; }
  header.doc { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; padding-bottom: 18px; border-bottom: 2px solid var(--teal); }
  .brand { display: flex; gap: 12px; align-items: center; }
  .logo { width: 44px; height: 44px; border-radius: 12px; background: var(--teal); color: var(--brand); display: grid; place-items: center; font-size: 20px; }
  .brand strong { display: block; font-size: 20px; letter-spacing: -.02em; }
  .brand small, .brand .contact { display: block; color: var(--muted); font-size: 12px; }
  .doc-meta { text-align: right; }
  .doc-meta h1 { margin: 0 0 4px; font-size: 22px; letter-spacing: .04em; text-transform: uppercase; color: var(--teal); }
  .doc-meta div { font-size: 12.5px; color: var(--muted); }
  .doc-meta b { color: var(--text); font-weight: 600; }
  .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 22px 0; }
  .parties h3 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 600; }
  .parties p { margin: 0; white-space: pre-line; }
  .parties .big { font-size: 15px; font-weight: 600; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); font-weight: 600; padding: 9px 10px; background: var(--cream); border-bottom: 1px solid var(--border); }
  td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
  td small { display: block; color: var(--muted); font-size: 11.5px; }
  .r { text-align: right; } .c { text-align: center; }
  .box { width: 18px; height: 18px; border: 1.5px solid #9AA29F; border-radius: 4px; display: inline-block; }
  .totals { margin-left: auto; width: 280px; margin-top: 14px; }
  .totals div { display: flex; justify-content: space-between; padding: 6px 10px; }
  .totals .grand { margin-top: 4px; background: var(--teal); color: #fff; border-radius: 8px; font-size: 15px; font-weight: 700; padding: 10px; }
  .due { display: inline-block; margin-top: 10px; padding: 6px 12px; border-radius: 999px; font-weight: 700; font-size: 12px; letter-spacing: .04em; text-transform: uppercase; }
  .due.paid { background: #E8F6EC; color: #15803D; } .due.unpaid { background: #FFF1E2; color: #B45309; } .due.refunded { background: #EFF0F1; color: #57606A; }
  .note { margin-top: 18px; padding: 10px 12px; border: 1px dashed var(--border); border-radius: 8px; background: var(--cream); }
  .note b { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 2px; }
  footer.doc { position: absolute; left: 15mm; right: 15mm; bottom: 12mm; display: flex; justify-content: space-between; gap: 12px; padding-top: 10px; border-top: 1px solid var(--border); color: var(--muted); font-size: 11.5px; }
  .cod { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
  .empty { max-width: 480px; margin: 80px auto; padding: 32px; text-align: center; background: #fff; border-radius: 14px; }
  @page { size: A4; margin: 0; }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .sheet { margin: 0; box-shadow: none; width: auto; min-height: 297mm; }
  }
  @media screen and (max-width: 840px) {
    .sheet { width: auto; min-height: 0; margin: 12px; padding: 20px; }
    footer.doc { position: static; margin-top: 24px; }
    .parties { grid-template-columns: 1fr; }
    header.doc { flex-direction: column; } .doc-meta { text-align: left; }
    .totals { width: 100%; }
  }
</style>
</head>
<body>
<div class="bar">
  <strong><?= e($docName) ?><?= count($orders) > 1 ? 's · ' . count($orders) . ' orders' : '' ?></strong>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($orders): ?><a href="<?= e($switch) ?>"><i class="fa-solid fa-repeat"></i> <?= $slip ? 'Invoice' : 'Packing slip' ?></a><?php endif; ?>
    <a href="<?= e(count($orders) === 1 ? admin_url('order.php?id=' . urlencode($orders[0]['id'])) : admin_url('orders.php')) ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <button class="primary" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<?php if (!$orders): ?>
  <div class="empty"><h2>No orders selected</h2><p>Select orders on the orders page and choose “Print”.</p><a href="<?= admin_url('orders.php') ?>">Back to orders</a></div>
<?php endif; ?>

<?php foreach ($orders as $o):
  $ps = $o['payment_status'] ?? 'unpaid';
  $due = $ps === 'paid' ? 0 : (float)($o['total'] ?? 0);
  $qty = array_sum(array_map(fn($i) => (int)($i['quantity'] ?? 0), $o['items'] ?? [])); ?>
<section class="sheet">
  <header class="doc">
    <div class="brand">
      <span class="logo"><i class="fa-solid fa-droplet"></i></span>
      <div>
        <strong><?= e($SITE['brand_name']) ?></strong>
        <?php if (!empty($SITE['tagline'])): ?><small><?= e($SITE['tagline']) ?></small><?php endif; ?>
        <?php if (!empty($SITE['address'])): ?><span class="contact"><?= e($SITE['address']) ?></span><?php endif; ?>
        <?php if ($contact): ?><span class="contact"><?= e(implode(' · ', $contact)) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="doc-meta">
      <h1><?= e($docName) ?></h1>
      <div>Order <b><?= e($o['id']) ?></b></div>
      <div>Date <b><?= e(admin_date($o['created_at'] ?? '', 'j M Y')) ?></b></div>
      <div>Status <b><?= e(order_status_label($o['status'] ?? '')) ?></b></div>
    </div>
  </header>

  <div class="parties">
    <div>
      <h3><?= $slip ? 'Ship to' : 'Bill & ship to' ?></h3>
      <p class="big"><?= e($o['name'] ?? '') ?></p>
      <p><?= e($o['phone'] ?? '') ?><?= !empty($o['email']) ? ' · ' . e($o['email']) : '' ?></p>
      <p><?= e($o['address'] ?? '') ?></p>
      <?php if (!empty($o['zone'])): ?><p style="color:var(--muted)"><?= e($o['zone']) ?></p><?php endif; ?>
    </div>
    <div>
      <h3>Delivery &amp; payment</h3>
      <p>Payment: <b><?= e($o['payment_method'] ?? '—') ?></b></p>
      <?php if (!empty($o['courier'])): ?><p>Courier: <b><?= e($o['courier']) ?></b></p><?php endif; ?>
      <?php if (!empty($o['tracking_number'])): ?><p>Tracking: <b><?= e($o['tracking_number']) ?></b></p><?php endif; ?>
      <?php if ($slip): ?>
        <p style="margin-top:8px">Collect on delivery</p><div class="cod"><?= money($due) ?></div>
      <?php else: ?>
        <span class="due <?= e($ps) ?>"><?= e(payment_statuses()[$ps][0] ?? 'Unpaid') ?></span>
      <?php endif; ?>
    </div>
  </div>

  <table>
    <thead><tr>
      <?php if ($slip): ?><th style="width:34px"></th><?php endif; ?>
      <th>Item</th>
      <?php if (!$slip): ?><th class="r">Price</th><?php endif; ?>
      <th class="c">Qty</th>
      <?php if (!$slip): ?><th class="r">Amount</th><?php endif; ?>
    </tr></thead>
    <tbody>
      <?php foreach ($o['items'] ?? [] as $it): ?>
        <tr>
          <?php if ($slip): ?><td><span class="box"></span></td><?php endif; ?>
          <td><strong><?= e($it['title'] ?? '') ?></strong><?php if (!empty($it['unit'])): ?><small><?= e($it['unit']) ?></small><?php endif; ?></td>
          <?php if (!$slip): ?><td class="r"><?= money($it['price'] ?? 0) ?></td><?php endif; ?>
          <td class="c"><?= (int)($it['quantity'] ?? 0) ?></td>
          <?php if (!$slip): ?><td class="r"><?= money($it['line_total'] ?? ((float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 0))) ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!$slip): ?>
  <div class="totals">
    <div><span>Subtotal</span><span><?= money($o['subtotal'] ?? 0) ?></span></div>
    <?php if (!empty($o['discount'])): ?><div><span>Discount<?= !empty($o['coupon_code']) ? ' (' . e($o['coupon_code']) . ')' : '' ?></span><span>−<?= money($o['discount']) ?></span></div><?php endif; ?>
    <div><span>Delivery</span><span><?= money($o['delivery_fee'] ?? 0) ?></span></div>
    <div class="grand"><span>Total</span><span><?= money($o['total'] ?? 0) ?></span></div>
    <?php if ($ps !== 'paid'): ?><div><span>Amount due</span><b><?= money($due) ?></b></div><?php endif; ?>
  </div>
  <?php else: ?>
    <p style="margin-top:12px;color:var(--muted)"><?= $qty ?> item<?= $qty === 1 ? '' : 's' ?> in <?= count($o['items'] ?? []) ?> line<?= count($o['items'] ?? []) === 1 ? '' : 's' ?></p>
  <?php endif; ?>

  <?php if (!empty($o['notes'])): ?><div class="note"><b>Customer note</b><?= nl2br(e($o['notes'])) ?></div><?php endif; ?>

  <footer class="doc">
    <span>Thank you for shopping with <?= e($SITE['brand_name']) ?>!</span>
    <span><?= e(implode(' · ', array_filter([$SITE['phone'] ?? '', isset($SITE['whatsapp']) && $SITE['whatsapp'] !== '' ? 'WhatsApp ' . $SITE['whatsapp'] : '']))) ?></span>
  </footer>
</section>
<?php endforeach; ?>
<?php if (!empty($_GET['autoprint']) && $orders): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script><?php endif; ?>
</body>
</html>
