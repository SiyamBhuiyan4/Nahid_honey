<?php
/**
 * Nahid Honey - Main Homepage
 * Clean Ghorer Bazar Design Patterns
 * Integrated with TelegramDB & Telegram CDN Engine
 */

require_once __DIR__ . '/../database/TelegramDB.php';

$db = new TelegramDB();
$rawProducts = $db->getCollection('products');
$rawReviews = $db->getCollection('reviews');

// Helper function for Bengali numerals
function bn_num($number) {
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    return str_replace($en, $bn, number_format((float)$number));
}

// Normalize products for uniform template handling
$products = array_map(function($p) {
    $salePrice = (float)($p['sale_price'] ?? $p['price'] ?? 0);
    $regularPrice = (float)($p['regular_price'] ?? $p['original_price'] ?? $salePrice);
    
    $discountText = $p['discount_text'] ?? '';
    if (empty($discountText) && $regularPrice > $salePrice) {
        $pct = round((($regularPrice - $salePrice) / $regularPrice) * 100);
        $discountText = bn_num($pct) . '% ছাড়';
    }

    return [
        'id' => $p['id'],
        'title' => $p['title_bn'] ?? $p['title'] ?? '',
        'slug' => $p['slug'] ?? ('product-' . $p['id']),
        'category' => $p['category'] ?? '',
        'category_name' => $p['category_bn'] ?? $p['category_name'] ?? $p['category'] ?? 'প্রাকৃতিক পণ্য',
        'price' => $salePrice,
        'original_price' => $regularPrice > $salePrice ? $regularPrice : null,
        'discount_text' => $discountText,
        'unit' => $p['weight_volume'] ?? $p['weight'] ?? $p['unit'] ?? '১ পিস',
        'rating' => (float)($p['rating'] ?? 5.0),
        'reviews_count' => (int)($p['reviews_count'] ?? 45),
        'badge' => $p['badge'] ?? '',
        'image' => $p['image'] ?? 'https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=600&q=80',
        'description' => $p['description'] ?? '',
        'in_stock' => $p['in_stock'] ?? true,
        'is_top' => (!empty($p['top_selling']) || ($p['badge'] ?? '') === 'Best Seller' || ($p['rating'] ?? 0) >= 4.95)
    ];
}, $rawProducts);

// Normalize reviews
$reviews = array_map(function($r) {
    return [
        'id' => $r['id'] ?? 1,
        'customer_name' => $r['customer_name'] ?? 'সন্তুষ্ট গ্রাহক',
        'location' => $r['location'] ?? 'ঢাকা, বাংলাদেশ',
        'badge' => $r['group_badge'] ?? $r['badge'] ?? 'Top Contributor',
        'avatar' => $r['avatar'] ?? 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=150&q=80',
        'product_name' => $r['product_title'] ?? $r['product_name'] ?? 'সুন্দরবনের খাঁটি মধু',
        'rating' => (int)($r['rating'] ?? 5),
        'date' => $r['review_date'] ?? $r['date'] ?? 'সম্প্রতি',
        'comment' => $r['comment'] ?? '',
        'verified_purchase' => $r['verified_purchase'] ?? true
    ];
}, $rawReviews);

// Category Shelves
$topSelling = array_slice(array_filter($products, fn($p) => $p['is_top']), 0, 8);
if (empty($topSelling)) {
    $topSelling = array_slice($products, 0, 8);
}

$honeyProducts = array_filter($products, function($p) {
    return stripos($p['category'], 'honey') !== false || stripos($p['category_name'], 'মধু') !== false;
});

$gurProducts = array_filter($products, function($p) {
    return stripos($p['category'], 'gur') !== false || stripos($p['category_name'], 'গুড়') !== false || stripos($p['category_name'], 'রস') !== false;
});

