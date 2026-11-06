/**
 * Nahid Honey - Front-end Application Script
 * Powered by jQuery, Swiper.js & Toastr
 */

$(document).ready(function () {
  // Configure Toastr options
  toastr.options = {
    closeButton: true,
    debug: false,
    newestOnTop: true,
    progressBar: true,
    positionClass: 'toast-top-right',
    preventDuplicates: false,
    showDuration: '300',
    hideDuration: '1000',
    timeOut: '3000',
    extendedTimeOut: '1000',
    showEasing: 'swing',
    hideEasing: 'linear',
    showMethod: 'fadeIn',
    hideMethod: 'fadeOut'
  };

  // ==========================================================================
  // 1. Swiper Sliders Initialization
  // ==========================================================================

  // Hero Slider
  if ($('.hero-swiper').length) {
    const heroSwiper = new Swiper('.hero-swiper', {
      loop: true,
      autoplay: {
        delay: 5000,
        disableOnInteraction: false
      },
      speed: 800,
      effect: 'fade',
      fadeEffect: {
        crossFade: true
      },
      pagination: {
        el: '.hero-pagination',
        clickable: true
      },
      navigation: {
        nextEl: '.hero-next',
        prevEl: '.hero-prev'
      }
    });
  }

  // Reviews Slider
  if ($('.reviews-swiper').length) {
    const reviewsSwiper = new Swiper('.reviews-swiper', {
      loop: true,
      autoplay: {
        delay: 4500,
        disableOnInteraction: false
      },
      speed: 600,
      spaceBetween: 24,
      slidesPerView: 1,
      pagination: {
        el: '.reviews-pagination',
        clickable: true
      },
      breakpoints: {
        640: {
          slidesPerView: 1
        },
        768: {
          slidesPerView: 2
        },
        1024: {
          slidesPerView: 3
        }
      }
    });
  }

  // ==========================================================================
  // 2. Cart Management with LocalStorage
  // ==========================================================================

  const CART_STORAGE_KEY = 'nahid_honey_cart_items';
  const WISHLIST_STORAGE_KEY = 'nahid_honey_wishlist_items';

  function getCart() {
    try {
      const data = localStorage.getItem(CART_STORAGE_KEY);
      return data ? JSON.parse(data) : [];
    } catch (e) {
      return [];
    }
  }

  function saveCart(cart) {
    try {
      localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(cart));
      updateCartUI();
    } catch (e) {
      console.error('Error saving cart to localStorage', e);
    }
  }

  function updateCartUI() {
    const cart = getCart();
    let totalQty = 0;
    let subtotal = 0;
    const $cartList = $('#cartItemsList');
    $cartList.empty();

    if (cart.length === 0) {
      $cartList.html(`
        <div class="cart-empty-message">
          <i class="fa-solid fa-basket-shopping"></i>
          <h4>আপনার শপিং ব্যাগটি খালি</h4>
          <p>পছন্দের খাঁটি পণ্যগুলো ব্যাগে যুক্ত করুন</p>
        </div>
      `);
      $('.btn-checkout-drawer').prop('disabled', true).css('opacity', '0.6');
    } else {
      $('.btn-checkout-drawer').prop('disabled', false).css('opacity', '1');

      cart.forEach((item) => {
        totalQty += item.qty;
        const itemTotal = item.price * item.qty;
        subtotal += itemTotal;

        const rowHtml = `
          <div class="cart-item-row" data-id="${item.id}">
            <img src="${item.image}" alt="${item.title}" class="cart-item-thumb">
            <div class="cart-item-details">
              <h4 class="cart-item-title">${item.title}</h4>
              <div class="cart-item-price-unit">৳${Number(item.price).toLocaleString('bn-BD')} (${item.unit || '১ পিস'})</div>
              <div class="cart-qty-spinner">
                <button class="qty-btn btn-cart-dec" data-id="${item.id}">-</button>
                <span class="qty-display">${item.qty}</span>
                <button class="qty-btn btn-cart-inc" data-id="${item.id}">+</button>
              </div>
            </div>
            <button class="cart-item-remove" data-id="${item.id}" title="পণ্য সরান">
              <i class="fa-regular fa-trash-can"></i>
            </button>
          </div>
        `;
        $cartList.append(rowHtml);
      });
    }

    // Update Badges & Subtotal
    $('#headerCartCount, #drawerCartCount').text(totalQty);
    $('#headerCartTotal').text('৳' + Number(subtotal).toLocaleString('bn-BD'));
    $('#drawerSubtotal').text('৳' + Number(subtotal).toLocaleString('bn-BD'));
  }

  // Open & Close Drawer
  function openCartDrawer() {
    $('#cartDrawerOverlay').addClass('open');
    $('#cartDrawer').addClass('open');
    $('body').css('overflow', 'hidden');
  }

  function closeCartDrawer() {
    $('#cartDrawerOverlay').removeClass('open');
    $('#cartDrawer').removeClass('open');
    $('body').css('overflow', '');
  }

  $('#openCartDrawerBtn, #mobileCartBtn').on('click', function (e) {
    e.preventDefault();
    openCartDrawer();
  });

  $('#closeCartDrawerBtn, #cartDrawerOverlay, #continueShoppingBtn').on('click', function (e) {
    e.preventDefault();
    closeCartDrawer();
  });

  // Add to Cart Event
  $(document).on('click', '.btn-add-to-cart', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const title = $(this).data('title');
    const price = parseFloat($(this).data('price'));
    const unit = $(this).data('unit');
    const image = $(this).data('image');

    const cart = getCart();
    const existingIndex = cart.findIndex(item => item.id == id);

    if (existingIndex > -1) {
      cart[existingIndex].qty += 1;
    } else {
      cart.push({
        id: id,
        title: title,
        price: price,
        unit: unit,
        image: image,
        qty: 1
      });
    }

    saveCart(cart);
    toastr.success(`<b>${title}</b> শপিং ব্যাগে যুক্ত হয়েছে!`, 'কার্টে যুক্ত সফল');
    openCartDrawer();
  });

  // Buy Now Event
  $(document).on('click', '.btn-buy-now', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const title = $(this).data('title');
    const price = parseFloat($(this).data('price'));
    const unit = $(this).data('unit');
    const image = $(this).data('image');

    const cart = getCart();
    const existingIndex = cart.findIndex(item => item.id == id);

    if (existingIndex > -1) {
      cart[existingIndex].qty += 1;
    } else {
      cart.push({
        id: id,
        title: title,
        price: price,
        unit: unit,
        image: image,
        qty: 1
      });
    }

    saveCart(cart);
    window.location.href = 'checkout.php';
  });

  // Checkout Button from Drawer
  $('#drawerCheckoutBtn').on('click', function (e) {
    e.preventDefault();
    const cart = getCart();
    if (cart.length > 0) {
      window.location.href = 'checkout.php';
    } else {
      toastr.warning('আপনার কার্টে কোনো পণ্য নেই!', 'কার্ট খালি');
    }
  });

  // Quantity Increment
  $(document).on('click', '.btn-cart-inc', function () {
    const id = $(this).data('id');
    const cart = getCart();
    const item = cart.find(i => i.id == id);
    if (item) {
      item.qty += 1;
      saveCart(cart);
    }
  });

  // Quantity Decrement
  $(document).on('click', '.btn-cart-dec', function () {
    const id = $(this).data('id');
    let cart = getCart();
    const item = cart.find(i => i.id == id);
    if (item) {
      if (item.qty > 1) {
        item.qty -= 1;
      } else {
        cart = cart.filter(i => i.id != id);
        toastr.info('পণ্যটি কার্ট থেকে সরানো হয়েছে।');
      }
      saveCart(cart);
    }
  });

  // Remove Item
  $(document).on('click', '.cart-item-remove', function () {
    const id = $(this).data('id');
    let cart = getCart();
    cart = cart.filter(i => i.id != id);
    saveCart(cart);
    toastr.info('পণ্যটি কার্ট থেকে সরানো হয়েছে।');
  });

  // ==========================================================================
  // 3. Wishlist Management
  // ==========================================================================

  function getWishlist() {
    try {
      const data = localStorage.getItem(WISHLIST_STORAGE_KEY);
      return data ? JSON.parse(data) : [];
    } catch (e) {
      return [];
    }
  }

  function saveWishlist(wishlist) {
    try {
      localStorage.setItem(WISHLIST_STORAGE_KEY, JSON.stringify(wishlist));
      updateWishlistUI();
    } catch (e) {
      console.error('Error saving wishlist', e);
    }
  }

  function updateWishlistUI() {
    const wishlist = getWishlist();
    $('#headerWishlistCount').text(wishlist.length);

    $('.wishlist-toggle-btn').each(function () {
      const id = $(this).data('id');
      if (wishlist.includes(id)) {
        $(this).addClass('active').html('<i class="fa-solid fa-heart"></i>');
      } else {
        $(this).removeClass('active').html('<i class="fa-regular fa-heart"></i>');
      }
    });
  }

  $(document).on('click', '.wishlist-toggle-btn, #headerWishlistBtn', function (e) {
    if ($(this).is('#headerWishlistBtn')) {
      e.preventDefault();
      const wishlist = getWishlist();
      toastr.info(`আপনার পছন্দের তালিকায় ${wishlist.length}টি পণ্য রয়েছে।`, 'Wishlist');
      return;
    }

    e.preventDefault();
    const id = $(this).data('id');
    const title = $(this).data('title');
    let wishlist = getWishlist();

    if (wishlist.includes(id)) {
      wishlist = wishlist.filter(itemId => itemId !== id);
      saveWishlist(wishlist);
      toastr.info(`<b>${title}</b> উইশলিস্ট থেকে সরানো হয়েছে।`);
    } else {
      wishlist.push(id);
      saveWishlist(wishlist);
      toastr.success(`<b>${title}</b> আপনার পছন্দের তালিকায় সংরক্ষিত হয়েছে!`, 'উইশলিস্টে যুক্ত');
    }
  });

  // ==========================================================================
  // 4. Instant Search AJAX / Live Filter
  // ==========================================================================

  const $searchInput = $('#searchInput');
  const $searchDropdown = $('#searchResultsDropdown');

  function renderSearchResults(results) {
    $searchDropdown.empty();

    if (results && results.length > 0) {
      results.slice(0, 8).forEach(product => {
        const itemHtml = `
          <div class="search-item" data-id="${product.id}">
            <img src="${product.image}" alt="${product.title}" class="search-item-img">
            <div class="search-item-info">
              <div class="search-item-title">${product.title}</div>
              <div class="search-item-price">
                ৳${Number(product.price).toLocaleString('bn-BD')}
                ${product.original_price ? `<del>৳${Number(product.original_price).toLocaleString('bn-BD')}</del>` : ''}
              </div>
            </div>
            <button class="btn-card-add btn-add-to-cart" 
              data-id="${product.id}" 
              data-title="${product.title}" 
              data-price="${product.price}" 
              data-unit="${product.unit}" 
              data-image="${product.image}"
              style="padding: 4px 10px; font-size: 0.75rem;">
              + কার্ট
            </button>
          </div>
        `;
        $searchDropdown.append(itemHtml);
      });
      $searchDropdown.addClass('active');
    } else {
      $searchDropdown.html(`
        <div style="padding: 18px; text-align: center; color: #64748b; font-size: 0.88rem;">
          "<b>${$searchInput.val()}</b>" সম্পর্কিত কোনো পণ্য পাওয়া যায়নি।
        </div>
      `).addClass('active');
    }
  }

  $searchInput.on('input focus', function () {
    const query = $(this).val().trim().toLowerCase();

    if (query.length === 0) {
      $searchDropdown.removeClass('active').empty();
      return;
    }

    if (typeof window.siteProducts !== 'undefined' && Array.isArray(window.siteProducts) && window.siteProducts.length > 0) {
      const results = window.siteProducts.filter(item => {
        const titleMatch = (item.title || '').toLowerCase().includes(query);
        const catMatch = (item.category_name || '').toLowerCase().includes(query);
        const descMatch = (item.description || '').toLowerCase().includes(query);
        return titleMatch || catMatch || descMatch;
      });
      renderSearchResults(results);
    } else {
      $.getJSON('api/search.php', { q: query }, function(res) {
        if (res && res.success) {
          renderSearchResults(res.results);
        }
      });
    }
  });

  // Hide dropdown when clicking outside
  $(document).on('click', function (e) {
    if (!$(e.target).closest('.header-search').length) {
      $searchDropdown.removeClass('active');
    }
  });

  // ==========================================================================
  // 5. Scroll & Floating Buttons
  // ==========================================================================

  $(window).on('scroll', function () {
    if ($(window).scrollTop() > 300) {
      $('#backToTopBtn').addClass('visible');
    } else {
      $('#backToTopBtn').removeClass('visible');
    }
  });

  $('#backToTopBtn').on('click', function (e) {
    e.preventDefault();
    $('html, body').animate({ scrollTop: 0 }, 500);
  });

  // Initialize UI on page load
  updateCartUI();
  updateWishlistUI();
});
