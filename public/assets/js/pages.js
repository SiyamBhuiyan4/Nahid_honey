/* Product, checkout, order success, tracking and wishlist pages. Depends on window.NHCart (site.js). */
(function () {
  'use strict';

  var PAGE = window.NH_PAGE || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var LS = {
    get: function (k, d) { try { var v = JSON.parse(localStorage.getItem(k)); return v == null ? d : v; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
    del: function (k) { try { localStorage.removeItem(k); } catch (e) {} }
  };
  var CUR = (window.SITE && window.SITE.currency) || '৳';
  var FREE = Number(window.SITE && window.SITE.freeDelivery) || 0;

  function money(n) { return window.NHCart ? NHCart.money(n) : CUR + Math.round(Number(n) || 0).toLocaleString('en-US'); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg, type) { if (window.NHCart && NHCart.toast) NHCart.toast(msg, type); }
  function products() { return window.SITE_PRODUCTS || []; }
  function findProduct(id) {
    var list = products();
    for (var i = 0; i < list.length; i++) if (String(list[i].id) === String(id)) return list[i];
    return null;
  }
  function wish() { return window.NHWishlist || null; }

  function api(action, body) {
    return fetch('/api.php?action=' + encodeURIComponent(action), {
      method: body ? 'POST' : 'GET',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { success: false, error: 'Unexpected server response. Please try again.' }; });
    }, function () {
      return { success: false, error: 'Network error. Please check your connection and try again.' };
    });
  }

  function copyText(text) {
    var done = function () { toast('Copied: ' + text); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
    } else { fallbackCopy(text); done(); }
  }
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    ta.remove();
  }

  document.addEventListener('click', function (ev) {
    var c = ev.target.closest('[data-copy]');
    if (c) { ev.preventDefault(); copyText(c.getAttribute('data-copy')); }
  });

  /** Product card markup (mirrors render_product_card in layout.php). */
  function cardHTML(p) {
    var save = p.original_price && p.original_price > p.price ? p.original_price - p.price : 0;
    var out = p.in_stock === false;
    var url = '/product.php?id=' + encodeURIComponent(p.id);
    return '<article class="pcard' + (out ? ' is-out' : '') + '">' +
      '<a href="' + url + '" class="pcard-media">' + (out ? '<span class="ribbon ribbon--muted">Out of stock</span>' : '') +
      '<img src="' + esc(p.image) + '" alt="' + esc(p.title) + '" loading="lazy"></a>' +
      '<div class="pcard-body">' + (p.category ? '<span class="pcard-cat">' + esc(p.category) + '</span>' : '') +
      '<h3 class="pcard-title"><a href="' + url + '">' + esc(p.title) + '</a></h3>' +
      (p.unit ? '<span class="pcard-unit">' + esc(p.unit) + '</span>' : '') +
      '<div class="price-row"><span class="price">' + money(p.price) + '</span>' + (save ? '<s class="price-old">' + money(p.original_price) + '</s>' : '') + '</div>' +
      (save ? '<span class="save-pill">Save ' + money(save) + '</span>' : '') +
      '<div class="pcard-actions">' +
      '<button type="button" class="btn btn-outline btn-sm" data-add-to-cart="' + esc(p.id) + '"' + (out ? ' disabled' : '') + '><i class="fa-solid fa-cart-plus"></i> Add to Cart</button>' +
      '<button type="button" class="btn btn-primary btn-sm" data-buy-now="' + esc(p.id) + '"' + (out ? ' disabled' : '') + '><i class="fa-solid fa-bolt"></i> Buy Now</button>' +
      '</div></div></article>';
  }

  function whenCart(fn) {
    if (window.NHCart) fn(); else document.addEventListener('nhcart:ready', fn, { once: true });
  }

  /* ======================================================================
     Product page
     ====================================================================== */
  function initProduct() {
    var P = PAGE.product || {};
    var pid = String(P.id);
    var images = P.images || [];
    var idx = 0;
    var main = $('#pdpMainImg'), stage = $('[data-pdp-stage]');

    // Gallery
    function show(i) {
      if (!images.length || !main) return;
      idx = (i + images.length) % images.length;
      stage.classList.remove('is-zoomed');
      main.style.transformOrigin = '';
      main.classList.add('is-swapping');
      setTimeout(function () { main.src = images[idx]; main.classList.remove('is-swapping'); }, 120);
      $$('[data-pdp-thumb]').forEach(function (b, j) { b.classList.toggle('is-active', j === idx); });
      var active = $('[data-pdp-thumb].is-active');
      if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    }
    document.addEventListener('click', function (ev) {
      var t = ev.target.closest('[data-pdp-thumb]');
      if (t) { show(+t.getAttribute('data-pdp-thumb')); return; }
      if (ev.target.closest('[data-pdp-prev]')) { show(idx - 1); return; }
      if (ev.target.closest('[data-pdp-next]')) { show(idx + 1); }
    });

    // Zoom: hover on mouse devices, tap to toggle on touch.
    var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    document.documentElement.classList.toggle('is-touch', !fine);
    function origin(ev) {
      var r = stage.getBoundingClientRect();
      var pt = ev.touches ? ev.touches[0] : ev;
      var x = Math.max(0, Math.min(100, (pt.clientX - r.left) / r.width * 100));
      var y = Math.max(0, Math.min(100, (pt.clientY - r.top) / r.height * 100));
      main.style.transformOrigin = x + '% ' + y + '%';
    }
    if (stage && main) {
      if (fine) {
        stage.addEventListener('mouseenter', function () { stage.classList.add('is-zoomed'); });
        stage.addEventListener('mousemove', origin);
        stage.addEventListener('mouseleave', function () { stage.classList.remove('is-zoomed'); });
      } else {
        stage.addEventListener('click', function (ev) {
          if (ev.target.closest('button')) return;
          var on = !stage.classList.contains('is-zoomed');
          if (on) origin(ev);
          stage.classList.toggle('is-zoomed', on);
        });
        stage.addEventListener('touchmove', function (ev) { if (stage.classList.contains('is-zoomed')) { origin(ev); ev.preventDefault(); } }, { passive: false });
      }
      // Swipe between images
      var x0 = null;
      stage.addEventListener('touchstart', function (e) { x0 = stage.classList.contains('is-zoomed') ? null : e.touches[0].clientX; }, { passive: true });
      stage.addEventListener('touchend', function (e) {
        if (x0 === null || images.length < 2) return;
        var dx = e.changedTouches[0].clientX - x0; x0 = null;
        if (Math.abs(dx) > 40) show(idx + (dx < 0 ? 1 : -1));
      });
    }

    // Quantity
    var qtyInput = $('#pdpQty');
    var max = Math.max(1, parseInt(P.max, 10) || 99);
    function qty() { return Math.max(1, Math.min(max, parseInt(qtyInput && qtyInput.value, 10) || 1)); }
    var stepper = $('[data-pdp-qty]');
    if (stepper && qtyInput) {
      stepper.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-step]'); if (!b) return;
        var v = qty() + parseInt(b.getAttribute('data-step'), 10);
        if (v > max) toast('Only ' + max + ' available', 'err');
        qtyInput.value = Math.max(1, Math.min(max, v));
      });
      qtyInput.addEventListener('change', function () { qtyInput.value = qty(); });
    }

    // Add / buy
    function inCart() {
      var row = NHCart.items().filter(function (r) { return String(r.id) === pid; })[0];
      return row ? row.quantity : 0;
    }
    document.addEventListener('click', function (ev) {
      var add = ev.target.closest('[data-pdp-add]'), buy = ev.target.closest('[data-pdp-buy]');
      if (!add && !buy) return;
      ev.preventDefault();
      whenCart(function () {
        var q = qty();
        var have = inCart();
        if (have + q > max) q = Math.max(0, max - have);
        if (buy) {
          if (q > 0) NHCart.add(pid, q);
          window.location.href = '/checkout.php';
          return;
        }
        if (q <= 0) { toast('You already have the maximum available quantity in your cart', 'err'); return; }
        NHCart.add(pid, q);
        toast('Added to cart');
        add.classList.add('is-pulse'); setTimeout(function () { add.classList.remove('is-pulse'); }, 400);
      });
    });

    // Wishlist
    var wbtn = $('[data-pdp-wish]');
    function paintWish() {
      if (!wbtn) return;
      var W = wish();
      if (!W) { wbtn.hidden = true; return; }
      wbtn.hidden = false;
      var on = W.has(pid);
      wbtn.classList.toggle('is-on', on);
      wbtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      wbtn.innerHTML = '<i class="fa-' + (on ? 'solid' : 'regular') + ' fa-heart"></i><span>' + (on ? 'Saved to wishlist' : 'Add to wishlist') + '</span>';
    }
    if (wbtn) {
      wbtn.addEventListener('click', function () {
        var W = wish(); if (!W) return;
        W.toggle(pid);
        paintWish();
        toast(W.has(pid) ? 'Saved to your wishlist' : 'Removed from wishlist');
      });
      document.addEventListener('nhwishlist:change', paintWish);
      paintWish();
      window.addEventListener('load', paintWish);
    }

    // Share
    var native = $('[data-share="native"]');
    if (native && navigator.share) {
      native.hidden = false;
      native.addEventListener('click', function () {
        navigator.share({ title: P.title, url: location.href.split('#')[0] }).catch(function () {});
      });
    }
    var copy = $('[data-share="copy"]');
    if (copy) copy.addEventListener('click', function () { copyText(copy.getAttribute('data-url') || location.href); });

    // Tabs
    function tab(name, scroll) {
      $$('[data-pdp-tab]').forEach(function (b) {
        var on = b.getAttribute('data-pdp-tab') === name;
        b.classList.toggle('is-active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      $$('[data-pdp-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-pdp-panel') !== name; });
      if (scroll) { var t = $('.pdp-tabs'); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-pdp-tab]');
      if (b) { tab(b.getAttribute('data-pdp-tab')); return; }
      var l = ev.target.closest('[data-pdp-tab-link]');
      if (l) { ev.preventDefault(); tab(l.getAttribute('data-pdp-tab-link'), true); }
    });
    if (location.hash === '#reviews') tab('reviews', true);

    // Reviews
    var form = $('#reviewForm'), thanks = $('#reviewThanks');
    document.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-review-open]')) {
        tab('reviews');
        if (form) { form.hidden = false; thanks.hidden = true; var n = $('#rvName'); if (n) n.focus(); form.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      }
      if (ev.target.closest('[data-review-close]') && form) form.hidden = true;
      var more = ev.target.closest('[data-review-more]');
      if (more) { $$('[data-rv-more]').forEach(function (li) { li.hidden = false; }); more.remove(); }
    });
    if (form) {
      form.addEventListener('input', function (ev) { clearError(ev.target); });
      form.addEventListener('change', function (ev) { if (ev.target.name === 'rating') clearError(ev.target); });
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var data = {
          product_id: pid,
          name: form.name.value.trim(),
          location: form.location.value.trim(),
          comment: form.comment.value.trim(),
          rating: (form.querySelector('input[name="rating"]:checked') || {}).value || 0,
          website: form.website.value
        };
        var ok = true;
        if (!(+data.rating >= 1)) { setError(form.querySelector('.rv-stars-input'), 'Please choose a star rating.'); ok = false; }
        if (data.name.length < 2) { setError(form.name, 'Please enter your name.'); ok = false; }
        if (data.comment.length < 10) { setError(form.comment, 'Please write at least 10 characters.'); ok = false; }
        if (!ok) return;
        var btn = form.querySelector('[type="submit"]'), label = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<span class="nh-spinner nh-spinner--light"></span> Submitting…';
        api('submit_review', data).then(function (res) {
          btn.disabled = false; btn.innerHTML = label;
          if (!res.success) {
            var f = res.field && form.querySelector('[name="' + res.field + '"]');
            if (f) setError(f, res.error); else toast(res.error || 'Could not submit your review.', 'err');
            return;
          }
          form.reset(); form.hidden = true; thanks.hidden = false;
          thanks.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
      });
    }

    // Sticky mobile bar: visible once the main buy buttons scroll out of view.
    var sticky = $('#pdpSticky'), buyBox = $('#pdpBuy');
    if (sticky && buyBox && 'IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        var hide = entries[0].isIntersecting || entries[0].boundingClientRect.top > 0;
        sticky.classList.toggle('is-visible', !hide);
        sticky.setAttribute('aria-hidden', hide ? 'true' : 'false');
        document.body.classList.toggle('has-pdp-sticky', !hide);
      }).observe(buyBox);
    }

    // Recently viewed
    var KEY = 'nh_recent';
    var recent = LS.get(KEY, []);
    if (!Array.isArray(recent)) recent = [];
    var others = recent.filter(function (id) { return String(id) !== pid; });
    var list = others.map(findProduct).filter(Boolean).slice(0, 8);
    var sec = $('#recentlyViewed');
    if (sec && list.length) {
      $('[data-recent-grid]', sec).innerHTML = list.map(cardHTML).join('');
      sec.hidden = false;
      var clr = $('[data-recent-clear]', sec);
      if (clr) clr.addEventListener('click', function () { LS.set(KEY, [pid]); sec.hidden = true; });
    }
    LS.set(KEY, [pid].concat(others).slice(0, 12));
  }

  /* ---------- Form error helpers ---------- */
  function fieldOf(el) { return el && (el.closest('.nh-field') || el.parentNode); }
  function setError(el, msg) {
    var f = fieldOf(el); if (!f) return;
    f.classList.add('has-error');
    var m = f.querySelector('.nh-error');
    if (!m) { m = document.createElement('small'); m.className = 'nh-error'; m.setAttribute('role', 'alert'); f.appendChild(m); }
    m.textContent = msg;
  }
  function clearError(el) {
    var f = fieldOf(el); if (!f || !f.classList.contains('has-error')) return;
    f.classList.remove('has-error');
    var m = f.querySelector('.nh-error'); if (m) m.remove();
  }

  /* ======================================================================
     Checkout
     ====================================================================== */
  function bdMobile(v) {
    var d = String(v || '').replace(/\D/g, '').replace(/^0?88(?=01)/, '');
    return /^01[3-9]\d{8}$/.test(d) ? d : null;
  }

  function initCheckout() {
    var form = $('#checkoutForm'), empty = $('#checkoutEmpty'), loading = $('#coLoading');
    if (!form) return;
    var CKEY = 'nh_customer';
    var coupon = null;          // last successful apply_coupon response
    var couponTimer = null;
    var submitting = false;

    function fee() { var z = form.querySelector('input[name="zone"]:checked'); return z ? Number(z.getAttribute('data-fee')) || 0 : 0; }
    function payKey() { return (form.querySelector('input[name="payment_method"]:checked') || {}).value || ''; }

    function itemHTML(l) {
      var p = l.p, max = 99;
      return '<div class="co-item" data-co-line="' + esc(p.id) + '">' +
        '<a href="/product.php?id=' + encodeURIComponent(p.id) + '" class="co-item-img"><img src="' + esc(p.image) + '" alt=""><b>' + l.qty + '</b></a>' +
        '<div class="co-item-info"><a href="/product.php?id=' + encodeURIComponent(p.id) + '">' + esc(p.title) + '</a>' +
        '<small>' + (p.unit ? esc(p.unit) + ' · ' : '') + money(p.price) + '</small>' +
        '<div class="nh-qty nh-qty--sm">' +
        '<button type="button" data-co-step="-1" aria-label="Decrease"' + (l.qty <= 1 ? ' disabled' : '') + '><i class="fa-solid fa-minus"></i></button>' +
        '<input type="number" min="1" max="' + max + '" value="' + l.qty + '" data-co-qty aria-label="Quantity">' +
        '<button type="button" data-co-step="1" aria-label="Increase"><i class="fa-solid fa-plus"></i></button></div></div>' +
        '<div class="co-item-end"><b>' + money(l.total) + '</b><button type="button" class="co-remove" data-co-remove aria-label="Remove item"><i class="fa-regular fa-trash-can"></i></button></div>' +
        '</div>';
    }

    function render() {
      var ls = NHCart.lines();
      if (loading) loading.hidden = true;
      if (!ls.length && !submitting) { form.hidden = true; empty.hidden = false; document.body.classList.remove('co-has-items'); return; }
      form.hidden = false; empty.hidden = true; document.body.classList.add('co-has-items');
      $('#coItems').innerHTML = ls.map(itemHTML).join('');
      $('#coCount').textContent = '(' + NHCart.count() + ' item' + (NHCart.count() === 1 ? '' : 's') + ')';
      totals();
    }

    function totals() {
      var sub = NHCart.subtotal(), zoneFee = fee(), disc = 0, delDisc = 0;
      var freeByThreshold = FREE > 0 && sub >= FREE;
      var del = freeByThreshold ? 0 : zoneFee;
      if (coupon) { disc = Math.min(sub, coupon.discount || 0); delDisc = Math.min(del, coupon.delivery_discount || 0); }
      var delivery = Math.max(0, del - delDisc);
      var total = Math.max(0, sub - disc + delivery);
      $('#sumSubtotal').textContent = money(sub);
      $('#sumDelivery').innerHTML = (delDisc > 0 || (freeByThreshold && zoneFee > 0)) ? '<s>' + money(zoneFee) + '</s> <b class="co-free">Free</b>' : (del > 0 ? money(del) : 'Free');
      var hint = $('#coFreeHint');
      if (hint) {
        hint.hidden = !(FREE > 0 && !freeByThreshold && zoneFee > 0);
        if (!hint.hidden) hint.innerHTML = '<i class="fa-solid fa-truck-fast"></i> Add <b>' + money(FREE - sub) + '</b> more for free delivery';
      }
      var row = $('#sumDiscountRow');
      if (row) { row.hidden = !(disc > 0); $('#sumDiscount').textContent = '−' + money(disc); }
      $('#sumTotal').textContent = money(total);
      var mt = $('#mobileTotal'); if (mt) mt.textContent = money(total);
    }

    // Item edits
    form.addEventListener('click', function (ev) {
      var line = ev.target.closest('[data-co-line]'); if (!line) return;
      var id = line.getAttribute('data-co-line');
      var cur = NHCart.items().filter(function (r) { return String(r.id) === String(id); })[0];
      if (!cur) return;
      var step = ev.target.closest('[data-co-step]');
      if (step) { NHCart.setQty(id, cur.quantity + parseInt(step.getAttribute('data-co-step'), 10)); return; }
      if (ev.target.closest('[data-co-remove]')) { NHCart.remove(id); toast('Item removed'); }
    });
    form.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t.hasAttribute('data-co-qty')) NHCart.setQty(t.closest('[data-co-line]').getAttribute('data-co-line'), parseInt(t.value, 10) || 1);
      if (t.name === 'zone') { totals(); revalidateCoupon(); }
      if (t.name === 'payment_method') paintPayment();
    });
    form.addEventListener('input', function (ev) { clearError(ev.target); hideError(); });

    // Payment instructions
    function paintPayment() {
      var k = payKey();
      $$('[data-payinfo]', form).forEach(function (b) { b.hidden = b.getAttribute('data-payinfo') !== k; });
    }

    // Alternative phone
    var altBtn = $('[data-toggle-alt]');
    if (altBtn) altBtn.addEventListener('click', function () {
      $('#altPhoneField').hidden = false; altBtn.hidden = true; $('#fAltPhone').focus();
    });

    // Coupon
    var cBox = $('#couponBox');
    function couponMsg(msg, err) {
      var m = $('#couponMsg'); if (!m) return;
      m.hidden = !msg; m.textContent = msg || ''; m.classList.toggle('is-error', !!err);
    }
    function paintCoupon() {
      if (!cBox) return;
      $('#couponApplied').hidden = !coupon;
      $('[data-coupon-toggle]', cBox).hidden = !!coupon;
      if (coupon) {
        $('#couponForm').hidden = true;
        $('#couponCode').textContent = coupon.code;
        $('#couponLabel').textContent = coupon.label;
      }
      totals();
    }
    function applyCoupon(code, silent) {
      var btn = $('#couponApply');
      if (!silent && btn) { btn.disabled = true; btn.textContent = 'Checking…'; }
      return api('apply_coupon', { code: code, items: NHCart.items(), zone: (form.querySelector('input[name="zone"]:checked') || {}).value })
        .then(function (res) {
          if (btn) { btn.disabled = false; btn.textContent = 'Apply'; }
          if (res.success) {
            coupon = res; couponMsg(''); paintCoupon();
            if (!silent) toast(res.message || 'Coupon applied');
          } else {
            var had = !!coupon;
            coupon = null; paintCoupon();
            if (silent && had) { $('#couponForm').hidden = false; $('#couponInput').value = code; }
            if (!silent || had) couponMsg(res.error || 'This coupon code is not valid.', true);
          }
          return res;
        });
    }
    function revalidateCoupon() {
      if (!coupon) return;
      clearTimeout(couponTimer);
      var code = coupon.code;
      couponTimer = setTimeout(function () { applyCoupon(code, true); }, 350);
    }
    if (cBox) {
      $('[data-coupon-toggle]', cBox).addEventListener('click', function () {
        $('#couponForm').hidden = false; $('[data-coupon-toggle]', cBox).hidden = true; $('#couponInput').focus();
      });
      $('#couponApply').addEventListener('click', function () {
        var code = $('#couponInput').value.trim();
        if (!code) { couponMsg('Please enter a coupon code.', true); return; }
        applyCoupon(code, false);
      });
      $('#couponInput').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); $('#couponApply').click(); }
      });
      $('#couponInput').addEventListener('input', function () { couponMsg(''); });
      $('#couponRemove').addEventListener('click', function () {
        coupon = null; couponMsg(''); $('#couponInput').value = ''; paintCoupon(); toast('Coupon removed');
      });
    }

    // Remember details
    var saved = LS.get(CKEY, null);
    if (saved && typeof saved === 'object') {
      ['name', 'phone', 'email', 'address', 'district', 'area', 'alt_phone'].forEach(function (k) {
        var el = form.elements[k]; if (el && saved[k] && !el.value) el.value = saved[k];
      });
      if (saved.alt_phone && altBtn) { $('#altPhoneField').hidden = false; altBtn.hidden = true; }
      if (saved.zone) { var z = form.querySelector('input[name="zone"][value="' + String(saved.zone).replace(/"/g, '') + '"]'); if (z) z.checked = true; }
    }

    // Errors
    function showError(msg) { var b = $('#coError'); if (b) { b.textContent = msg; b.hidden = false; } }
    function hideError() { var b = $('#coError'); if (b) b.hidden = true; }

    function validate() {
      var ok = true, first = null;
      function bad(el, msg) { setError(el, msg); ok = false; first = first || el; }
      if (form.name.value.trim().length < 2) bad(form.name, 'Please enter your full name.');
      if (!bdMobile(form.phone.value)) bad(form.phone, 'Enter a valid 11-digit mobile number, e.g. 017XXXXXXXX.');
      var alt = form.alt_phone.value.trim();
      if (alt && !bdMobile(alt)) bad(form.alt_phone, 'Enter a valid mobile number or leave it empty.');
      var em = form.email.value.trim();
      if (em && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(em)) bad(form.email, 'Enter a valid email address or leave it empty.');
      if (form.address.value.trim().length < 8) bad(form.address, 'Please enter your full address (house, road, area).');
      if (first) { first.focus({ preventScroll: true }); first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      return ok;
    }
    // Validate on blur for quick feedback
    form.addEventListener('focusout', function (ev) {
      var t = ev.target;
      if (t.name === 'phone' && t.value.trim() && !bdMobile(t.value)) setError(t, 'Enter a valid 11-digit mobile number, e.g. 017XXXXXXXX.');
      if (t.name === 'email' && t.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(t.value.trim())) setError(t, 'Enter a valid email address or leave it empty.');
    });

    var btn = $('#placeOrder');
    function busy(on) {
      $$('button[type="submit"]', form).forEach(function (b) { b.disabled = on; });
      btn.innerHTML = on ? '<span class="nh-spinner nh-spinner--light"></span> <span>Placing your order…</span>' : '<i class="fa-solid fa-lock"></i> <span>Place Order</span>';
      form.classList.toggle('is-busy', on);
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (submitting) return;
      hideError();
      if (!validate()) return;
      var items = NHCart.items();
      if (!items.length) { render(); return; }

      var payload = {
        name: form.name.value.trim(),
        phone: bdMobile(form.phone.value),
        alt_phone: form.alt_phone.value.trim() ? bdMobile(form.alt_phone.value) : '',
        email: form.email.value.trim(),
        address: form.address.value.trim(),
        district: form.district.value.trim(),
        area: form.area.value.trim(),
        zone: (form.querySelector('input[name="zone"]:checked') || {}).value || '',
        payment_method: payKey(),
        payment_reference: (function () { var b = form.querySelector('[data-payinfo="' + payKey() + '"] [data-payref]'); return b ? b.value.trim() : ''; })(),
        notes: form.notes.value.trim(),
        coupon_code: coupon ? coupon.code : '',
        website: form.website.value,
        items: items
      };

      submitting = true; busy(true);
      api('create_order', payload).then(function (res) {
        if (!res.success) {
          submitting = false; busy(false);
          var map = { name: 'name', phone: 'phone', alt_phone: 'alt_phone', email: 'email', address: 'address' };
          if (res.field && map[res.field] && form.elements[map[res.field]]) {
            setError(form.elements[map[res.field]], res.error);
            form.elements[map[res.field]].focus();
          } else if (res.field === 'coupon') {
            coupon = null; paintCoupon(); couponMsg(res.error, true);
            showError(res.error);
          } else {
            showError(res.error || 'Could not place your order. Please try again.');
          }
          toast(res.error || 'Could not place your order.', 'err');
          return;
        }
        if ($('#fRemember') && $('#fRemember').checked) {
          LS.set(CKEY, { name: payload.name, phone: payload.phone, email: payload.email, address: payload.address,
            district: payload.district, area: payload.area, alt_phone: payload.alt_phone, zone: payload.zone });
        } else { LS.del(CKEY); }
        rememberOrder(res.order_id);
        NHCart.clear();
        window.location.href = res.success_url || ('/order-success.php?id=' + encodeURIComponent(res.order_id));
      });
    });

    document.addEventListener('nhcart:change', function () { if (!submitting) { render(); revalidateCoupon(); } });
    paintPayment();
    render();
  }

  function rememberOrder(id) {
    if (!id) return;
    var list = LS.get('nh_orders', []);
    if (!Array.isArray(list)) list = [];
    list = [id].concat(list.filter(function (x) { return x !== id; })).slice(0, 5);
    LS.set('nh_orders', list);
  }

  /* ======================================================================
     Track page: quick links to orders placed from this device
     ====================================================================== */
  function initTrack() {
    var box = $('#trackRecent'); if (!box) return;
    var list = LS.get('nh_orders', []);
    if (!Array.isArray(list)) return;
    list = list.filter(function (id) { return /^[A-Za-z0-9\-]{3,40}$/.test(id) && id !== PAGE.order_id; });
    if (!list.length) return;
    box.innerHTML = '<span>Your recent orders:</span>' + list.map(function (id) {
      return '<a href="/track.php?id=' + encodeURIComponent(id) + '">' + esc(id) + '</a>';
    }).join('');
    box.hidden = false;
  }

  /* ======================================================================
     Wishlist page
     ====================================================================== */
  function initWishlist() {
    var listEl = $('#wlList'), emptyEl = $('#wlEmpty'), loading = $('#wlLoading'), actions = $('#wlActions');
    function render() {
      var W = wish();
      if (loading) loading.hidden = true;
      var ids = W ? W.ids() : [];
      var items = ids.map(findProduct).filter(Boolean);
      $('#wlCount').textContent = items.length ? items.length + ' saved product' + (items.length === 1 ? '' : 's') : 'Saved products on this device';
      listEl.hidden = !items.length; emptyEl.hidden = !!items.length; actions.hidden = !items.some(function (p) { return p.in_stock !== false; });
      listEl.innerHTML = items.map(function (p) {
        var out = p.in_stock === false;
        var save = p.original_price && p.original_price > p.price ? p.original_price - p.price : 0;
        var url = '/product.php?id=' + encodeURIComponent(p.id);
        return '<article class="wl-item' + (out ? ' is-out' : '') + '" data-wl="' + esc(p.id) + '">' +
          '<a href="' + url + '" class="wl-img"><img src="' + esc(p.image) + '" alt="" loading="lazy"></a>' +
          '<div class="wl-info">' + (p.category ? '<span class="wl-cat">' + esc(p.category) + '</span>' : '') +
          '<a href="' + url + '" class="wl-title">' + esc(p.title) + '</a>' +
          (p.unit ? '<small>' + esc(p.unit) + '</small>' : '') +
          '<div class="wl-price"><b>' + money(p.price) + '</b>' + (save ? '<s>' + money(p.original_price) + '</s>' : '') + '</div>' +
          '<span class="wl-stock ' + (out ? 'is-out' : 'is-in') + '">' + (out ? 'Out of stock' : 'In stock') + '</span></div>' +
          '<div class="wl-actions">' +
          '<button type="button" class="btn btn-primary btn-sm" data-wl-move' + (out ? ' disabled' : '') + '><i class="fa-solid fa-cart-plus"></i> Move to cart</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-wl-remove><i class="fa-regular fa-trash-can"></i> Remove</button>' +
          '</div></article>';
      }).join('');
    }
    document.addEventListener('click', function (ev) {
      var W = wish(); if (!W) return;
      var item = ev.target.closest('[data-wl]');
      if (item && ev.target.closest('[data-wl-remove]')) {
        W.toggle(item.getAttribute('data-wl')); render(); toast('Removed from wishlist'); return;
      }
      if (item && ev.target.closest('[data-wl-move]')) {
        whenCart(function () {
          var id = item.getAttribute('data-wl');
          if (NHCart.add(id, 1)) { if (W.has(id)) W.toggle(id); render(); toast('Moved to cart'); }
        });
        return;
      }
      if (ev.target.closest('[data-wl-all]')) {
        whenCart(function () {
          var n = 0;
          W.ids().slice().forEach(function (id) {
            var p = findProduct(id);
            if (p && p.in_stock !== false && NHCart.add(id, 1)) { W.toggle(id); n++; }
          });
          render();
          if (n) { toast(n + ' item' + (n === 1 ? '' : 's') + ' moved to cart'); if (NHCart.open) NHCart.open(); }
        });
      }
    });
    document.addEventListener('nhwishlist:change', render);
    if (wish()) render();
    else window.addEventListener('load', function () {
      render();
      if (!wish()) $('#wlCount').textContent = 'Wishlist is not available right now.';
    });
  }

  /* ---------- Boot ---------- */
  function boot() {
    if (PAGE.page === 'product') initProduct();
    if (PAGE.page === 'checkout') whenCart(initCheckout);
    if (PAGE.page === 'track') initTrack();
    if (PAGE.page === 'success') rememberOrder(PAGE.order_id);
    if (PAGE.page === 'wishlist') initWishlist();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
