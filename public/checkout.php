<?php
/**
 * Nahid Honey - Checkout Page
 * Clean, frictionless checkout matching Ghorer Bazar design patterns.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/../database/TelegramDB.php';
$telegramDB = new TelegramDB();
$allProducts = $telegramDB->getCollection('products');

// Check for direct buy-now parameter (e.g. checkout.php?buy_now=1&id=1&qty=1)
$directProduct = null;
if (isset($_GET['id'])) {
    $targetId = (int)$_GET['id'];
    $targetQty = max(1, (int)($_GET['qty'] ?? 1));
    foreach ($allProducts as $p) {
        if ((int)$p['id'] === $targetId) {
            $prodPrice = (float)($p['sale_price'] ?? ($p['price'] ?? ($p['regular_price'] ?? 0)));
            $prodUnit = $p['weight_volume'] ?? ($p['weight'] ?? ($p['unit'] ?? ''));
            $directProduct = [
                'id' => $p['id'],
                'title' => $p['title_bn'] ?? ($p['title'] ?? ''),
                'unit' => $prodUnit,
                'price' => $prodPrice,
                'quantity' => $targetQty,
                'image' => $p['image'] ?? 'https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=300&q=80',
            ];
            break;
        }
    }
}

// Fallback top default product if cart is empty and no direct product specified
$defaultFallbackItem = null;
if (!empty($allProducts)) {
    $first = $allProducts[0];
    $fallbackPrice = (float)($first['sale_price'] ?? ($first['price'] ?? ($first['regular_price'] ?? 850)));
    $fallbackUnit = $first['weight_volume'] ?? ($first['weight'] ?? ($first['unit'] ?? '৫০০ গ্রাম'));
    $defaultFallbackItem = [
        'id' => $first['id'],
        'title' => $first['title_bn'] ?? ($first['title'] ?? 'সুন্দরবনের প্রাকৃতিক খলিসা মধু'),
        'unit' => $fallbackUnit,
        'price' => $fallbackPrice,
        'quantity' => 1,
        'image' => $first['image'] ?? 'https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=300&q=80',
    ];
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>অর্ডার কনফার্ম করুন | নাহিদ হানি (Nahid Honey) - খাঁটি প্রাকৃতিক মধু</title>
  
  <!-- Favicon -->
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🍯</text></svg>">
  
  <!-- CSS Assets -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/toastr.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
  
  <style>
    /* Inline specific checkout adjustments */
    .bengali-num {
      font-feature-settings: "tnum";
      font-variant-numeric: tabular-nums;
    }
    .badge-cod-trust {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      background: #ecfdf5;
      color: #065f46;
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 700;
    }
    .empty-cart-message {
      padding: 30px 15px;
      text-align: center;
      color: #64748b;
    }
    .empty-cart-message i {
      font-size: 40px;
      color: #cbd5e1;
      margin-bottom: 10px;
      display: block;
    }
    .btn-add-default {
      margin-top: 10px;
      background: var(--primary);
      color: #fff;
      border: none;
      padding: 8px 16px;
      border-radius: 6px;
      font-weight: 600;
      cursor: pointer;
    }
    .bkash-extra-info {
      margin-top: 12px;
      padding: 12px;
      background: #fdf2f8;
      border: 1px dashed #f472b6;
      border-radius: 8px;
      font-size: 0.85rem;
      color: #831843;
      display: none;
    }
  </style>
