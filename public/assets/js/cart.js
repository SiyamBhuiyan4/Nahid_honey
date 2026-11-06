/**
 * Nahid Honey - Modern E-Commerce Cart & Order State Manager
 * Adheres to Ghorer Bazar Design Patterns & style.css Specifications
 * Powered by localStorage, dynamic slide-out minicart drawer,
 * Toastr notifications, and instant checkout handling.
 */

(function (window, document, $) {
  'use strict';

  var STORAGE_KEY = 'nahid_honey_cart';

  var Cart = {
    items: [],

    /**
     * Initialize Cart State, Event Listeners, and Drawer DOM
     */
    init: function () {
      this.load();
      this.ensureDrawerDOM();
      this.renderDrawer();
      this.updateBadges();
      this.bindEvents();
      this.initToastr();
      console.log('Nahid Honey Cart initialized. Items count:', this.getCount());
    },

    /**
     * Configure Toastr default options if library is loaded
     */
    initToastr: function () {
      if (typeof toastr !== 'undefined') {
        toastr.options = {
          closeButton: true,
          debug: false,
          newestOnTop: true,
          progressBar: true,
          positionClass: 'toast-bottom-right',
          preventDuplicates: false,
          onclick: null,
          showDuration: '300',
          hideDuration: '600',
          timeOut: '3200',
          extendedTimeOut: '1000',
          showEasing: 'swing',
          hideEasing: 'linear',
          showMethod: 'fadeIn',
          hideMethod: 'fadeOut'
        };
      }
    },

    /**
     * Show toast message safely with Toastr or console fallback
     */
    notify: function (type, message, title) {
      if (typeof toastr !== 'undefined' && typeof toastr[type] === 'function') {
        toastr[type](message, title || '');
      } else {
        console.log('[Toast ' + type + ']: ' + message);
      }
    },

    /**
     * Load items from localStorage
     */
    load: function () {
      try {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
          var parsed = JSON.parse(saved);
          if (Array.isArray(parsed)) {
            this.items = parsed.map(function (item) {
              return {
                id: String(item.id || ''),
                title: String(item.title || 'পণ্য'),
                price: parseFloat(item.price) || 0,
                original_price: item.original_price ? parseFloat(item.original_price) : null,
                image: item.image || 'https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=600&q=80',
                unit: item.unit || item.weight || '',
                weight: item.weight || item.unit || '',
                category: item.category || '',
                url: item.url || '#',
                quantity: parseInt(item.quantity, 10) > 0 ? parseInt(item.quantity, 10) : 1
              };
            }).filter(function (item) {
              return item.id !== '';
            });
          } else {
            this.items = [];
          }
        } else {
          this.items = [];
        }
      } catch (e) {
        console.error('Error loading cart from localStorage:', e);
        this.items = [];
      }
    },

    /**
     * Persist items to localStorage
     */
    save: function () {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(this.items));
      } catch (e) {
        console.error('Error saving cart to localStorage:', e);
      }
      this.dispatchUpdateEvent();
    },

    /**
     * Dispatch custom cart:updated event to document and jQuery
     */
    dispatchUpdateEvent: function (extra) {
      var eventData = {
        items: this.items,
        count: this.getCount(),
        total: this.getTotal(),
        extra: extra || {}
      };

      if (typeof CustomEvent === 'function') {
        var event = new CustomEvent('cart:updated', { detail: eventData });
        document.dispatchEvent(event);
      }

      if (window.jQuery) {
        $(document).trigger('cart:updated', [eventData]);
      }
    },

    /**
     * Get all items in cart
     */
    getItems: function () {
      return this.items;
    },

    /**
     * Get total quantity of items in cart
     */
    getCount: function () {
      return this.items.reduce(function (acc, item) {
        return acc + (parseInt(item.quantity, 10) || 0);
      }, 0);
    },

    /**
     * Get total price of items in cart
     */
    getTotal: function () {
      return this.items.reduce(function (acc, item) {
        return acc + ((parseFloat(item.price) || 0) * (parseInt(item.quantity, 10) || 0));
      }, 0);
    },

    /**
     * Format number as BDT currency string (e.g. ৳১,৫০০ or ৳1,500)
     */
    formatPrice: function (amount) {
      var num = Math.round(Number(amount) || 0);
      return '৳' + num.toLocaleString('en-US');
    },

    /**
     * Add product to cart or increment quantity
     * @param {Object} product - Product details { id, title, price, ... }
     * @param {number} qty - Quantity to add (default 1)
     * @param {boolean} showNotification - Whether to trigger toastr (default true)
     * @param {boolean} openDrawer - Whether to slide-out cart drawer (default true)
     */
    addItem: function (product, qty, showNotification, openDrawer) {
      if (!product || !product.id) {
        console.warn('Cannot add invalid product to cart:', product);
        return false;
      }

      var quantityToAdd = parseInt(qty, 10);
      if (isNaN(quantityToAdd) || quantityToAdd <= 0) {
        quantityToAdd = 1;
      }

      var id = String(product.id);
      var price = parseFloat(product.price);
      if (isNaN(price)) {
        price = 0;
      }

      var existingIndex = -1;
      for (var i = 0; i < this.items.length; i++) {
        if (String(this.items[i].id) === id) {
          existingIndex = i;
          break;
        }
      }

      if (existingIndex > -1) {
        this.items[existingIndex].quantity += quantityToAdd;
        if (product.price) this.items[existingIndex].price = price;
        if (product.title) this.items[existingIndex].title = product.title;
        if (product.image) this.items[existingIndex].image = product.image;
        if (product.unit || product.weight) {
          this.items[existingIndex].unit = product.unit || product.weight;
          this.items[existingIndex].weight = product.unit || product.weight;
        }
      } else {
        this.items.push({
          id: id,
          title: product.title || 'খাঁটি প্রাকৃতিক মধু',
          price: price,
          original_price: product.original_price ? parseFloat(product.original_price) : null,
          image: product.image || 'https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=600&q=80',
          unit: product.unit || product.weight || '',
          weight: product.weight || product.unit || '',
          category: product.category || '',
          url: product.url || '#',
          quantity: quantityToAdd
        });
      }

      this.save();
      this.renderDrawer();
      this.updateBadges();

      if (showNotification !== false) {
        var itemTitle = product.title || 'পণ্যটি';
        this.notify('success', '<strong>' + this.escapeHtml(itemTitle) + '</strong> কার্টে যুক্ত করা হয়েছে!', 'সফল!');
      }

      if (openDrawer !== false) {
        this.openCart();
      }

      return true;
    },

    /**
     * Remove product completely from cart by id
     */
    removeItem: function (id, showNotification) {
      var strId = String(id);
      var removedItem = null;

      var newItems = [];
      for (var i = 0; i < this.items.length; i++) {
        if (String(this.items[i].id) === strId) {
          removedItem = this.items[i];
        } else {
          newItems.push(this.items[i]);
        }
      }

      this.items = newItems;
      this.save();
      this.renderDrawer();
      this.updateBadges();

      if (showNotification !== false) {
        var title = removedItem ? removedItem.title : 'পণ্য';
        this.notify('info', title + ' কার্ট থেকে সরানো হয়েছে।', 'কার্ট আপডেট');
      }

      return true;
    },

    /**
     * Update quantity of an item
     */
    updateQty: function (id, qty) {
      var strId = String(id);
      var newQty = parseInt(qty, 10);

      if (isNaN(newQty) || newQty <= 0) {
        return this.removeItem(strId, true);
      }

      for (var i = 0; i < this.items.length; i++) {
        if (String(this.items[i].id) === strId) {
          this.items[i].quantity = newQty;
          break;
        }
      }

      this.save();
      this.renderDrawer();
      this.updateBadges();
      return true;
    },

    /**
     * Clear all items in cart
     */
    clear: function (showNotification) {
      this.items = [];
      this.save();
      this.renderDrawer();
      this.updateBadges();

      if (showNotification !== false) {
        this.notify('info', 'আপনার শপিং ব্যাগ খালি করা হয়েছে।', 'কার্ট খালি');
      }
    },

    /**
     * 'Buy Now' action: Adds item to cart and proceeds to checkout immediately
     */
    buyNow: function (product, qty) {
      this.addItem(product, qty || 1, false, false);

      // Check if quick checkout modal exists on page
      var modal = document.getElementById('quickCheckoutModal') || document.querySelector('.quick-checkout-modal');
      if (modal && window.jQuery && typeof $(modal).modal === 'function') {
        $(modal).modal('show');
        return;
      }

      // Default redirect to checkout.php
      window.location.href = 'checkout.php';
    },

    /**
     * Open Slide-Out Minicart Drawer
     */
    openCart: function () {
      this.ensureDrawerDOM();

      var drawers = document.querySelectorAll('.cart-drawer, #nahidCartDrawer');
      var overlays = document.querySelectorAll('.cart-drawer-overlay, #nahidCartOverlay, .nahid-cart-backdrop');

      for (var i = 0; i < drawers.length; i++) {
        drawers[i].classList.add('open', 'is-open');
        drawers[i].setAttribute('aria-hidden', 'false');
      }

      for (var j = 0; j < overlays.length; j++) {
        overlays[j].classList.add('open', 'is-open');
      }

      document.body.classList.add('cart-drawer-open');
      this.renderDrawer();
    },

    /**
     * Close Slide-Out Minicart Drawer
     */
    closeCart: function () {
      var drawers = document.querySelectorAll('.cart-drawer, #nahidCartDrawer');
      var overlays = document.querySelectorAll('.cart-drawer-overlay, #nahidCartOverlay, .nahid-cart-backdrop');

      for (var i = 0; i < drawers.length; i++) {
        drawers[i].classList.remove('open', 'is-open');
        drawers[i].setAttribute('aria-hidden', 'true');
      }

      for (var j = 0; j < overlays.length; j++) {
        overlays[j].classList.remove('open', 'is-open');
      }

      document.body.classList.remove('cart-drawer-open');
    },

    /**
     * Toggle Minicart Drawer
     */
    toggleCart: function () {
      var drawer = document.querySelector('.cart-drawer, #nahidCartDrawer');
      if (drawer && (drawer.classList.contains('open') || drawer.classList.contains('is-open'))) {
        this.closeCart();
      } else {
        this.openCart();
      }
    },

    /**
     * Update all badge counters and subtotal text across the page
     */
    updateBadges: function () {
      var count = this.getCount();
      var totalFormatted = this.formatPrice(this.getTotal());

      // Update badge counts (matches style.css .icon-badge inside .cart-action-btn)
      var badgeSelectors = [
        '.cart-action-btn .icon-badge',
        '.icon-badge',
        '.cart-count',
        '.cart-badge',
        '#cart-count',
        '#cartBadge',
        '#headerCartCount',
        '#mobileCartCount',
        '[data-cart-count]'
      ];

      var elements = document.querySelectorAll(badgeSelectors.join(','));
      for (var i = 0; i < elements.length; i++) {
        elements[i].textContent = count;
        if (count > 0) {
          elements[i].style.display = 'flex';
          elements[i].classList.remove('d-none', 'hidden');
        }
      }

      // Update total price displays (matches style.css .cart-total inside .cart-action-btn)
      var totalSelectors = [
        '.cart-action-btn .cart-total',
        '.cart-total',
        '#cart-total',
        '#headerCartTotal',
        '.subtotal-val',
        '[data-cart-total]'
      ];
      var totalElements = document.querySelectorAll(totalSelectors.join(','));
      for (var j = 0; j < totalElements.length; j++) {
        totalElements[j].textContent = totalFormatted;
      }

      // Update drawer header count
      var drawerCountEls = document.querySelectorAll('#cartDrawerItemCount, #nahidDrawerItemCount');
      for (var k = 0; k < drawerCountEls.length; k++) {
        drawerCountEls[k].textContent = count + ' টি';
      }
    },

    /**
     * Render the Minicart Drawer markup dynamically
     */
    renderDrawer: function () {
      var listContainers = document.querySelectorAll('.cart-items-list, #nahidCartDrawerBody');
      var footerContainers = document.querySelectorAll('.cart-drawer-footer, #nahidCartDrawerFooter');
      var drawerCountEls = document.querySelectorAll('#cartDrawerItemCount, #nahidDrawerItemCount');

      var count = this.getCount();
      var total = this.getTotal();
      var totalFormatted = this.formatPrice(total);

      for (var c = 0; c < drawerCountEls.length; c++) {
        drawerCountEls[c].textContent = count + ' টি';
      }

      // Empty State
      if (this.items.length === 0) {
        var emptyHtml = [
          '<div class="cart-empty-message">',
          '  <i class="fas fa-shopping-bag" style="font-size:3rem;color:#cbd5e1;display:block;margin-bottom:12px;">',
          '    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">',
          '      <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>',
          '      <line x1="3" y1="6" x2="21" y2="6"></line>',
          '      <path d="M16 10a4 4 0 0 1-8 0"></path>',
          '    </svg>',
          '  </i>',
          '  <h4 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin-bottom:6px;">আপনার শপিং ব্যাগটি খালি!</h4>',
          '  <p style="font-size:0.88rem;color:#64748b;margin-bottom:16px;">কোনো খাঁটি প্রাকৃতিক পণ্য এখনও যুক্ত করা হয়নি।</p>',
          '  <a href="index.php#products" class="btn-checkout-drawer" data-action="close-cart" style="text-decoration:none;display:inline-flex;width:auto;padding:10px 24px;">কেনাকাটা শুরু করুন</a>',
          '</div>'
        ].join('\n');

        for (var i = 0; i < listContainers.length; i++) {
          listContainers[i].innerHTML = emptyHtml;
        }

        for (var j = 0; j < footerContainers.length; j++) {
          footerContainers[j].style.display = 'none';
        }
        return;
      }

      // Render Item Rows
      for (var f = 0; f < footerContainers.length; f++) {
        footerContainers[f].style.display = 'block';
      }

      var itemsHtml = [];
      for (var k = 0; k < this.items.length; k++) {
        var item = this.items[k];
        var lineTotal = (item.price || 0) * (item.quantity || 1);
        var fallbackImg = 'https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=600&q=80';

        itemsHtml.push([
          '<div class="cart-item-row" data-id="' + item.id + '">',
          '  <img class="cart-item-thumb" src="' + (item.image || fallbackImg) + '" alt="' + this.escapeHtml(item.title) + '" onerror="this.onerror=null;this.src=\'' + fallbackImg + '\'">',
          '  <div class="cart-item-details">',
          '    <h4 class="cart-item-title">' + this.escapeHtml(item.title) + '</h4>',
          '    <div class="cart-item-price-unit">' + this.formatPrice(item.price) + (item.unit ? ' <span style="font-size:0.75rem;color:#64748b;font-weight:normal;">(' + this.escapeHtml(item.unit) + ')</span>' : '') + '</div>',
          '    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:4px;">',
          '      <div class="cart-qty-spinner">',
          '        <button type="button" class="qty-btn minus-btn" data-id="' + item.id + '" aria-label="কমান">−</button>',
          '        <span class="qty-display">' + item.quantity + '</span>',
          '        <button type="button" class="qty-btn plus-btn" data-id="' + item.id + '" aria-label="বাড়ান">+</button>',
          '      </div>',
          '      <div style="font-weight:700;color:#15803d;font-size:0.95rem;">' + this.formatPrice(lineTotal) + '</div>',
          '    </div>',
          '  </div>',
          '  <button type="button" class="cart-item-remove" data-id="' + item.id + '" title="মুছে ফেলুন" aria-label="মুছে ফেলুন">',
          '    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">',
          '      <polyline points="3 6 5 6 21 6"></polyline>',
          '      <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>',
          '      <line x1="10" y1="11" x2="10" y2="17"></line>',
          '      <line x1="14" y1="11" x2="14" y2="17"></line>',
          '    </svg>',
          '  </button>',
          '</div>'
        ].join('\n'));
      }

      for (var l = 0; l < listContainers.length; l++) {
        listContainers[l].innerHTML = itemsHtml.join('\n');
      }

      // Render Drawer Footer
      var footerHtml = [
        '<div class="cart-subtotal-row">',
        '  <span>সর্বমোট (Subtotal):</span>',
        '  <span class="subtotal-val">' + totalFormatted + '</span>',
        '</div>',
        '<div style="font-size:0.8rem;color:#64748b;margin-bottom:12px;text-align:right;">🚚 ডেলিভারি চার্জ চেকআউটে যোগ করা হবে</div>',
        '<a href="checkout.php" class="btn-checkout-drawer">',
        '  <span>অর্ডার সম্পন্ন করুন (' + totalFormatted + ')</span>',
        '  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">',
        '    <line x1="5" y1="12" x2="19" y2="12"></line>',
        '    <polyline points="12 5 19 12 12 19"></polyline>',
        '  </svg>',
        '</a>',
        '<div style="display:flex;gap:10px;margin-top:8px;">',
        '  <button type="button" class="btn-continue-shopping" data-action="close-cart" style="flex:1;">আরো কেনাকাটা করুন</button>',
        '  <button type="button" class="btn-continue-shopping" data-action="clear-cart" style="color:#dc2626;flex:0.7;">ব্যাগ খালি করুন</button>',
        '</div>'
      ].join('\n');

      for (var m = 0; m < footerContainers.length; m++) {
        footerContainers[m].innerHTML = footerHtml;
      }
    },

    /**
     * Escape HTML string helper
     */
    escapeHtml: function (str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    },

    /**
     * Extract product attributes from DOM element
     */
    extractProductFromElement: function (elem) {
      var $el = $(elem);
      var card = $el.closest('.product-card, .product-item, .shelf-product, [data-product-card]');

      var id = $el.attr('data-id') || (card.length ? card.attr('data-id') : null) || '';
      var title = $el.attr('data-title') || (card.length ? (card.attr('data-title') || card.find('.product-title, h3, h4').first().text()) : '');
      var price = $el.attr('data-price') || (card.length ? (card.attr('data-price') || card.find('.current-price, .product-price').first().text()) : 0);
      var origPrice = $el.attr('data-original-price') || (card.length ? (card.attr('data-original-price') || card.find('.original-price, del').first().text()) : null);
      var image = $el.attr('data-image') || (card.length ? (card.attr('data-image') || card.find('img').first().attr('src')) : '');
      var unit = $el.attr('data-unit') || $el.attr('data-weight') || (card.length ? (card.attr('data-unit') || card.find('.product-unit').first().text()) : '');
      var category = $el.attr('data-category') || (card.length ? card.attr('data-category') : '');
      var url = $el.attr('data-url') || (card.length ? card.find('a').first().attr('href') : '#');

      // Clean price string (remove ৳, commas, whitespace)
      if (typeof price === 'string') {
        price = price.replace(/[^\d.]/g, '');
      }

      // Quantity input inside product card if exists
      var qtyInput = card.length ? card.find('.qty-input, input[name="quantity"], .qty-display') : null;
      var qty = (qtyInput && qtyInput.length) ? (parseInt(qtyInput.val() || qtyInput.text(), 10)) : 1;
      if (isNaN(qty) || qty <= 0) qty = 1;

      return {
        product: {
          id: String(id).trim(),
          title: String(title).trim(),
          price: parseFloat(price) || 0,
          original_price: origPrice ? parseFloat(String(origPrice).replace(/[^\d.]/g, '')) : null,
          image: String(image).trim(),
          unit: String(unit).trim(),
          weight: String(unit).trim(),
          category: String(category).trim(),
          url: url
        },
        qty: qty
      };
    },

    /**
     * Bind all Cart-related click and change events
     */
    bindEvents: function () {
      var self = this;

      // Add To Cart button clicks (supports .btn-card-add from style.css, and standard .add-to-cart)
      $(document).on('click', '.btn-card-add, .add-to-cart, [data-action="add-to-cart"], .btn-add-to-cart', function (e) {
        e.preventDefault();
        var extracted = self.extractProductFromElement(this);
        if (extracted.product.id) {
          self.addItem(extracted.product, extracted.qty, true, true);
        } else {
          console.warn('Product ID not found on add-to-cart button or parent card.');
        }
      });

      // Buy Now button clicks (supports .btn-card-buy from style.css, and standard .buy-now)
      $(document).on('click', '.btn-card-buy, .buy-now, [data-action="buy-now"], .btn-buy-now, .btn-order-now', function (e) {
        e.preventDefault();
        var extracted = self.extractProductFromElement(this);
        if (extracted.product.id) {
          self.buyNow(extracted.product, extracted.qty);
        } else {
          if (self.items.length > 0) {
            window.location.href = 'checkout.php';
          } else {
            self.notify('warning', 'আপনার শপিং ব্যাগ খালি! অনুগ্রহ করে একটি পণ্য বেছে নিন।', 'সতর্কতা');
          }
        }
      });

      // Open Minicart Drawer (supports .cart-action-btn from style.css and .open-cart)
      $(document).on('click', '.cart-action-btn, .cart-toggle, .open-cart, [data-action="open-cart"], #headerCartBtn, #floatingCartBtn', function (e) {
        e.preventDefault();
        self.openCart();
      });

      // Close Minicart Drawer
      $(document).on('click', '.cart-close-btn, .close-cart, [data-action="close-cart"], .cart-drawer-overlay, #nahidCartOverlay', function (e) {
        e.preventDefault();
        self.closeCart();
      });

      // Keyboard ESC key closes drawer
      $(document).on('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
          self.closeCart();
        }
      });

      // Item Quantity Minus
      $(document).on('click', '.qty-btn.minus-btn, .btn-qty-minus', function (e) {
        e.preventDefault();
        var id = $(this).attr('data-id');
        var item = null;
        for (var i = 0; i < self.items.length; i++) {
          if (String(self.items[i].id) === String(id)) {
            item = self.items[i];
            break;
          }
        }
        if (item) {
          self.updateQty(id, item.quantity - 1);
        }
      });

      // Item Quantity Plus
      $(document).on('click', '.qty-btn.plus-btn, .btn-qty-plus', function (e) {
        e.preventDefault();
        var id = $(this).attr('data-id');
        var item = null;
        for (var i = 0; i < self.items.length; i++) {
          if (String(self.items[i].id) === String(id)) {
            item = self.items[i];
            break;
          }
        }
        if (item) {
          self.updateQty(id, item.quantity + 1);
        }
      });

      // Remove Item Button
      $(document).on('click', '.cart-item-remove, .btn-remove-item', function (e) {
        e.preventDefault();
        var id = $(this).attr('data-id');
        self.removeItem(id, true);
      });

      // Clear Cart Button
      $(document).on('click', '[data-action="clear-cart"], .btn-clear-cart', function (e) {
        e.preventDefault();
        if (window.confirm('আপনি কি নিশ্চিত যে আপনার শপিং ব্যাগ খালি করতে চান?')) {
          self.clear(true);
        }
      });
    },

    /**
     * Ensure Cart Drawer HTML Structure exists in DOM
     */
    ensureDrawerDOM: function () {
      if (document.querySelector('.cart-drawer')) {
        return;
      }

      var overlay = document.createElement('div');
      overlay.id = 'nahidCartOverlay';
      overlay.className = 'cart-drawer-overlay';
      overlay.setAttribute('data-action', 'close-cart');

      var drawer = document.createElement('div');
      drawer.id = 'nahidCartDrawer';
      drawer.className = 'cart-drawer';
      drawer.setAttribute('role', 'dialog');
      drawer.setAttribute('aria-modal', 'true');
      drawer.setAttribute('aria-hidden', 'true');

      drawer.innerHTML = [
        '<div class="cart-drawer-header">',
        '  <h3>',
        '    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">',
        '      <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>',
        '      <line x1="3" y1="6" x2="21" y2="6"></line>',
        '      <path d="M16 10a4 4 0 0 1-8 0"></path>',
        '    </svg>',
        '    <span>আপনার শপিং ব্যাগ (<span id="cartDrawerItemCount">০ টি</span>)</span>',
        '  </h3>',
        '  <button type="button" class="cart-close-btn" data-action="close-cart" aria-label="বন্ধ করুন">✕</button>',
        '</div>',
        '<div class="cart-free-shipping-bar">',
        '  <span>🍯 ১০০% খাঁটি প্রাকৃতিক পণ্যের বিশ্বস্ত প্রতিষ্ঠান - নাহিদ হানি</span>',
        '</div>',
        '<div class="cart-items-list" id="nahidCartDrawerBody"></div>',
        '<div class="cart-drawer-footer" id="nahidCartDrawerFooter"></div>'
      ].join('\n');

      document.body.appendChild(overlay);
      document.body.appendChild(drawer);
    }
  };

  // Expose Cart globally
  window.Cart = Cart;
  window.cart = Cart;

  // Initialize once DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      Cart.init();
    });
  } else {
    Cart.init();
  }

})(window, document, window.jQuery || window.$);
