<?php
/**
 * Nahid Honey - Order Tracking Page
 * Allows customer to track order by Order ID (NH-XXXXXX) or Phone number.
 * Displays timeline (Pending -> Processing -> Out for Delivery -> Delivered),
 * customer information, and ordered items breakdown.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/../database/TelegramDB.php';
$telegramDB = new TelegramDB();

$queryParam = trim($_GET['id'] ?? ($_GET['phone'] ?? ($_GET['query'] ?? '')));
$searched = !empty($queryParam);
$foundOrder = null;

if ($searched) {
    // Clean search input
    $cleanQuery = preg_replace('/[^\d+A-Za-z\-]/', '', $queryParam);
    if (preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $cleanQuery)) {
        if (str_starts_with($cleanQuery, '+88')) {
            $cleanQuery = substr($cleanQuery, 3);
        } elseif (str_starts_with($cleanQuery, '88')) {
            $cleanQuery = substr($cleanQuery, 2);
        }
    }

    $foundOrder = $telegramDB->findOrder($cleanQuery);
    if (!$foundOrder && $cleanQuery !== $queryParam) {
        $foundOrder = $telegramDB->findOrder($queryParam);
    }
}

// Calculate status progress
$currentStatus = $foundOrder['status'] ?? 'Pending';
$stepStatuses = [
    'pending' => false,
    'processing' => false,
    'out_for_delivery' => false,
    'delivered' => false,
];
$progressPercent = 15;

switch (strtolower($currentStatus)) {
    case 'delivered':
        $stepStatuses['pending'] = true;
        $stepStatuses['processing'] = true;
        $stepStatuses['out_for_delivery'] = true;
        $stepStatuses['delivered'] = true;
        $progressPercent = 100;
        break;

    case 'out for delivery':
    case 'out_for_delivery':
        $stepStatuses['pending'] = true;
        $stepStatuses['processing'] = true;
        $stepStatuses['out_for_delivery'] = true;
        $progressPercent = 75;
        break;

    case 'processing':
        $stepStatuses['pending'] = true;
        $stepStatuses['processing'] = true;
        $progressPercent = 45;
        break;

    case 'pending':
    default:
        $stepStatuses['pending'] = true;
        $progressPercent = 15;
        break;
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $foundOrder ? "অর্ডার ট্র্যাকিং [{$foundOrder['order_id']}]" : "অর্ডার ট্র্যাক করুন"; ?> | নাহিদ হানি (Nahid Honey)</title>

  <!-- Favicon -->
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🍯</text></svg>">

  <!-- CSS Assets -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/toastr.min.css">
  <link rel="stylesheet" href="assets/css/main.css">

  <style>
    .track-hero {
      text-align: center;
      margin-bottom: 30px;
    }
    .track-hero h2 {
      font-size: 2rem;
      font-weight: 800;
      color: #0f172a;
      margin-bottom: 8px;
    }
    .track-hero p {
      color: #64748b;
      font-size: 1rem;
      max-width: 600px;
      margin: 0 auto;
    }
    .order-status-header {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      padding: 22px 28px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px;
      box-shadow: var(--shadow-sm);
    }
    .status-id-title {
      font-size: 1.35rem;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 0.88rem;
      font-weight: 700;
    }
    .status-pending {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .status-processing {
      background: #fffbeb;
      color: #b45309;
      border: 1px solid #fde68a;
    }
    .status-out_for_delivery {
      background: #f5f3ff;
      color: #6d28d9;
      border: 1px solid #ddd6fe;
    }
    .status-delivered {
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
    }
    .timeline-card {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      padding: 28px;
      margin-bottom: 24px;
      box-shadow: var(--shadow-sm);
    }
    .timeline-card h4 {
      font-size: 1.15rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 15px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    .items-table th {
      background: #f8fafc;
      padding: 12px 16px;
      text-align: left;
      font-size: 0.85rem;
      font-weight: 700;
      color: #475569;
      border-bottom: 1px solid var(--border-color);
    }
    .items-table td {
      padding: 14px 16px;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.92rem;
      color: #1e293b;
    }
    .items-table tr:last-child td {
      border-bottom: none;
    }
    .items-table td.text-right, .items-table th.text-right {
      text-align: right;
    }
    .not-found-box {
      background: #ffffff;
      border: 1px solid #fecaca;
      border-radius: var(--radius-lg);
      padding: 40px 20px;
      text-align: center;
      max-width: 600px;
      margin: 0 auto;
      box-shadow: var(--shadow-sm);
    }
    .not-found-icon {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: #fef2f2;
      color: #ef4444;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      margin-bottom: 14px;
    }
    .instructions-card {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      padding: 30px;
      max-width: 680px;
      margin: 0 auto;
      box-shadow: var(--shadow-sm);
    }
    .instruction-steps {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 20px;
      margin-top: 20px;
    }
    .instruction-item {
      text-align: center;
      padding: 15px;
      background: #fafaf9;
      border-radius: var(--radius-md);
      border: 1px solid #f1f5f9;
    }
    .instruction-item .icon {
      font-size: 26px;
      color: var(--primary);
      margin-bottom: 8px;
    }
    .instruction-item h5 {
      font-size: 0.92rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 4px;
    }
    .instruction-item p {
      font-size: 0.8rem;
      color: #64748b;
    }
    .support-callout {
      background: #ecfdf5;
      border: 1px solid #a7f3d0;
      border-radius: var(--radius-lg);
      padding: 20px 24px;
      margin-top: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 15px;
    }
    .support-callout-text h4 {
      font-size: 1.05rem;
      color: #065f46;
      font-weight: 700;
      margin-bottom: 4px;
    }
    .support-callout-text p {
      font-size: 0.88rem;
      color: #047857;
    }
    .support-buttons {
      display: flex;
      gap: 10px;
    }
    .btn-support-call {
      background: #059669;
      color: #ffffff;
      padding: 8px 16px;
      border-radius: var(--radius-full);
      font-weight: 700;
      font-size: 0.88rem;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-support-call:hover {
      background: #047857;
    }
    .btn-support-wa {
      background: #25d366;
      color: #ffffff;
      padding: 8px 16px;
      border-radius: var(--radius-full);
      font-weight: 700;
      font-size: 0.88rem;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-support-wa:hover {
      background: #1eb757;
    }
  </style>
</head>
<body>

  <!-- Top Announcement Bar -->
  <div class="top-notice-bar">
    <span>🌿 ১০০% আসল সুন্দরবনের মধু ও প্রাকৃতিক খাবারের বিশ্বস্ত প্রতিষ্ঠান | লাইভ অর্ডার ট্র্যাকিং</span>
  </div>

  <!-- Store Header -->
  <header class="store-header">
    <div class="header-container">
      <a href="index.php" class="brand-logo-link">
        <div class="brand-icon">🍯</div>
        <div class="brand-titles">
          <h1>নাহিদ হানি</h1>
          <p>Pure Honey & Natural Food</p>
        </div>
      </a>

      <div class="header-actions">
        <a href="checkout.php" class="btn-track-header" style="background: var(--primary-bg); color: var(--primary-dark);">
          <i class="fa-solid fa-cart-shopping"></i>
          <span>চেকআউট</span>
        </a>
        <a href="tel:01700000000" class="btn-hotline">
          <i class="fa-solid fa-phone-volume"></i>
          <span>০১৭০০-০০০০০০</span>
        </a>
      </div>
    </div>
  </header>

  <!-- Main Tracking Content -->
  <main class="main-wrapper">
    <!-- Breadcrumb -->
    <div class="page-breadcrumb">
      <a href="index.php"><i class="fa-solid fa-house"></i> হোম</a>
      <span><i class="fa-solid fa-chevron-right" style="font-size: 0.75rem;"></i></span>
      <span style="color: #0f172a; font-weight: 600;">অর্ডার ট্র্যাকিং</span>
    </div>

    <!-- Hero Title -->
    <div class="track-hero">
      <h2>আপনার অর্ডারের বর্তমান অবস্থা জানুন</h2>
      <p>অর্ডার কনফার্মেশনের পর প্রাপ্ত অর্ডার আইডি (যেমন NH-XXXXXX) অথবা আপনার মোবাইল নম্বর দিয়ে সার্চ করুন।</p>
    </div>

    <!-- Search Box -->
    <div class="track-search-box">
      <form action="track.php" method="GET" id="trackSearchForm">
        <div class="track-input-group">
          <div class="input-with-icon" style="flex-grow: 1;">
            <i class="fa-solid fa-magnifying-glass input-icon"></i>
            <input 
              type="text" 
              name="id" 
              id="trackInput" 
              class="form-control" 
              value="<?php echo htmlspecialchars($queryParam); ?>" 
              placeholder="অর্ডার আইডি (যেমন NH-XXXXXX) অথবা মোবাইল নম্বর লিখুন..." 
              required
            >
          </div>
          <button type="submit" class="btn-track-submit" id="btnTrackSubmit">
            <i class="fa-solid fa-truck-fast"></i>
            <span>অর্ডার খুঁজুন</span>
          </button>
        </div>
      </form>
    </div>

    <!-- Search Results / Tracking View -->
    <div id="trackResultContainer">

      <?php if ($searched && $foundOrder): ?>
        <!-- Order Found Successfully -->
        
        <!-- Order Summary Header Card -->
        <div class="order-status-header">
          <div>
            <div class="status-id-title">
              <i class="fa-solid fa-receipt" style="color: var(--primary);"></i>
              <span>অর্ডার আইডি: <?php echo htmlspecialchars($foundOrder['order_id']); ?></span>
            </div>
            <div style="font-size: 0.88rem; color: #64748b; margin-top: 4px;">
              অর্ডারের তারিখ: <?php echo htmlspecialchars($foundOrder['created_at'] ?? 'আজ'); ?>
            </div>
          </div>

          <!-- Status Badge -->
          <div>
            <?php 
              $statusSlug = strtolower(str_replace(' ', '_', $currentStatus));
              $badgeClass = match ($statusSlug) {
                  'delivered' => 'status-delivered',
                  'out_for_delivery' => 'status-out_for_delivery',
                  'processing' => 'status-processing',
                  default => 'status-pending',
              };
              $badgeBangla = match ($statusSlug) {
                  'delivered' => 'সফলভাবে ডেলিভারি সম্পন্ন',
                  'out_for_delivery' => 'ডেলিভারির জন্য বের হয়েছে',
                  'processing' => 'প্রক্রিয়াকরণ ও প্যাকেজিং চলছে',
                  default => 'অর্ডার গৃহীত হয়েছে',
              };
            ?>
            <span class="badge-status <?php echo $badgeClass; ?>">
              <i class="fa-solid fa-circle-dot"></i>
              <span><?php echo $badgeBangla; ?> (<?php echo htmlspecialchars($currentStatus); ?>)</span>
            </span>
          </div>
        </div>

        <!-- Timeline Stepper Card -->
        <div class="timeline-card">
          <h4><i class="fa-solid fa-route" style="color: var(--primary);"></i> ডেলিভারি লাইভ অগ্রগতি</h4>
          
          <div class="timeline-stepper">
            <div class="timeline-progress-bar" style="width: <?php echo $progressPercent; ?>%;"></div>

            <!-- Step 1: Pending / Placed -->
            <div class="timeline-step <?php echo $stepStatuses['pending'] ? ($currentStatus === 'Pending' ? 'active' : 'completed') : ''; ?>">
              <div class="step-node">
                <i class="fa-solid fa-file-invoice"></i>
              </div>
              <div class="step-title">অর্ডার প্লেসড</div>
              <div class="step-desc">অর্ডার গৃহীত হয়েছে</div>
            </div>

            <!-- Step 2: Processing -->
            <div class="timeline-step <?php echo $stepStatuses['processing'] ? ($currentStatus === 'Processing' ? 'active' : 'completed') : ''; ?>">
              <div class="step-node">
                <i class="fa-solid fa-box-archive"></i>
              </div>
              <div class="step-title">প্রসেসিং</div>
              <div class="step-desc">প্যাকেজিং ও কোয়ালিটি চেক</div>
            </div>

            <!-- Step 3: Out for Delivery -->
            <div class="timeline-step <?php echo $stepStatuses['out_for_delivery'] ? ($currentStatus === 'Out for Delivery' ? 'active' : 'completed') : ''; ?>">
              <div class="step-node">
                <i class="fa-solid fa-truck-fast"></i>
              </div>
              <div class="step-title">অন দ্য ওয়ে</div>
              <div class="step-desc">ডেলিভারির পথে রাইডার</div>
            </div>

            <!-- Step 4: Delivered -->
            <div class="timeline-step <?php echo $stepStatuses['delivered'] ? 'completed active' : ''; ?>">
              <div class="step-node">
                <i class="fa-solid fa-house-circle-check"></i>
              </div>
              <div class="step-title">ডেলিভারড</div>
              <div class="step-desc">গ্রাহকের নিকট পৌঁছেছে</div>
            </div>
          </div>
        </div>

        <!-- Customer & Order Information Grid -->
        <div class="ghorer-card">
          <div class="ghorer-card-header">
            <h3><i class="fa-solid fa-user-check" style="color: var(--secondary);"></i> গ্রাহক ও ডেলিভারি বিবরণী</h3>
          </div>
          <div class="ghorer-card-body">
            <div class="order-info-grid">
              <div class="info-item">
                <h5>গ্রাহকের নাম</h5>
                <p><?php echo htmlspecialchars($foundOrder['name']); ?></p>
              </div>
              <div class="info-item">
                <h5>মোবাইল নম্বর</h5>
                <p class="bengali-num"><?php echo htmlspecialchars($foundOrder['phone']); ?></p>
              </div>
              <div class="info-item">
                <h5>ডেলিভারি এলাকা</h5>
                <p><?php echo htmlspecialchars($foundOrder['zone']); ?></p>
              </div>
              <div class="info-item">
                <h5>পেমেন্ট পদ্ধতি</h5>
                <p><?php echo htmlspecialchars($foundOrder['payment_method']); ?></p>
              </div>
            </div>

            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px 18px; margin-bottom: 20px;">
              <span style="font-size: 0.78rem; text-transform: uppercase; color: #64748b; font-weight: 700;">সম্পূর্ণ ডেলিভারি ঠিকানা:</span>
              <p style="font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-top: 4px;">
                <?php echo nl2br(htmlspecialchars($foundOrder['address'])); ?>
              </p>
              <?php if (!empty($foundOrder['notes'])): ?>
                <div style="margin-top: 8px; font-size: 0.88rem; color: #b45309;">
                  <strong>বিশেষ নোট:</strong> <?php echo htmlspecialchars($foundOrder['notes']); ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Ordered Items Table -->
            <h4 style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 10px;">
              <i class="fa-solid fa-basket-shopping" style="color: var(--primary);"></i> অর্ডারকৃত পণ্যসমূহ:
            </h4>
            <div style="overflow-x: auto;">
              <table class="items-table">
                <thead>
                  <tr>
                    <th>পণ্য</th>
                    <th>পরিমাণ</th>
                    <th>একক মূল্য</th>
                    <th class="text-right">মোট টাকা</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($foundOrder['items'] as $item): ?>
                    <tr>
                      <td>
                        <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                        <?php if (!empty($item['unit'])): ?>
                          <span style="color: #64748b; font-size: 0.8rem;">(<?php echo htmlspecialchars($item['unit']); ?>)</span>
                        <?php endif; ?>
                      </td>
                      <td class="bengali-num"><?php echo (int)($item['quantity'] ?? 1); ?> টি</td>
                      <td class="bengali-num">৳<?php echo number_format($item['price'] ?? 0); ?></td>
                      <td class="text-right bengali-num"><strong>৳<?php echo number_format(($item['quantity'] ?? 1) * ($item['price'] ?? 0)); ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="3" class="text-right" style="padding-top: 14px; color: #64748b;">সাবটোটাল:</td>
                    <td class="text-right bengali-num" style="padding-top: 14px;"><strong>৳<?php echo number_format($foundOrder['subtotal'] ?? 0); ?></strong></td>
                  </tr>
                  <tr>
                    <td colspan="3" class="text-right" style="color: #64748b;">ডেলিভারি চার্জ:</td>
                    <td class="text-right bengali-num"><strong>৳<?php echo number_format($foundOrder['delivery_fee'] ?? 0); ?></strong></td>
                  </tr>
                  <tr style="font-size: 1.15rem; font-weight: 800; color: var(--primary-dark); background: #fafaf9;">
                    <td colspan="3" class="text-right" style="border-top: 2px dashed #cbd5e1;">সর্বমোট প্রদেয় বিল:</td>
                    <td class="text-right bengali-num" style="border-top: 2px dashed #cbd5e1;"><strong>৳<?php echo number_format($foundOrder['total'] ?? 0); ?></strong></td>
                  </tr>
                </tfoot>
              </table>
            </div>

          </div>
        </div>

        <!-- Customer Support Callout -->
        <div class="support-callout">
          <div class="support-callout-text">
            <h4><i class="fa-solid fa-headset"></i> আপনার অর্ডার সংক্রান্ত কোনো সহায়তা প্রয়োজন?</h4>
            <p>আমাদের সাপোর্ট প্রতিনিধি সকাল ৯টা থেকে রাত ১০টা পর্যন্ত প্রস্তুত রয়েছে।</p>
          </div>
          <div class="support-buttons">
            <a href="tel:01700000000" class="btn-support-call">
              <i class="fa-solid fa-phone"></i> কল করুন
            </a>
            <a href="https://wa.me/8801700000000?text=<?php echo urlencode("আমার অর্ডার ট্র্যাকিং সংক্রান্ত তথ্য প্রয়োজন [{$foundOrder['order_id']}]"); ?>" target="_blank" class="btn-support-wa">
              <i class="fa-brands fa-whatsapp"></i> হোয়াটসঅ্যাপ
            </a>
          </div>
        </div>

      <?php elseif ($searched && !$foundOrder): ?>
        <!-- Order Not Found Error View -->
        <div class="not-found-box">
          <div class="not-found-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
          </div>
          <h3 style="font-size: 1.4rem; color: #0f172a; font-weight: 700; margin-bottom: 8px;">
            কোনো অর্ডার খুঁজে পাওয়া যায়নি!
          </h3>
          <p style="color: #64748b; font-size: 0.95rem; margin-bottom: 20px; line-height: 1.5;">
            "<strong><?php echo htmlspecialchars($queryParam); ?></strong>" দিয়ে কোনো রেকর্ড মেলেনি। অনুগ্রহ করে আপনার সঠিক অর্ডার আইডি (যেমন NH-XXXXXX) অথবা অর্ডারে ব্যবহৃত ১১ ডিজিটের মোবাইল নম্বরটি মিলিয়ে পুনরায় সার্চ করুন।
          </p>
          <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
            <a href="track.php" class="btn-modal-shop">
              <i class="fa-solid fa-rotate-left"></i> আবার চেষ্টা করুন
            </a>
            <a href="index.php" class="btn-modal-track" style="background: var(--primary);">
              <i class="fa-solid fa-store"></i> শপে যান
            </a>
          </div>
        </div>

      <?php else: ?>
        <!-- Default State: How to track instructions -->
        <div class="instructions-card">
          <h3 style="font-size: 1.25rem; font-weight: 700; color: #1e293b; text-align: center;">
            কীভাবে আপনার অর্ডার ট্র্যাক করবেন?
          </h3>
          <p style="text-align: center; color: #64748b; font-size: 0.92rem; margin-top: 4px;">
            খুব সহজেই ৩ ধাপে আপনার মধুর পার্সেল ট্র্যাক করুন:
          </p>

          <div class="instruction-steps">
            <div class="instruction-item">
              <div class="icon"><i class="fa-solid fa-receipt"></i></div>
              <h5>১. আইডি সংগ্রহ করুন</h5>
              <p>অর্ডার কনফার্ম করার সময় প্রাপ্ত NH-XXXXXX আইডিটি নিন।</p>
            </div>
            <div class="instruction-item">
              <div class="icon"><i class="fa-solid fa-keyboard"></i></div>
              <h5>২. সার্চ বক্সে লিখুন</h5>
              <p>উপরে আইডি অথবা অর্ডারে ব্যবহৃত ১১ ডিজিট ফোন নম্বর দিন।</p>
            </div>
            <div class="instruction-item">
              <div class="icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
              <h5>৩. লাইভ স্ট্যাটাস দেখুন</h5>
              <p>সার্চ করে বর্তমান প্যাকেজিং ও ডেলিভারি অগ্রগতি জানুন।</p>
            </div>
          </div>

          <div style="margin-top: 25px; padding-top: 20px; border-top: 1px dashed var(--border-color); text-align: center;">
            <p style="font-size: 0.88rem; color: #64748b;">
              জরুরি প্রয়োজনে সরাসরি আমাদের হটলাইনে ফোন দিন: 
              <a href="tel:01700000000" style="color: var(--primary-dark); font-weight: 700;">০১৭০০-০০০০০০</a>
            </p>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </main>

  <!-- Store Footer -->
  <footer class="store-footer">
    <div class="footer-contact">
      <a href="tel:01700000000"><i class="fa-solid fa-phone"></i> ০১৭০০-০০০০০০</a>
      <a href="https://wa.me/8801700000000" target="_blank"><i class="fa-brands fa-whatsapp"></i> হোয়াটসঅ্যাপ চ্যাট</a>
      <a href="checkout.php"><i class="fa-solid fa-bag-shopping"></i> চেকআউট পেইজ</a>
      <a href="index.php"><i class="fa-solid fa-store"></i> সব পণ্য দেখুন</a>
    </div>
    <p>© <?php echo date('Y'); ?> নাহিদ হানি (Nahid Honey) - সর্বস্বত্ব সংরক্ষিত।</p>
  </footer>

  <!-- JavaScript Assets -->
  <script src="assets/js/jquery.min.js"></script>
  <script src="assets/js/toastr.min.js"></script>

  <script>
    // Toastr Setup
    toastr.options = {
      closeButton: true,
      progressBar: true,
      positionClass: "toast-top-right",
      timeOut: "3500"
    };

    // Client-side quick validation on search submit
    $('#trackSearchForm').on('submit', function (e) {
      const q = $('#trackInput').val().trim();
      if (!q) {
        e.preventDefault();
        toastr.warning('অনুগ্রহ করে অর্ডার আইডি অথবা মোবাইল নম্বর লিখুন।');
        $('#trackInput').focus();
      }
    });
  </script>
</body>
</html>