</head>
<body>

  <!-- Top Announcement Bar -->
  <div class="top-notice-bar">
    <span>🌿 ১০০% আসল সুন্দরবনের মধু ও প্রাকৃতিক খাবারের বিশ্বস্ত প্রতিষ্ঠান | ক্যাশ অন ডেলিভারি | হোম ডেলিভারি</span>
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
        <a href="track.php" class="btn-track-header">
          <i class="fa-solid fa-truck-fast"></i>
          <span>অর্ডার ট্র্যাক</span>
        </a>
        <a href="tel:01700000000" class="btn-hotline">
          <i class="fa-solid fa-phone-volume"></i>
          <span>০১৭০০-০০০০০০</span>
        </a>
      </div>
    </div>
  </header>

  <!-- Main Checkout Content -->
  <main class="main-wrapper">
    <!-- Breadcrumb -->
    <div class="page-breadcrumb">
      <a href="index.php"><i class="fa-solid fa-house"></i> হোম</a>
      <span><i class="fa-solid fa-chevron-right" style="font-size: 0.75rem;"></i></span>
      <span style="color: #0f172a; font-weight: 600;">চেকআউট ও অর্ডার কনফার্মেশন</span>
    </div>

    <div class="page-title-banner">
      <h2>অর্ডার সম্পন্ন করতে নিচের তথ্যগুলো পূরণ করুন</h2>
      <p>পণ্য হাতে পেয়ে চেক করে সম্পূর্ণ মূল্য পরিশোধ করার সুবিধা (ক্যাশ অন ডেলিভারি)</p>
    </div>

    <!-- Checkout Two-Column Grid -->
    <form id="checkoutForm" novalidate>
      <div class="checkout-layout">

        <!-- Left Column: Customer & Delivery Information -->
        <div class="checkout-form-column">

          <!-- Card 1: Customer Details -->
          <div class="ghorer-card">
            <div class="ghorer-card-header">
              <h3>
                <span class="step-badge">১</span>
                <span>আপনার ঠিকানা ও ব্যক্তিগত তথ্য</span>
              </h3>
              <span class="badge-cod-trust">
                <i class="fa-solid fa-shield-halved"></i> ১০০% নিরাপদ
              </span>
            </div>
            <div class="ghorer-card-body">
              <div class="form-group">
                <label for="customerName">
                  আপনার সম্পূর্ণ নাম <span class="required">*</span>
                </label>
                <div class="input-with-icon">
                  <i class="fa-solid fa-user input-icon"></i>
                  <input type="text" id="customerName" name="name" class="form-control" placeholder="যেমন: মোহাম্মদ আবদুল্লাহ" required autocomplete="name">
                </div>
              </div>

              <div class="form-group">
                <label for="customerPhone">
                  মোবাইল নম্বর (১১ ডিজিট) <span class="required">*</span>
                </label>
                <div class="input-with-icon">
                  <i class="fa-solid fa-phone input-icon"></i>
                  <input type="tel" id="customerPhone" name="phone" class="form-control bengali-num" placeholder="017XXXXXXXX" maxlength="11" required autocomplete="tel">
                </div>
                <small style="color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block;">
                  * ডেলিভারি রাইডার এই নম্বরে যোগাযোগ করে পণ্য পৌঁছে দেবে।
                </small>
              </div>

              <div class="form-group">
                <label for="customerAddress">
                  সম্পূর্ণ ডেলিভারি ঠিকানা <span class="required">*</span>
                </label>
                <div class="input-with-icon">
                  <i class="fa-solid fa-location-dot input-icon" style="top: 24px;"></i>
                  <textarea id="customerAddress" name="address" class="form-control" placeholder="বাসা নম্বর, রোড নম্বর, এলাকা, থানা ও জেলার নাম বিস্তারিত লিখুন..." required autocomplete="street-address"></textarea>
                </div>
              </div>

              <div class="form-group" style="margin-bottom: 0;">
                <label for="orderNotes">
                  ডেলিভারি নোট / বিশেষ নির্দেশনা (ঐচ্ছিক)
                </label>
                <div class="input-with-icon">
                  <i class="fa-solid fa-pen-to-square input-icon" style="top: 24px;"></i>
                  <textarea id="orderNotes" name="notes" class="form-control" style="min-height: 60px;" placeholder="যেমন: বিকাল ৫টার পরে ডেলিভারি দিন অথবা কোনো বিশেষ নির্দেশনা..."></textarea>
                </div>
              </div>
            </div>
          </div>

          <!-- Card 2: Delivery Area Selection -->
          <div class="ghorer-card">
            <div class="ghorer-card-header">
              <h3>
                <span class="step-badge">২</span>
                <span>ডেলিভারি এলাকা নির্বাচন করুন</span>
              </h3>
            </div>
            <div class="ghorer-card-body">
              <div class="radio-cards-grid">
                <!-- Inside Dhaka -->
                <label class="radio-option-card active" id="zoneDhakaCard">
                  <input type="radio" name="delivery_zone" value="Dhaka" checked>
                  <div class="radio-card-content">
                    <div class="radio-card-title">
                      <span>ঢাকার ভিতরে</span>
                      <span class="radio-badge-price">৳৭০</span>
                    </div>
                    <div class="radio-card-desc">হোম ডেলিভারি (২৪-৪৮ ঘণ্টা)</div>
                  </div>
                </label>

                <!-- Outside Dhaka -->
                <label class="radio-option-card" id="zoneOutsideCard">
                  <input type="radio" name="delivery_zone" value="Outside Dhaka">
                  <div class="radio-card-content">
                    <div class="radio-card-title">
                      <span>ঢাকার বাইরে</span>
                      <span class="radio-badge-price">৳১৩০</span>
                    </div>
                    <div class="radio-card-desc">সারা বাংলাদেশে হোম ডেলিভারি (২-৪ দিন)</div>
                  </div>
                </label>
              </div>
            </div>
          </div>

          <!-- Card 3: Payment Method Selection -->
          <div class="ghorer-card">
            <div class="ghorer-card-header">
              <h3>
                <span class="step-badge">৩</span>
                <span>পেমেন্ট পদ্ধতি নির্বাচন করুন</span>
              </h3>
            </div>
            <div class="ghorer-card-body">
              <!-- Cash on Delivery -->
              <label class="payment-card active" id="paymentCodCard">
                <input type="radio" name="payment_method" value="Cash on Delivery" checked>
                <div class="payment-icon-box payment-icon-cod">
                  <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
                <div class="payment-info">
                  <h4>ক্যাশ অন ডেলিভারি (Cash on Delivery)</h4>
                  <p>পণ্য হাতে পেয়ে চেক করে মূল্য পরিশোধ করুন। অগ্রিম কোনো টাকা দিতে হবে না।</p>
                </div>
              </label>

              <!-- bKash -->
              <label class="payment-card" id="paymentBkashCard">
                <input type="radio" name="payment_method" value="bKash">
                <div class="payment-icon-box payment-icon-bkash">
                  <i class="fa-solid fa-mobile-screen-button"></i>
                </div>
                <div class="payment-info">
                  <h4>বিকাশ পেমেন্ট (bKash Payment)</h4>
                  <p>বিকাশের মাধ্যমে সরাসরি পেমেন্ট করতে চাইলে এই অপশন নির্বাচন করুন।</p>
                </div>
              </label>

              <!-- bKash instructions dropdown -->
              <div class="bkash-extra-info" id="bkashHelpBox">
                <strong><i class="fa-solid fa-circle-info"></i> বিকাশ পেমেন্ট নির্দেশনা:</strong><br>
                অর্ডার নিশ্চিত করার পর আমাদের বিকাশ মার্চেন্ট/পার্সোনাল নম্বর <strong>০১৭০০-০০০০০০</strong>-এ সেন্ড মানি করুন। ডেলিভারির সময় রাইডারকে ট্রানজেকশন আইডি দেখালেই হবে অথবা ক্যাশ পেমেন্ট করতে পারেন।
              </div>
            </div>
          </div>

        </div>

        <!-- Right Column: Order Summary Table & Place Order Button -->
        <div class="checkout-summary-column">
          <div class="order-summary-box">
            <div class="summary-card">
              <div class="summary-header">
                <h3><i class="fa-solid fa-bag-shopping" style="color: var(--primary);"></i> আপনার অর্ডার বিবরণী</h3>
              </div>

              <!-- Items List -->
              <div class="summary-items-list" id="orderItemsContainer">
                <!-- Dynamically populated via JavaScript -->
              </div>

              <!-- Price Calculations -->
              <div class="summary-totals">
                <div class="total-line">
                  <span>সাবটোটাল (পণ্য মূল্য)</span>
                  <span id="subtotalDisplay" class="bengali-num" style="font-weight: 600;">৳০</span>
                </div>
                <div class="total-line delivery-line">
                  <span>ডেলিভারি চার্জ (<span id="zoneLabelDisplay">ঢাকার ভিতরে</span>)</span>
                  <span id="deliveryFeeDisplay" class="bengali-num" style="font-weight: 600;">৳৭০</span>
                </div>
                <div class="total-line grand-total">
                  <span>সর্বমোট প্রদেয় বিল</span>
                  <span id="grandTotalDisplay" class="grand-total-amount bengali-num">৳০</span>
                </div>

                <!-- Submit Button -->
                <button type="submit" id="btnPlaceOrder" class="btn-confirm-order">
                  <i class="fa-solid fa-lock"></i>
                  <span id="btnOrderText">অর্ডার নিশ্চিত করুন (৳০)</span>
                </button>
              </div>

              <!-- Trust Badges List -->
              <div class="trust-badges-list">
                <div class="trust-badge-item">
                  <i class="fa-solid fa-circle-check"></i>
                  <span>১০০% খাঁটি ও প্রাকৃতিক পণ্যের নিশ্চয়তা</span>
                </div>
                <div class="trust-badge-item">
                  <i class="fa-solid fa-box-open"></i>
                  <span>প্যাকেট খুলে কোয়ালিটি দেখে মূল্য পরিশোধের সুবিধা</span>
                </div>
                <div class="trust-badge-item">
                  <i class="fa-solid fa-rotate-left"></i>
                  <span>পণ্য অপছন্দ হলে সহজে রিটার্ন বা রিফান্ড পলিসি</span>
                </div>
                <div class="trust-badge-item">
                  <i class="fa-solid fa-truck"></i>
                  <span>সারা বাংলাদেশে বিশ্বস্ত ও নিরাপদ হোম ডেলিভারি</span>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </form>
  </main>

  <!-- Store Footer -->
  <footer class="store-footer">
    <div class="footer-contact">
      <a href="tel:01700000000"><i class="fa-solid fa-phone"></i> ০১৭০০-০০০০০০</a>
      <a href="https://wa.me/8801700000000" target="_blank"><i class="fa-brands fa-whatsapp"></i> হোয়াটসঅ্যাপ চ্যাট</a>
      <a href="track.php"><i class="fa-solid fa-location-crosshairs"></i> অর্ডার ট্র্যাকিং</a>
      <a href="index.php"><i class="fa-solid fa-store"></i> সব পণ্য দেখুন</a>
    </div>
    <p>© <?php echo date('Y'); ?> নাহিদ হানি (Nahid Honey) - সর্বস্বত্ব সংরক্ষিত।</p>
  </footer>

  <!-- Order Success Modal -->
  <div class="modal-overlay" id="orderSuccessModal">
    <div class="order-success-modal">
      <div class="success-check-icon">
        <i class="fa-solid fa-check"></i>
      </div>
      <h3 class="modal-title">অর্ডার সফলভাবে সম্পন্ন হয়েছে!</h3>
      <p class="modal-subtitle">
        ধন্যবাদ! আপনার অর্ডারটি আমরা গ্রহণ করেছি। খুব শীঘ্রই আমাদের প্রতিনিধি আপনাকে ফোন করে অর্ডারটি কনফার্ম করবে।
      </p>

      <!-- Tracking ID Container -->
      <div class="tracking-id-box">
        <div class="tracking-id-text">
          <span>অর্ডার ট্র্যাকিং আইডি</span>
          <div class="tracking-id-number" id="modalOrderId">NH-XXXXXX</div>
        </div>
        <button type="button" class="btn-copy-id" id="btnCopyOrderId" title="আইডি কপি করুন">
          <i class="fa-regular fa-copy"></i>
          <span>কপি</span>
        </button>
      </div>

      <!-- Quick Details Summary -->
      <div class="modal-details-summary">
        <div><strong>গ্রাহকের নাম:</strong> <span id="modalCustomerName"></span></div>
        <div><strong>মোবাইল নম্বর:</strong> <span id="modalCustomerPhone"></span></div>
        <div><strong>ডেলিভারি ঠিকানা:</strong> <span id="modalCustomerAddress"></span></div>
        <div><strong>পেমেন্ট মাধ্যম:</strong> <span id="modalPaymentMethod"></span></div>
        <div style="font-size: 1rem; font-weight: 700; color: #b45309; margin-top: 6px;">
          <strong>মোট প্রদেয় বিল:</strong> ৳<span id="modalTotalPayable"></span>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="modal-action-buttons">
        <a href="track.php" id="modalTrackLink" class="btn-modal-track">
          <i class="fa-solid fa-truck-fast"></i>
          <span>অর্ডার ট্র্যাক করুন</span>
        </a>
        <a href="index.php" class="btn-modal-shop">
          <i class="fa-solid fa-arrow-left"></i>
          <span>শপে ফিরে যান</span>
        </a>
      </div>
    </div>
  </div>

  <!-- JavaScript Assets -->
  <script src="assets/js/jquery.min.js"></script>
  <script src="assets/js/toastr.min.js"></script>

  <script>
    // Configuration & Initial Data injected from PHP
    const DIRECT_PRODUCT = <?php echo json_encode($directProduct); ?>;
    const DEFAULT_FALLBACK = <?php echo json_encode($defaultFallbackItem); ?>;

    // Toastr Setup
    toastr.options = {
      closeButton: true,
      progressBar: true,
      positionClass: "toast-top-right",
      timeOut: "3500"
    };

    // State
    let cartItems = [];
    let deliveryFee = 70; // Default Inside Dhaka
    let deliveryZone = "Dhaka";
    let paymentMethod = "Cash on Delivery";

    // Initialize Cart
    function initCart() {
      // 1. If direct product query was supplied (?id=1&qty=1), prefer it
      if (DIRECT_PRODUCT) {
        cartItems = [DIRECT_PRODUCT];
        saveCartToStorage();
      } else {
        // 2. Try loading from localStorage
        try {
          const stored = localStorage.getItem('nahid_cart') || localStorage.getItem('cart');
          if (stored) {
            const parsed = JSON.parse(stored);
            if (Array.isArray(parsed) && parsed.length > 0) {
              cartItems = parsed;
            }
          }
        } catch (e) {
          console.error("Cart parse error:", e);
        }

        // 3. If cart is still empty, load default top featured product
        if (cartItems.length === 0 && DEFAULT_FALLBACK) {
          cartItems = [DEFAULT_FALLBACK];
          saveCartToStorage();
        }
      }

      renderCartSummary();
    }

    function saveCartToStorage() {
      try {
        localStorage.setItem('nahid_cart', JSON.stringify(cartItems));
      } catch (e) {}
    }

    // Render Order Summary
    function renderCartSummary() {
      const $container = $('#orderItemsContainer');
      $container.empty();

      if (cartItems.length === 0) {
        $container.html(`
          <div class="empty-cart-message">
            <i class="fa-solid fa-basket-shopping"></i>
            <p>আপনার কার্টে বর্তমানে কোনো পণ্য নেই।</p>
            ${DEFAULT_FALLBACK ? '<button type="button" class="btn-add-default" id="btnAddDefaultProduct">মধু যোগ করুন</button>' : ''}
          </div>
        `);
        $('#subtotalDisplay').text('৳০');
        $('#grandTotalDisplay').text('৳০');
        $('#btnOrderText').text('অর্ডার নিশ্চিত করুন (৳০)');
        $('#btnPlaceOrder').prop('disabled', true);
        return;
      }

      let subtotal = 0;

      cartItems.forEach((item, idx) => {
        const itemQty = Math.max(1, parseInt(item.quantity || item.qty || 1));
        const itemPrice = parseFloat(item.price || 0);
        const itemLineTotal = itemQty * itemPrice;
        subtotal += itemLineTotal;

        const imgUrl = item.image || 'https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=150&q=80';
        const unitHtml = item.unit ? `<span style="color:#64748b; font-size: 0.8rem;">(${item.unit})</span>` : '';

        const rowHtml = `
          <div class="summary-item-row" data-index="${idx}">
            <img src="${imgUrl}" alt="${escapeHtml(item.title)}" class="item-thumb" onerror="this.src='https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=150&q=80'">
            <div class="item-details">
              <div class="item-title">${escapeHtml(item.title)} ${unitHtml}</div>
              <div class="item-price-calc">
                <div class="item-qty-control">
                  <button type="button" class="btn-qty-minus" data-idx="${idx}">-</button>
                  <span>${itemQty}</span>
                  <button type="button" class="btn-qty-plus" data-idx="${idx}">+</button>
                </div>
                <span>✕ ৳${formatNumber(itemPrice)}</span>
                <button type="button" class="item-remove-btn" data-idx="${idx}" title="পণ্য সরান">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
              </div>
            </div>
            <div class="item-total-price bengali-num">
              ৳${formatNumber(itemLineTotal)}
            </div>
          </div>
        `;
        $container.append(rowHtml);
      });

      const grandTotal = subtotal + deliveryFee;

      $('#subtotalDisplay').text('৳' + formatNumber(subtotal));
      $('#deliveryFeeDisplay').text('৳' + formatNumber(deliveryFee));
      $('#grandTotalDisplay').text('৳' + formatNumber(grandTotal));
      $('#btnOrderText').text(`অর্ডার নিশ্চিত করুন (৳${formatNumber(grandTotal)})`);
      $('#btnPlaceOrder').prop('disabled', false);
    }

    // Helper formatting
    function formatNumber(num) {
      return num.toLocaleString('en-US');
    }

    function escapeHtml(str) {
      if (!str) return '';
      return $('<div>').text(str).html();
    }

    // Cart item manipulation
    $(document).on('click', '.btn-qty-plus', function () {
      const idx = $(this).data('idx');
      if (cartItems[idx]) {
        cartItems[idx].quantity = (parseInt(cartItems[idx].quantity || 1)) + 1;
        saveCartToStorage();
        renderCartSummary();
      }
    });

    $(document).on('click', '.btn-qty-minus', function () {
      const idx = $(this).data('idx');
      if (cartItems[idx]) {
        const currentQty = parseInt(cartItems[idx].quantity || 1);
        if (currentQty > 1) {
          cartItems[idx].quantity = currentQty - 1;
        } else {
          cartItems.splice(idx, 1);
        }
        saveCartToStorage();
        renderCartSummary();
      }
    });

    $(document).on('click', '.item-remove-btn', function () {
      const idx = $(this).data('idx');
      if (cartItems[idx]) {
        cartItems.splice(idx, 1);
        saveCartToStorage();
        renderCartSummary();
        toastr.info('পণ্যটি কার্ট থেকে সরানো হয়েছে');
      }
    });

    $(document).on('click', '#btnAddDefaultProduct', function () {
      if (DEFAULT_FALLBACK) {
        cartItems = [DEFAULT_FALLBACK];
        saveCartToStorage();
        renderCartSummary();
        toastr.success('পণ্য কার্টে যোগ করা হয়েছে!');
      }
    });

    // Delivery Zone Selection & Total Recalculation
    $('input[name="delivery_zone"]').on('change', function () {
      deliveryZone = $(this).val();
      $('.radio-option-card').removeClass('active');
      $(this).closest('.radio-option-card').addClass('active');

      if (deliveryZone === 'Outside Dhaka') {
        deliveryFee = 130;
        $('#zoneLabelDisplay').text('ঢাকার বাইরে');
      } else {
        deliveryFee = 70;
        $('#zoneLabelDisplay').text('ঢাকার ভিতরে');
      }

      renderCartSummary();
    });

    // Payment Method Selection
    $('input[name="payment_method"]').on('change', function () {
      paymentMethod = $(this).val();
      $('.payment-card').removeClass('active');
      $(this).closest('.payment-card').addClass('active');

      if (paymentMethod === 'bKash') {
        $('#bkashHelpBox').slideDown(200);
      } else {
        $('#bkashHelpBox').slideUp(200);
      }
    });

    // Form Submission with AJAX
    $('#checkoutForm').on('submit', function (e) {
      e.preventDefault();

      // Basic Client Validations
      const name = $('#customerName').val().trim();
      const phone = $('#customerPhone').val().trim();
      const address = $('#customerAddress').val().trim();
      const notes = $('#orderNotes').val().trim();

      if (name.length < 2) {
        toastr.warning('অনুগ্রহ করে আপনার সঠিক নাম লিখুন।');
        $('#customerName').focus();
        return;
      }

      // Bangladeshi mobile validation
      const cleanPhone = phone.replace(/[^\d]/g, '');
      const bdPhoneRegex = /^(?:88)?01[3-9]\d{8}$/;
      if (!bdPhoneRegex.test(cleanPhone)) {
        toastr.warning('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন (যেমন: 017XXXXXXXX)।');
        $('#customerPhone').focus();
        return;
      }

      if (address.length < 5) {
        toastr.warning('অনুগ্রহ করে আপনার সম্পূর্ণ ডেলিভারি ঠিকানা লিখুন।');
        $('#customerAddress').focus();
        return;
      }

      if (cartItems.length === 0) {
        toastr.error('আপনার কার্টে কোনো পণ্য নেই!');
        return;
      }

      // Calculate Subtotal & Items payload
      const cleanItems = cartItems.map(item => ({
        id: item.id || '',
        title: item.title,
        unit: item.unit || '',
        quantity: parseInt(item.quantity || item.qty || 1),
        price: parseFloat(item.price || 0),
        image: item.image || ''
      }));

      const payload = {
        name: name,
        phone: cleanPhone,
        address: address,
        notes: notes,
        zone: deliveryZone,
        delivery_fee: deliveryFee,
        payment_method: paymentMethod,
        items: cleanItems
      };

      // Button Loading UI
      const $btn = $('#btnPlaceOrder');
      const originalText = $btn.html();
      $btn.prop('disabled', true).html('<span class="spinner"></span> <span>অর্ডার প্রসেসিং হচ্ছে...</span>');

      $.ajax({
        url: 'api.php?action=create_order',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        dataType: 'json',
        timeout: 20000,
        success: function (res) {
          if (res.success) {
            // Order Placed Successfully!
            toastr.success(res.message || 'আপনার অর্ডারটি সফলভাবে সম্পন্ন হয়েছে!');

            // Clear Cart Storage
            try {
              localStorage.removeItem('nahid_cart');
              localStorage.removeItem('cart');
              cartItems = [];
            } catch (err) {}

            // Populate Success Modal
            const order = res.order || {};
            const orderId = res.order_id || order.order_id || 'NH-DONE';
            $('#modalOrderId').text(orderId);
            $('#modalCustomerName').text(order.name || name);
            $('#modalCustomerPhone').text(order.phone || cleanPhone);
            $('#modalCustomerAddress').text(order.address || address);
            $('#modalPaymentMethod').text(order.payment_method || paymentMethod);
            $('#modalTotalPayable').text(formatNumber(order.total || (payload.subtotal + deliveryFee)));

            // Update Track Link
            $('#modalTrackLink').attr('href', 'track.php?id=' + encodeURIComponent(orderId));

            // Display Modal
            $('#orderSuccessModal').addClass('show');

          } else {
            toastr.error(res.error || 'অর্ডার করতে সমস্যা হয়েছে। আবার চেষ্টা করুন।');
            $btn.prop('disabled', false).html(originalText);
          }
        },
        error: function (xhr) {
          let errText = 'সার্ভারে সংযোগ করতে সমস্যা হয়েছে। দয়া করে আবার চেষ্টা করুন।';
          try {
            const errRes = JSON.parse(xhr.responseText);
            if (errRes && errRes.error) {
              errText = errRes.error;
            }
          } catch (e) {}
          toastr.error(errText);
          $btn.prop('disabled', false).html(originalText);
        }
      });
    });

    // Copy Order ID
    $('#btnCopyOrderId').on('click', function () {
      const orderId = $('#modalOrderId').text();
      navigator.clipboard.writeText(orderId).then(() => {
        toastr.success('অর্ডার আইডি কপি করা হয়েছে!');
      }).catch(() => {
        toastr.info(orderId);
      });
    });

    // Initialize on Document Ready
    $(document).ready(function () {
      initCart();
    });
  </script>
</body>
</html>