$organicProducts = array_filter($products, function($p) {
    return stripos($p['category'], 'oil') !== false || stripos($p['category'], 'ghee') !== false || 
           stripos($p['category'], 'organic') !== false || stripos($p['category'], 'seasonal') !== false ||
           stripos($p['category_name'], 'ঘি') !== false || stripos($p['category_name'], 'তেল') !== false ||
           stripos($p['category_name'], 'অর্গানিক') !== false;
});
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>নাহিদ হানী - Nahid Honey | ১০০% খাঁটি প্রাকৃতিক খাবারের বিশ্বস্ত ঠিকানা</title>
    <meta name="description" content="সুন্দরবনের প্রাকৃতিক চাকের কাঁচা মধু, যশোরের খাঁটি খেজুর গুড় ও রস, খাঁটি গাওয়া ঘি ও ঘানি ভাঙা সরিষার তেল। সারা বাংলাদেশে ক্যাশ অন হোম ডেলিভারি।">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Swiper & Toastr CSS -->
    <link rel="stylesheet" href="assets/css/swiper-bundle.min.css">
    <link rel="stylesheet" href="assets/css/toastr.min.css">
    
    <!-- Custom Theme CSS (Ghorer Bazar Design Language) -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

    <!-- ======================================================================
         Top Announcement Bar
         ====================================================================== -->
    <aside class="top-bar" aria-label="Announcement Bar">
        <div class="container top-bar-inner">
            <div class="top-bar-notice">
                <span class="badge-pulse">হট অফার</span>
                <span>🚚 সারা বাংলাদেশে ক্যাশ অন হোম ডেলিভারি সুবিধা!</span>
            </div>
            <div class="top-bar-contacts">
                <a href="tel:+8809642922922" class="top-bar-phone">
                    <i class="fa-solid fa-phone-volume"></i>
                    <span>হটলাইন: +8809642922922</span>
                </a>
                <a href="track-order.php" class="top-bar-track">
                    <i class="fa-solid fa-truck-fast"></i>
                    <span>অর্ডার ট্র্যাক</span>
                </a>
            </div>
        </div>
    </aside>

    <!-- ======================================================================
         Sticky Header
         ====================================================================== -->
    <header class="main-header" id="mainHeader">
        <div class="container header-inner">
            <!-- Brand Logo -->
            <a href="index.php" class="logo-container" aria-label="Nahid Honey Homepage">
                <div class="logo-icon-box">
                    <i class="fa-solid fa-jar"></i>
                </div>
                <div class="logo-text-box">
                    <h1>নাহিদ হানী <span>- Nahid Honey</span></h1>
                    <p>১০০% খাঁটি ও প্রাকৃতিক খাবারের বিশ্বস্ত ঠিকানা</p>
                </div>
            </a>

            <!-- Search Bar with Instant AJAX Filter -->
            <div class="header-search">
                <div class="search-input-group">
                    <input type="text" id="searchInput" class="search-input" placeholder="খাঁটি মধু, খেজুর গুড়, গাওয়া ঘি বা যেকোনো পণ্য খুঁজুন..." autocomplete="off">
                    <button class="search-btn" aria-label="Search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>
                </div>
                <!-- Live Search Results Dropdown -->
                <div class="search-results-dropdown" id="searchResultsDropdown"></div>
            </div>

            <!-- Header Action Items: Track Order, Wishlist, Cart Button -->
            <div class="header-actions">
                <a href="track-order.php" class="action-btn" title="আপনার অর্ডারের বর্তমান অবস্থা ট্র্যাক করুন">
                    <i class="fa-solid fa-truck-ramp-box"></i>
                    <span>Track Order</span>
                </a>

                <button class="action-btn" id="headerWishlistBtn" title="পছন্দের তালিকা">
                    <i class="fa-regular fa-heart"></i>
                    <span>Wishlist</span>
                    <span class="icon-badge" id="headerWishlistCount">0</span>
                </button>

                <button class="action-btn cart-action-btn" id="openCartDrawerBtn" title="শপিং কার্ট খুলুন">
                    <i class="fa-solid fa-basket-shopping"></i>
                    <span>Cart</span>
                    <span class="icon-badge" id="headerCartCount">0</span>
                    <span class="cart-total" id="headerCartTotal">৳০</span>
                </button>
            </div>
        </div>
    </header>

    <!-- ======================================================================
         Navigation Menu: All Products, Pure Honey, Khejur Gur & Rosh, etc.
         ====================================================================== -->
    <nav class="main-nav" aria-label="Category Navigation">
        <div class="container nav-inner">
            <ul class="nav-links">
                <li><a href="#all-products" class="nav-link active"><i class="fa-solid fa-table-cells-large"></i> All Products (সব পণ্য)</a></li>
                <li><a href="#pure-honey" class="nav-link"><i class="fa-solid fa-flask-vial"></i> Pure Honey (মধু)</a></li>
                <li><a href="#khejur-gur" class="nav-link"><i class="fa-solid fa-bowl-rice"></i> Khejur Gur & Rosh (খেজুর গুড় ও রস) <span class="nav-tag">শীতের তাজা</span></a></li>
                <li><a href="#oil-ghee" class="nav-link"><i class="fa-solid fa-bottle-droplet"></i> Pure Ghee & Mustard Oil (ঘি ও সরিষার তেল)</a></li>
                <li><a href="#organic-seasonal" class="nav-link"><i class="fa-solid fa-seedling"></i> Organic & Seasonal (পাহাড়ি পেঁপে)</a></li>
                <li><a href="#reviews-section" class="nav-link"><i class="fa-solid fa-comments"></i> কাস্টমার রিভিউ</a></li>
            </ul>

            <a href="#khejur-gur" class="nav-campaign-pill">
                <i class="fa-solid fa-leaf"></i>
                <span>শীতের তাজা খেজুর গুড় ও রস</span>
            </a>
        </div>
    </nav>

    <!-- ======================================================================
         Hero Banner Carousel (Swiper.js)
         - 100% Pure Organic Food & Winter Khejur Gur showcase
         - Winter Khejur Gur special
         - Sundarban Raw Honey showcase
         ====================================================================== -->
    <section class="hero-section">
        <div class="container">
            <div class="swiper hero-swiper hero-slider-container">
                <div class="swiper-wrapper">
                    <!-- Slide 1: 100% Pure Food & Winter Khejur Gur -->
                    <div class="swiper-slide hero-slide" style="background-image: url('https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=1600&q=80');">
                        <div class="hero-slide-content">
                            <span class="hero-badge badge-red">
                                <i class="fa-solid fa-leaf"></i> ১০০% খাঁটি ও প্রাকৃতিক খাবার
                            </span>
                            <h2 class="hero-title">যশোরের ঐতিহ্যবাহী <span>নলেন খেজুর গুড় ও তাজা রস</span></h2>
                            <p class="hero-subtitle">কোনো চিনি বা কৃত্রিম উপাদান ছাড়া সরাসরি গাছিদের থেকে সংগৃহীত সুবাসিত পাটালি, ঝোলা গুড় এবং প্রাকৃতিক চাকের খাঁটি মধু। সারা দেশে ক্যাশ অন হোম ডেলিভারি!</p>
                            <div class="hero-btn-group">
                                <a href="#khejur-gur" class="btn-primary-hero">
                                    <i class="fa-solid fa-bag-shopping"></i> গুড় ও রস দেখুন
                                </a>
                                <a href="#top-selling" class="btn-secondary-hero">
                                    <i class="fa-solid fa-jar"></i> সেরা পণ্য কিনুন
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Slide 2: Winter Khejur Gur Special -->
                    <div class="swiper-slide hero-slide" style="background-image: url('https://images.unsplash.com/photo-1607623814075-e51df1bdc82f?auto=format&fit=crop&w=1600&q=80');">
                        <div class="hero-slide-content">
                            <span class="hero-badge">
                                <i class="fa-solid fa-snowflake"></i> শীতের বিশেষ আয়োজন
                            </span>
                            <h2 class="hero-title">যশোরের স্পেশাল খাঁটি <span>নলেন ঝোলা ও দানাদার পাটালি</span> খেজুর গুড়</h2>
                            <p class="hero-subtitle">গাছিদের মাটির হাঁড়ির তাজা রস জ্বাল দিয়ে তৈরি চিনিমুক্ত, সুবাসিত আসল গুড়। শীতের ভাপা ও চিতই পিঠার আসল তৃপ্তি আনুন খাঁটি স্বাদে।</p>
                            <div class="hero-btn-group">
                                <a href="#khejur-gur" class="btn-primary-hero">
                                    <i class="fa-solid fa-cart-shopping"></i> গুড় অর্ডার করুন
                                </a>
                                <a href="tel:+8809642922922" class="btn-secondary-hero">
                                    <i class="fa-solid fa-phone"></i> +8809642922922
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Slide 3: Sundarban Raw Honey Showcase -->
                    <div class="swiper-slide hero-slide" style="background-image: url('https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=1600&q=80');">
                        <div class="hero-slide-content">
                            <span class="hero-badge badge-green">
                                <i class="fa-solid fa-certificate"></i> সুন্দরবন সরাসরি সংগ্রহ
                            </span>
                            <h2 class="hero-title">সুন্দরবনের প্রাকৃতিক চাক ভাঙা <span>কাঁচা অপরিশোধিত খাঁটি মধু</span></h2>
                            <p class="hero-subtitle">অভিজ্ঞ মৌয়ালদের দ্বারা গহীন বনের খলিসা ও গরান ফুলের চাক থেকে সংগৃহীত। কোনো হিটিং বা প্রসেসিং ছাড়া শতভাগ খাঁটি ও এনজাইম সমৃদ্ধ।</p>
                            <div class="hero-btn-group">
                                <a href="#pure-honey" class="btn-primary-hero">
                                    <i class="fa-solid fa-jar"></i> মধু কিনুন
                                </a>
                                <a href="#reviews-section" class="btn-secondary-hero">
                                    <i class="fa-brands fa-facebook"></i> মেম্বার রিভিউ দেখুন
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Swiper Navigation Arrows & Dots -->
                <div class="swiper-button-prev hero-prev"></div>
                <div class="swiper-button-next hero-next"></div>
                <div class="swiper-pagination hero-pagination"></div>
            </div>
        </div>
    </section>

    <!-- ======================================================================
         Trust Badges Ribbon
         ====================================================================== -->
    <div class="trust-ribbon">
        <div class="container">
            <div class="trust-grid">
                <div class="trust-item">
                    <div class="trust-icon-box">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div class="trust-text">
                        <h4>১০০% নির্ভেজাল গ্যারান্টি</h4>
                        <p>ল্যাব টেস্টে ভেজাল পেলে শতভাগ মূল্য ফেরত</p>
                    </div>
                </div>

                <div class="trust-item">
                    <div class="trust-icon-box">
                        <i class="fa-solid fa-hand-holding-dollar"></i>
                    </div>
                    <div class="trust-text">
                        <h4>ক্যাশ অন হোম ডেলিভারি</h4>
                        <p>পণ্য দেখে বুঝে নিয়ে নিশ্চিত হয়ে মূল্য দিন</p>
                    </div>
                </div>

                <div class="trust-item">
                    <div class="trust-icon-box">
                        <i class="fa-solid fa-truck-fast"></i>
                    </div>
                    <div class="trust-text">
                        <h4>দ্রুততম ডেলিভারি</h4>
                        <p>ঢাকা ও সারা দেশে দ্রুততম সময়ে ডেলিভারি</p>
                    </div>
                </div>

                <div class="trust-item">
                    <div class="trust-icon-box">
                        <i class="fa-solid fa-headset"></i>
                    </div>
                    <div class="trust-text">
                        <h4>সার্বক্ষণিক সাপোর্ট</h4>
                        <p>হটলাইন কল: +8809642922922</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================================
         Featured Category Circles
         ====================================================================== -->
    <section class="category-circles-section">
        <div class="container">
            <div class="section-header">
                <div class="section-title-wrap">
                    <h2><span class="title-bar"></span> Featured Categories</h2>
                    <p>আপনার পরিবারের সুস্বাস্থ্যের নিশ্চয়তায় খাঁটি খাদ্যপণ্য বেছে নিন</p>
                </div>
                <a href="#all-products" class="view-all-link">সবগুলো দেখুন <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="category-circles-grid">
                <a href="#pure-honey" class="category-circle-card">
                    <div class="category-img-wrap">
                        <img src="https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=200&q=80" alt="Pure Honey (মধু)">
                    </div>
                    <div class="category-name">Pure Honey (মধু)</div>
                    <div class="category-items-count"><?= bn_num(count($honeyProducts)) ?> টি খাঁটি আইটেম</div>
                </a>

                <a href="#khejur-gur" class="category-circle-card">
                    <div class="category-img-wrap">
                        <img src="https://images.unsplash.com/photo-1607623814075-e51df1bdc82f?auto=format&fit=crop&w=200&q=80" alt="Khejur Gur & Rosh">
                    </div>
                    <div class="category-name">খেজুর গুড় ও রস</div>
                    <div class="category-items-count"><?= bn_num(count($gurProducts)) ?> টি তাজা আইটেম</div>
                </a>

                <a href="#oil-ghee" class="category-circle-card">
                    <div class="category-img-wrap">
                        <img src="https://images.unsplash.com/photo-1631451095765-2c91616fc9e6?auto=format&fit=crop&w=200&q=80" alt="Pure Ghee">
                    </div>
                    <div class="category-name">খাঁটি গাওয়া ঘি</div>
                    <div class="category-items-count">দানাদার প্রিমিয়াম</div>
                </a>

                <a href="#oil-ghee" class="category-circle-card">
                    <div class="category-img-wrap">
                        <img src="https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?auto=format&fit=crop&w=200&q=80" alt="Mustard Oil">
                    </div>
                    <div class="category-name">সরিষার তেল</div>
                    <div class="category-items-count">কাঠের ঘানি ভাঙা</div>
                </a>

                <a href="#organic-seasonal" class="category-circle-card">
                    <div class="category-img-wrap">
                        <img src="https://images.unsplash.com/photo-1517282009859-f000ec3b26fe?auto=format&fit=crop&w=200&q=80" alt="Organic Papaya">
                    </div>
                    <div class="category-name">পাহাড়ি পেঁপে</div>
                    <div class="category-items-count">প্রাকৃতিক মিষ্টি</div>
                </a>

                <a href="#organic-seasonal" class="category-circle-card">
                    <div class="category-img-wrap">
                        <img src="https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=200&q=80" alt="Organic Pantry">
                    </div>
                    <div class="category-name">অর্গানিক কম্বো</div>
                    <div class="category-items-count">সুপার সেভার প্যাক</div>
                </a>
            </div>
        </div>
    </section>

    <!-- ======================================================================
         Purity & Quality Commitment Section: 100% Pure Organic Food
         ====================================================================== -->
    <section class="campaign-banner-section" id="purity-promise">
        <div class="container">
            <div class="campaign-banner-card">
                <div class="campaign-left">
                    <div class="campaign-pill">
                        <i class="fa-solid fa-shield-halved"></i> শতভাগ বিশুদ্ধতার অঙ্গীকার
                    </div>
                    <h3>খাঁটি ও প্রাকৃতিক খাবারের <span>১০০% বিশ্বস্ত ঠিকানা</span></h3>
                    <p>নাহিদ হানী সরাসরি সুন্দরবনের মৌয়ালদের চাকের মধু, যশোরের গাছিদের খেজুরের রস ও গুড়, এবং নিজস্ব খামারের খাঁটি গাওয়া ঘি সরবরাহ করে। কোনো কেমিক্যাল, চিনি বা কৃত্রিম উপাদান নেই—শুধু বিশুদ্ধ স্বাদ ও সুস্বাস্থ্য।</p>
                    <div class="campaign-steps">
                        <div class="campaign-step"><i class="fa-solid fa-flask-vial"></i> ১০০% ল্যাব টেস্টেড খাঁটি খাদ্য</div>
                        <div class="campaign-step"><i class="fa-solid fa-truck-fast"></i> সারা দেশে ক্যাশ অন হোম ডেলিভারি</div>
                        <div class="campaign-step"><i class="fa-solid fa-rotate-left"></i> পছন্দ না হলে ইনস্ট্যান্ট রিটার্ন</div>
                    </div>
                </div>
                <div class="campaign-right">
                    <a href="#top-selling" class="btn-campaign-cta">
                        <i class="fa-solid fa-basket-shopping"></i> খাঁটি খাবার অর্ডার করুন
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- ======================================================================
         Top Selling Products Section
         - Discount badges, star ratings, dual pricing, Add to Cart & Buy Now
         ====================================================================== -->
    <section class="products-section" id="top-selling">
        <div class="container">
            <div class="section-header">
                <div class="section-title-wrap">
                    <h2><span class="title-bar"></span> Top Selling Products (হট সেলিং পণ্যসমূহ)</h2>
                    <p>গ্রাহকদের সর্বাধিক পছন্দের ও সেরা রেটিংপ্রাপ্ত প্রাকৃতিক খাদ্যপণ্য</p>
                </div>
                <a href="#all-products" class="view-all-link">সমস্ত পণ্য দেখুন <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="products-grid">
                <?php foreach ($topSelling as $product): ?>
                    <div class="product-card">
                        <div class="product-thumb-wrap">
                            <?php if (!empty($product['discount_text'])): ?>
                                <span class="badge-discount"><?= htmlspecialchars($product['discount_text']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($product['badge'])): ?>
                                <span class="badge-tag"><?= htmlspecialchars($product['badge']) ?></span>
                            <?php endif; ?>
                            
                            <button class="wishlist-toggle-btn" data-id="<?= $product['id'] ?>" data-title="<?= htmlspecialchars($product['title']) ?>" aria-label="Add to Wishlist">
                                <i class="fa-regular fa-heart"></i>
                            </button>

                            <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['title']) ?>" loading="lazy">
                        </div>

                        <div class="product-body">
                            <div class="product-category-row">
                                <span class="product-cat-name"><?= htmlspecialchars($product['category_name']) ?></span>
                                <div class="product-rating">
                                    <i class="fa-solid fa-star"></i>
                                    <span><?= number_format($product['rating'], 1) ?></span>
                                    <span class="count">(<?= bn_num($product['reviews_count']) ?>)</span>
                                </div>
                            </div>

                            <h3 class="product-title" title="<?= htmlspecialchars($product['title']) ?>">
                                <?= htmlspecialchars($product['title']) ?>
                            </h3>

                            <div class="product-unit">
                                <i class="fa-solid fa-scale-balanced"></i>
                                <span>পরিমাণ: <?= htmlspecialchars($product['unit']) ?></span>
                            </div>

                            <div class="product-price-row">
                                <span class="current-price">৳<?= bn_num($product['price']) ?></span>
                                <?php if (!empty($product['original_price'])): ?>
                                    <span class="original-price">৳<?= bn_num($product['original_price']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="product-actions-row">
                                <button class="btn-card-add btn-add-to-cart"
                                    data-id="<?= $product['id'] ?>"
                                    data-title="<?= htmlspecialchars($product['title']) ?>"
                                    data-price="<?= $product['price'] ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-image="<?= htmlspecialchars($product['image']) ?>">
                                    <i class="fa-solid fa-cart-shopping"></i> কার্টে রাখুন
                                </button>

                                <button class="btn-card-buy btn-buy-now"
                                    data-id="<?= $product['id'] ?>"
                                    data-title="<?= htmlspecialchars($product['title']) ?>"
                                    data-price="<?= $product['price'] ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-image="<?= htmlspecialchars($product['image']) ?>">
                                    <i class="fa-solid fa-bag-shopping"></i> অর্ডার করুন
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ======================================================================
         Category Shelf 1: Pure Honey (মধু সম্ভার)
         ====================================================================== -->
    <section class="shelf-section" id="pure-honey">
        <div class="container">
            <div class="shelf-header honey-shelf">
                <div class="shelf-header-left">
                    <span class="shelf-icon">🍯</span>
                    <div>
                        <h3>Pure Honey Shelf (খাঁটি মধুর সম্ভার)</h3>
                        <p>সুন্দরবনের প্রাকৃতিক চাকের খলিসা, বুনো ফুল, লিচু, সরিষা ও কালোজিরা ফুলের ১০০% কাঁচা অপরিশোধিত মধু</p>
                    </div>
                </div>
                <a href="#all-products" class="view-all-link">সমস্ত মধু দেখুন <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="products-grid">
                <?php foreach ($honeyProducts as $product): ?>
                    <div class="product-card">
                        <div class="product-thumb-wrap">
                            <?php if (!empty($product['discount_text'])): ?>
                                <span class="badge-discount"><?= htmlspecialchars($product['discount_text']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($product['badge'])): ?>
                                <span class="badge-tag"><?= htmlspecialchars($product['badge']) ?></span>
                            <?php endif; ?>
                            
                            <button class="wishlist-toggle-btn" data-id="<?= $product['id'] ?>" data-title="<?= htmlspecialchars($product['title']) ?>" aria-label="Add to Wishlist">
                                <i class="fa-regular fa-heart"></i>
                            </button>

                            <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['title']) ?>" loading="lazy">
                        </div>

                        <div class="product-body">
                            <div class="product-category-row">
                                <span class="product-cat-name"><?= htmlspecialchars($product['category_name']) ?></span>
                                <div class="product-rating">
                                    <i class="fa-solid fa-star"></i>
                                    <span><?= number_format($product['rating'], 1) ?></span>
                                    <span class="count">(<?= bn_num($product['reviews_count']) ?>)</span>
                                </div>
                            </div>

                            <h3 class="product-title"><?= htmlspecialchars($product['title']) ?></h3>

                            <div class="product-unit">
                                <i class="fa-solid fa-scale-balanced"></i>
                                <span>পরিমাণ: <?= htmlspecialchars($product['unit']) ?></span>
                            </div>

                            <div class="product-price-row">
                                <span class="current-price">৳<?= bn_num($product['price']) ?></span>
                                <?php if (!empty($product['original_price'])): ?>
                                    <span class="original-price">৳<?= bn_num($product['original_price']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="product-actions-row">
                                <button class="btn-card-add btn-add-to-cart"
                                    data-id="<?= $product['id'] ?>"
                                    data-title="<?= htmlspecialchars($product['title']) ?>"
                                    data-price="<?= $product['price'] ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-image="<?= htmlspecialchars($product['image']) ?>">
                                    <i class="fa-solid fa-cart-shopping"></i> কার্টে রাখুন
                                </button>

                                <button class="btn-card-buy btn-buy-now"
                                    data-id="<?= $product['id'] ?>"
                                    data-title="<?= htmlspecialchars($product['title']) ?>"
                                    data-price="<?= $product['price'] ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-image="<?= htmlspecialchars($product['image']) ?>">
                                    <i class="fa-solid fa-bag-shopping"></i> অর্ডার করুন
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ======================================================================
         Category Shelf 2: Winter Khejur Gur & Rosh (শীতের তাজা খেজুর গুড় ও রস)
         ====================================================================== -->
    <section class="shelf-section" id="khejur-gur">
        <div class="container">
            <div class="shelf-header gur-shelf">
                <div class="shelf-header-left">
                    <span class="shelf-icon">🌴</span>
                    <div>
                        <h3>Winter Khejur Gur & Rosh Shelf (শীতের তাজা খেজুর গুড় ও রস)</h3>
                        <p>যশোরের ঐতিহ্যবাহী গাছিদের মাটির হাঁড়ির তাজা রস এবং চিনিমুক্ত সুবাসিত খাঁটি নলেন ঝোলা ও দানাদার পাটালি</p>
                    </div>
                </div>
                <a href="#all-products" class="view-all-link">সমস্ত গুড় দেখুন <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="products-grid">
                <?php foreach ($gurProducts as $product): ?>
                    <div class="product-card">
                        <div class="product-thumb-wrap">
                            <?php if (!empty($product['discount_text'])): ?>
                                <span class="badge-discount"><?= htmlspecialchars($product['discount_text']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($product['badge'])): ?>
                                <span class="badge-tag"><?= htmlspecialchars($product['badge']) ?></span>
                            <?php endif; ?>
                            
                            <button class="wishlist-toggle-btn" data-id="<?= $product['id'] ?>" data-title="<?= htmlspecialchars($product['title']) ?>" aria-label="Add to Wishlist">
                                <i class="fa-regular fa-heart"></i>
                            </button>

                            <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['title']) ?>" loading="lazy">
                        </div>

                        <div class="product-body">
                            <div class="product-category-row">
                                <span class="product-cat-name"><?= htmlspecialchars($product['category_name']) ?></span>
                                <div class="product-rating">
                                    <i class="fa-solid fa-star"></i>
                                    <span><?= number_format($product['rating'], 1) ?></span>
                                    <span class="count">(<?= bn_num($product['reviews_count']) ?>)</span>
                                </div>
                            </div>

                            <h3 class="product-title"><?= htmlspecialchars($product['title']) ?></h3>

                            <div class="product-unit">
                                <i class="fa-solid fa-scale-balanced"></i>
                                <span>পরিমাণ: <?= htmlspecialchars($product['unit']) ?></span>
                            </div>

                            <div class="product-price-row">
                                <span class="current-price">৳<?= bn_num($product['price']) ?></span>
                                <?php if (!empty($product['original_price'])): ?>
                                    <span class="original-price">৳<?= bn_num($product['original_price']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="product-actions-row">
                                <button class="btn-card-add btn-add-to-cart"
                                    data-id="<?= $product['id'] ?>"
                                    data-title="<?= htmlspecialchars($product['title']) ?>"
                                    data-price="<?= $product['price'] ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-image="<?= htmlspecialchars($product['image']) ?>">
                                    <i class="fa-solid fa-cart-shopping"></i> কার্টে রাখুন
                                </button>

                                <button class="btn-card-buy btn-buy-now"
                                    data-id="<?= $product['id'] ?>"
                                    data-title="<?= htmlspecialchars($product['title']) ?>"
                                    data-price="<?= $product['price'] ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-image="<?= htmlspecialchars($product['image']) ?>">
                                    <i class="fa-solid fa-bag-shopping"></i> অর্ডার করুন
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ======================================================================
         Category Shelf 3: Organic Pantry (ঘি, সরিষার তেল ও পাহাড়ি পেঁপে)
         ====================================================================== -->
    <section class="shelf-section" id="oil-ghee">
        <div class="container">
            <div class="shelf-header organic-shelf">
                <div class="shelf-header-left">
                    <span class="shelf-icon">🌿</span>
                    <div>
                        <h3>Organic Pantry (ঘি, সরিষার তেল ও অর্গানিক আইটেম)</h3>
                        <p>গ্রামের খামারের আসল গাওয়া ঘি, কাঠের ঘানি ভাঙা খাঁটি সরিষার তেল এবং পাহাড়ি মিষ্টি পেঁপে</p>
                    </div>
                </div>
                <a href="#all-products" class="view-all-link">সমস্ত প্যান্ট্রি পণ্য <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="products-grid">
                <?php foreach ($organicProducts as $product): ?>
                    <div class="product-card">
                        <div class="product-thumb-wrap">
                            <?php if (!empty($product['discount_text'])): ?>
                                <span class="badge-discount"><?= htmlspecialchars($product['discount_text']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($product['badge'])): ?>
                                <span class="badge-tag"><?= htmlspecialchars($product['badge']) ?></span>
                            <?php endif; ?>
                            
                            <button class="wishlist-toggle-btn" data-id="<?= $product['id'] ?>" data-title="<?= htmlspecialchars($product['title']) ?>" aria-label="Add to Wishlist">
                                <i class="fa-regular fa-heart"></i>
                            </button>

                            <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['title']) ?>" loading="lazy">
                        </div>

                        <div class="product-body">
                            <div class="product-category-row">
                                <span class="product-cat-name"><?= htmlspecialchars($product['category_name']) ?></span>
                                <div class="product-rating">
                                    <i class="fa-solid fa-star"></i>
                                    <span><?= number_format($product['rating'], 1) ?></span>
                                    <span class="count">(<?= bn_num($product['reviews_count']) ?>)</span>
                                </div>
                            </div>

                            <h3 class="product-title"><?= htmlspecialchars($product['title']) ?></h3>

                            <div class="product-unit">
                                <i class="fa-solid fa-scale-balanced"></i>
                                <span>পরিমাণ: <?= htmlspecialchars($product['unit']) ?></span>
                            </div>

                            <div class="product-price-row">
                                <span class="current-price">৳<?= bn_num($product['price']) ?></span>
                                <?php if (!empty($product['original_price'])): ?>
                                    <span class="original-price">৳<?= bn_num($product['original_price']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="product-actions-row">
                                <button class="btn-card-add btn-add-to-cart"
                                    data-id="<?= $product['id'] ?>"
                                    data-title="<?= htmlspecialchars($product['title']) ?>"
                                    data-price="<?= $product['price'] ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-image="<?= htmlspecialchars($product['image']) ?>">
                                    <i class="fa-solid fa-cart-shopping"></i> কার্টে রাখুন
                                </button>

                                <button class="btn-card-buy btn-buy-now"
                                    data-id="<?= $product['id'] ?>"
                                    data-title="<?= htmlspecialchars($product['title']) ?>"
                                    data-price="<?= $product['price'] ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-image="<?= htmlspecialchars($product['image']) ?>">
                                    <i class="fa-solid fa-bag-shopping"></i> অর্ডার করুন
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ======================================================================
         Verified Facebook Group Customer Reviews Carousel (Swiper.js)
         ====================================================================== -->
    <section class="reviews-section" id="reviews-section">
        <div class="container">
            <div class="section-header">
                <div class="section-title-wrap">
                    <h2><span class="title-bar"></span> Verified Facebook Group Customer Reviews</h2>
                    <p>নাহিদ হানী অফিসিয়াল ফেসবুক গ্রুপের গ্রাহক মেম্বারদের সরাসরি মতামত ও বিশ্বস্ততার প্রমাণ</p>
                </div>
                <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" class="view-all-link">
                    <i class="fa-brands fa-facebook"></i> গ্রুপে ভিজিট করুন <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <div class="swiper reviews-swiper">
                <div class="swiper-wrapper">
                    <?php foreach ($reviews as $review): ?>
                        <div class="swiper-slide">
                            <div class="review-card">
                                <div class="review-header">
                                    <img src="<?= htmlspecialchars($review['avatar']) ?>" alt="<?= htmlspecialchars($review['customer_name']) ?>" class="review-avatar">
                                    <div class="review-meta">
                                        <h4><?= htmlspecialchars($review['customer_name']) ?></h4>
                                        <div class="fb-badge">
                                            <i class="fa-brands fa-facebook"></i>
                                            <span><?= htmlspecialchars($review['badge']) ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="review-stars-row">
                                    <div class="review-stars">
                                        <?php for ($s = 0; $s < $review['rating']; $s++): ?>
                                            <i class="fa-solid fa-star"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="review-date"><?= htmlspecialchars($review['date']) ?></span>
                                </div>

                                <p class="review-text">"<?= htmlspecialchars($review['comment']) ?>"</p>

                                <div class="review-product-tag">
                                    <i class="fa-solid fa-circle-check" style="color: #15803d;"></i>
                                    <span>ক্রয়কৃত পণ্য: <b><?= htmlspecialchars($review['product_name']) ?></b></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="swiper-pagination reviews-pagination" style="margin-top: 24px; position: relative;"></div>
            </div>

            <div class="reviews-cta-wrap">
                <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" class="btn-fb-group-join">
                    <i class="fa-brands fa-facebook"></i>
                    <span>আমাদের অফিসিয়াল ফেসবুক কমিউনিটি গ্রুপে যুক্ত হোন</span>
                </a>
            </div>
        </div>
    </section>

    <!-- ======================================================================
         Comprehensive Footer
         ====================================================================== -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-main-grid">
                <!-- Col 1: About Nahid Honey & Telegram CDN Engine -->
                <div class="footer-col footer-about">
                    <div class="footer-about-logo">
                        <div class="logo-icon-box" style="width: 40px; height: 40px; font-size: 1.2rem;">
                            <i class="fa-solid fa-jar"></i>
                        </div>
                        <h3>নাহিদ হানী <span>- Nahid Honey</span></h3>
                    </div>
                    <p>নাহিদ হানী বাংলাদেশের একটি বিশ্বস্ত ন্যাচারাল ফুড প্রতিষ্ঠান। সুন্দরবনের প্রাকৃতিক চাকের কাঁচা মধু, যশোরের ঐতিহ্যবাহী খেজুর গুড় ও রস, খাঁটি গাওয়া ঘি ও ঘানি ভাঙা সরিষার তেল আমরা সম্পূর্ণ ভেজালমুক্তভাবে সারাদেশে ঘরে ঘরে পৌঁছে দিচ্ছি।</p>
                    
                    <div class="telegram-cdn-badge">
                        <i class="fa-brands fa-telegram"></i>
                        <div>
                            <strong>Powered by Telegram Cloud Database & High-Speed CDN</strong>
                            <p style="margin: 0; font-size: 0.72rem; color: #94a3b8;">Channel Engine: -1004372641845 | Instant Cloud Storage</p>
                        </div>
                    </div>
                </div>

                <!-- Col 2: Quick Links -->
                <div class="footer-col">
                    <h4>প্রয়োজনীয় লিংক</h4>
                    <ul class="footer-links-list">
                        <li><a href="#all-products"><i class="fa-solid fa-angle-right"></i> All Products (সব পণ্য)</a></li>
                        <li><a href="#pure-honey"><i class="fa-solid fa-angle-right"></i> Pure Honey (মধু)</a></li>
                        <li><a href="#khejur-gur"><i class="fa-solid fa-angle-right"></i> Khejur Gur & Rosh (খেজুর গুড়)</a></li>
                        <li><a href="#oil-ghee"><i class="fa-solid fa-angle-right"></i> Pure Ghee & Mustard Oil</a></li>
                        <li><a href="#purity-promise"><i class="fa-solid fa-angle-right"></i> শতভাগ খাঁটি খাবারের নিশ্চয়তা</a></li>
                    </ul>
                </div>

                <!-- Col 3: Customer Service & Policies -->
                <div class="footer-col">
                    <h4>পলিসি ও কাস্টমার কেয়ার</h4>
                    <ul class="footer-links-list">
                        <li><a href="track-order.php"><i class="fa-solid fa-angle-right"></i> Track Order (অর্ডার ট্র্যাক)</a></li>
                        <li><a href="#"><i class="fa-solid fa-angle-right"></i> রিটার্ন ও রিফান্ড পলিসি</a></li>
                        <li><a href="#"><i class="fa-solid fa-angle-right"></i> প্রাইভেসি পলিসি</a></li>
                        <li><a href="#"><i class="fa-solid fa-angle-right"></i> ডেলিভারি ও পেমেন্ট শর্তাবলী</a></li>
                        <li><a href="#"><i class="fa-solid fa-angle-right"></i> সচরাচর জিজ্ঞাসা (FAQ)</a></li>
                    </ul>
                </div>

                <!-- Col 4: Contact & Hotline -->
                <div class="footer-col">
                    <h4>যোগাযোগ ও ঠিকানা</h4>
                    <ul class="footer-contact-items">
                        <li class="footer-contact-item">
                            <i class="fa-solid fa-phone-volume"></i>
                            <div>
                                <strong>কাস্টমার সার্ভিস হটলাইন:</strong>
                                <p><a href="tel:+8809642922922" style="color: #facc15; font-weight: 700;">+8809642922922</a></p>
                            </div>
                        </li>
                        <li class="footer-contact-item">
                            <i class="fa-brands fa-whatsapp"></i>
                            <div>
                                <strong>হোয়াটসঅ্যাপ সাপোর্ট:</strong>
                                <p><a href="https://wa.me/8809642922922" target="_blank" rel="noopener noreferrer" style="color: #4ade80; font-weight: 600;">+8809642922922</a></p>
                            </div>
                        </li>
                        <li class="footer-contact-item">
                            <i class="fa-solid fa-location-dot"></i>
                            <div>
                                <strong>প্রধান কার্যালয়:</strong>
                                <p>বাড়ি-২৪, রোড-০৫, সেক্টর-১১, উত্তরা, ঢাকা-১২৩০</p>
                            </div>
                        </li>
                    </ul>

                    <div class="footer-social-row">
                        <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" class="social-circle" title="Facebook Page"><i class="fa-brands fa-facebook-f"></i></a>
                        <a href="https://t.me/+T16Rq2v7cRFiNzU1" target="_blank" rel="noopener noreferrer" class="social-circle" title="Telegram Channel"><i class="fa-brands fa-telegram"></i></a>
                        <a href="https://youtube.com" target="_blank" rel="noopener noreferrer" class="social-circle" title="YouTube"><i class="fa-brands fa-youtube"></i></a>
                        <a href="https://instagram.com" target="_blank" rel="noopener noreferrer" class="social-circle" title="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    </div>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="footer-bottom">
                <div>
                    &copy; <?= date('Y') ?> <b>নাহিদ হানী (Nahid Honey)</b>। সর্বস্বত্ব সংরক্ষিত। Powered by Telegram Database Engine & High-Speed Edge CDN.
                </div>
                <div class="footer-pay-badges">
                    <span class="pay-badge-pill"><i class="fa-solid fa-truck-moving"></i> সারা দেশে ক্যাশ অন হোম ডেলিভারি</span>
                    <span class="pay-badge-pill"><i class="fa-solid fa-mobile-screen-button"></i> বিকাশ</span>
                    <span class="pay-badge-pill"><i class="fa-solid fa-money-bill-transfer"></i> নগদ</span>
                    <span class="pay-badge-pill"><i class="fa-solid fa-shield-halved"></i> ১০০% নিরাপদ চেকআউট</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- ======================================================================
         Slide-out Cart Drawer
         ====================================================================== -->
    <div class="cart-drawer-overlay" id="cartDrawerOverlay"></div>
    <div class="cart-drawer" id="cartDrawer">
        <div class="cart-drawer-header">
            <h3><i class="fa-solid fa-basket-shopping" style="color: #15803d;"></i> আপনার শপিং ব্যাগ (<span id="drawerCartCount">0</span>)</h3>
            <button class="cart-close-btn" id="closeCartDrawerBtn" aria-label="Close Cart">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="cart-free-shipping-bar">
            <i class="fa-solid fa-truck-fast"></i>
            <span>🚚 সারা বাংলাদেশে ক্যাশ অন হোম ডেলিভারি সুবিধা!</span>
        </div>

        <div class="cart-items-list" id="cartItemsList">
            <!-- Populated via jQuery from localStorage -->
        </div>

        <div class="cart-drawer-footer">
            <div class="cart-subtotal-row">
                <span>মোট পণ্যের মূল্য:</span>
                <span class="subtotal-val" id="drawerSubtotal">৳০</span>
            </div>
            <button class="btn-checkout-drawer" id="drawerCheckoutBtn">
                <i class="fa-solid fa-lock"></i> নিরাপদ চেকআউট করুন (Buy Now)
            </button>
            <button class="btn-continue-shopping" id="continueShoppingBtn">
                আরও কেনাকাটা করুন <i class="fa-solid fa-arrow-right"></i>
            </button>
        </div>
    </div>

    <!-- ======================================================================
         Floating Action Buttons (WhatsApp & Facebook Group)
         ====================================================================== -->
    <div class="floating-actions-container">
        <!-- Floating WhatsApp with prefilled message -->
        <a href="https://wa.me/8809642922922?text=<?= urlencode('আসসালামু আলাইকুম, আমি নাহিদ হানী থেকে খাঁটি পণ্য অর্ডার করতে চাই।') ?>" target="_blank" rel="noopener noreferrer" class="floating-btn floating-whatsapp" aria-label="WhatsApp Support">
            <i class="fa-brands fa-whatsapp"></i>
            <span class="floating-tooltip">হোয়াটসঅ্যাপ চ্যাট (+8809642922922)</span>
        </a>

        <!-- Floating Facebook Group Community -->
        <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" class="floating-btn floating-facebook" aria-label="Facebook Community Group">
            <i class="fa-brands fa-facebook-f"></i>
            <span class="floating-tooltip">ফেসবুক গ্রুপে যোগ দিন</span>
        </a>

        <!-- Back to Top Button -->
        <button class="floating-btn floating-backtotop" id="backToTopBtn" aria-label="Scroll to Top">
            <i class="fa-solid fa-arrow-up"></i>
            <span class="floating-tooltip">উপরে যান</span>
        </button>
    </div>

    <!-- ======================================================================
         Scripts & Dynamic Data Bridge
         ====================================================================== -->
    <!-- Pass PHP Normalized Products Collection to JavaScript for Instant AJAX Search -->
    <script>
        window.siteProducts = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <!-- jQuery, Swiper & Toastr JS -->
    <script src="assets/js/jquery.min.js"></script>
    <script src="assets/js/swiper-bundle.min.js"></script>
    <script src="assets/js/toastr.min.js"></script>

    <!-- Application Script -->
    <script src="assets/js/app.js"></script>

</body>
</html>
