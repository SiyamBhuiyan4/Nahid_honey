/* Storefront script: cart, drawer, live search, product page, checkout. No dependencies. */
(function () {
  'use strict';

  var CUR = (window.SITE && window.SITE.currency) || '৳';
  var PRODUCTS = window.SITE_PRODUCTS || [];
  var KEY = 'nahid_cart';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function money(n) { return CUR + Math.round(Number(n) || 0).toLocaleString('en-US'); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function product(id) {
    for (var i = 0; i < PRODUCTS.length; i++) if (String(PRODUCTS[i].id) === String(id)) return PRODUCTS[i];
    return null;
  }

  /* ---------- Toasts ---------- */
  function toast(msg, type) {
    var box = $('#toasts'); if (!box) return;
    var t = document.createElement('div');
    t.className = 'toast toast--' + (type || 'ok');
    t.innerHTML = '<i class="fa-solid ' + (type === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i><span>' + esc(msg) + '</span>';
    box.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 2800);
  }

  /* ---------- Cart (stores only id + qty; details come from the live catalog) ---------- */
  if (window.CHECKOUT) document.body.classList.add('is-checkout');
  var cart = load();
  function load() {
    try {
      var raw = JSON.parse(localStorage.getItem(KEY) || '[]');
      if (!Array.isArray(raw)) return [];
      return raw.map(function (r) { return { id: r.id, qty: Math.max(1, parseInt(r.qty || r.quantity || 1, 10)) }; })
        .filter(function (r) { var p = product(r.id); return p && p.in_stock !== false; });
    } catch (e) { return []; }
  }
  function save() { try { localStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) {} render(); }
  function lines() {
    return cart.map(function (r) { var p = product(r.id); return p ? { p: p, qty: r.qty, total: p.price * r.qty } : null; }).filter(Boolean);
  }
  function subtotal() { return lines().reduce(function (s, l) { return s + l.total; }, 0); }
  function count() { return cart.reduce(function (s, r) { return s + r.qty; }, 0); }

  function add(id, qty) {
    var p = product(id); if (!p) return false;
    qty = Math.max(1, parseInt(qty || 1, 10));
    var row = cart.filter(function (r) { return String(r.id) === String(id); })[0];
    if (row) row.qty = Math.min(99, row.qty + qty); else cart.push({ id: p.id, qty: Math.min(99, qty) });
    save();
    return p;
  }
  function setQty(id, qty) {
    cart = cart.map(function (r) { if (String(r.id) === String(id)) r.qty = Math.max(1, Math.min(99, qty)); return r; });
    save();
  }
  function remove(id) { cart = cart.filter(function (r) { return String(r.id) !== String(id); }); save(); }

  function lineHTML(l, compact) {
    return '<div class="citem" data-line="' + esc(l.p.id) + '">' +
      '<img src="' + esc(l.p.image) + '" alt="">' +
      '<div class="citem-info"><strong>' + esc(l.p.title) + '</strong>' +
      (l.p.unit ? '<small>' + esc(l.p.unit) + '</small>' : '') +
      '<div class="citem-row"><div class="qty qty--sm">' +
      '<button type="button" data-line-dec aria-label="Decrease"><i class="fa-solid fa-minus"></i></button>' +
      '<input type="number" value="' + l.qty + '" min="1" max="99" data-line-qty aria-label="Quantity">' +
      '<button type="button" data-line-inc aria-label="Increase"><i class="fa-solid fa-plus"></i></button></div>' +
      '<b>' + money(l.total) + '</b></div></div>' +
      '<button type="button" class="icon-btn icon-btn--sm" data-line-remove aria-label="Remove"><i class="fa-regular fa-trash-can"></i></button>' +
      '</div>';
  }

  function render() {
    var ls = lines();
    $$('[data-cart-count]').forEach(function (el) { el.textContent = count(); });
    $$('[data-cart-total]').forEach(function (el) { el.textContent = money(subtotal()); });
    document.body.classList.toggle('has-cart', ls.length > 0);

    var box = $('#cartItems');
    if (box) {
      box.innerHTML = ls.length ? ls.map(function (l) { return lineHTML(l); }).join('') :
        '<div class="drawer-empty"><i class="fa-solid fa-bag-shopping"></i><p>Your cart is empty</p>' +
        '<a href="index.php#shop" class="btn btn-outline btn-sm" data-close-cart>Start shopping</a></div>';
    }
    var co = $('#drawerCheckout'); if (co) co.classList.toggle('is-disabled', !ls.length);
    if (window.CHECKOUT) renderCheckout();
  }

  /* ---------- Drawer ---------- */
  var drawer = $('#cartDrawer'), overlay = $('.drawer-overlay');
  function openCart() {
    if (!drawer || window.CHECKOUT) return;
    overlay.hidden = false; drawer.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(function () { document.body.classList.add('cart-open'); });
  }
  function closeCart() {
    if (!drawer) return;
    document.body.classList.remove('cart-open'); drawer.setAttribute('aria-hidden', 'true');
    setTimeout(function () { overlay.hidden = true; }, 250);
  }

  function qtyFrom(btn) {
    var sel = btn.getAttribute('data-qty-from');
    var el = sel && $(sel);
    return el ? parseInt(el.value, 10) || 1 : 1;
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('button, a, [data-close-cart]');
    if (!t) return;

    if (t.hasAttribute('data-open-cart')) { ev.preventDefault(); openCart(); return; }
    if (t.hasAttribute('data-close-cart')) { closeCart(); if (t.tagName !== 'A') return; }

    if (t.hasAttribute('data-add-to-cart')) {
      var p = add(t.getAttribute('data-add-to-cart'), qtyFrom(t));
      if (p) { toast('Added to cart'); t.classList.add('is-pulse'); setTimeout(function () { t.classList.remove('is-pulse'); }, 400); }
      return;
    }
    if (t.hasAttribute('data-buy-now')) {
      if (add(t.getAttribute('data-buy-now'), qtyFrom(t))) window.location.href = 'checkout.php';
      return;
    }

    var line = t.closest('[data-line]');
    if (line) {
      var id = line.getAttribute('data-line');
      var row = cart.filter(function (r) { return String(r.id) === String(id); })[0];
      if (!row) return;
      if (t.hasAttribute('data-line-inc')) setQty(id, row.qty + 1);
      else if (t.hasAttribute('data-line-dec')) setQty(id, row.qty - 1);
      else if (t.hasAttribute('data-line-remove')) remove(id);
      return;
    }

    if (t.hasAttribute('data-qty-inc') || t.hasAttribute('data-qty-dec')) {
      var input = t.parentNode.querySelector('input');
      var v = (parseInt(input.value, 10) || 1) + (t.hasAttribute('data-qty-inc') ? 1 : -1);
      input.value = Math.max(1, Math.min(99, v));
      return;
    }

    if (t.hasAttribute('data-thumb')) {
      var main = $('#pdMainImg'); if (main) main.src = t.getAttribute('data-thumb');
      $$('[data-thumb]').forEach(function (b) { b.classList.toggle('is-active', b === t); });
    }
  });

  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t.hasAttribute('data-line-qty')) {
      setQty(t.closest('[data-line]').getAttribute('data-line'), parseInt(t.value, 10) || 1);
    }
    if (window.CHECKOUT && t.name === 'zone') renderCheckout();
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeCart(); });

  /* ---------- Live search ---------- */
  var sInput = $('#searchInput'), sBox = $('#searchResults');
  if (sInput && sBox) {
    var timer;
    sInput.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var q = sInput.value.trim().toLowerCase();
        if (q.length < 2) { sBox.hidden = true; return; }
        var hits = PRODUCTS.filter(function (p) { return (p.title + ' ' + p.category).toLowerCase().indexOf(q) > -1; }).slice(0, 6);
        sBox.innerHTML = hits.length ? hits.map(function (p) {
          return '<a href="product.php?id=' + encodeURIComponent(p.id) + '" class="sres"><img src="' + esc(p.image) + '" alt="">' +
            '<span><strong>' + esc(p.title) + '</strong><small>' + esc(p.unit || p.category || '') + '</small></span><b>' + money(p.price) + '</b></a>';
        }).join('') + '<button type="submit" class="sres-all">See all results <i class="fa-solid fa-arrow-right"></i></button>'
          : '<div class="sres-none">No products match "' + esc(sInput.value.trim()) + '"</div>';
        sBox.hidden = false;
      }, 120);
    });
    document.addEventListener('click', function (ev) { if (!ev.target.closest('.search')) sBox.hidden = true; });
  }

  /* ---------- Checkout ---------- */
  function fee() { var z = $('input[name="zone"]:checked'); return z ? Number(z.getAttribute('data-fee')) || 0 : 0; }

  function renderCheckout() {
    var form = $('#checkoutForm'), empty = $('#checkoutEmpty'), done = $('#checkoutSuccess');
    if (!form || (done && !done.hidden)) return;
    var ls = lines();
    form.hidden = !ls.length; empty.hidden = !!ls.length;
    if (!ls.length) return;
    $('#summaryItems').innerHTML = ls.map(function (l) { return lineHTML(l); }).join('');
    $('#sumSubtotal').textContent = money(subtotal());
    $('#sumDelivery').textContent = money(fee());
    $('#sumTotal').textContent = money(subtotal() + fee());
  }

  function fieldError(input, msg) {
    var f = input.closest('.field');
    f.classList.add('has-error');
    var m = f.querySelector('.field-msg');
    if (!m) { m = document.createElement('small'); m.className = 'field-msg'; f.appendChild(m); }
    m.textContent = msg;
  }

  var form = $('#checkoutForm');
  if (form) {
    form.addEventListener('input', function (ev) {
      var f = ev.target.closest('.field'); if (f) f.classList.remove('has-error');
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var name = $('#fName'), phone = $('#fPhone'), addr = $('#fAddress');
      var digits = phone.value.replace(/\D/g, '').replace(/^88/, '');
      var ok = true;
      if (name.value.trim().length < 2) { fieldError(name, 'Please enter your full name.'); ok = false; }
      if (!/^01[3-9]\d{8}$/.test(digits)) { fieldError(phone, 'Enter a valid 11-digit mobile number.'); ok = false; }
      if (addr.value.trim().length < 5) { fieldError(addr, 'Please enter your full address.'); ok = false; }
      if (!ok) { var first = $('.has-error input, .has-error textarea'); if (first) first.focus(); return; }

      var btn = $('#placeOrder'), label = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Placing order...';

      fetch('api.php?action=create_order', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: name.value.trim(), phone: digits, address: addr.value.trim(),
          notes: $('#fNotes').value.trim(),
          zone: ($('input[name="zone"]:checked') || {}).value,
          payment_method: ($('input[name="payment_method"]:checked') || {}).value,
          items: cart.map(function (r) { return { id: r.id, quantity: r.qty }; })
        })
      }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
        .then(function (res) {
          if (!res.success) throw new Error(res.error || 'Could not place your order. Please try again.');
          var o = res.order || {};
          form.hidden = true;
          $('#checkoutSuccess').hidden = false;
          cart = []; save();
          $('#successOrderId').textContent = res.order_id;
          $('#successTrack').href = 'track.php?id=' + encodeURIComponent(res.order_id);
          $('#successSummary').innerHTML =
            '<div><dt>Name</dt><dd>' + esc(o.name) + '</dd></div>' +
            '<div><dt>Phone</dt><dd>' + esc(o.phone) + '</dd></div>' +
            '<div><dt>Payment</dt><dd>' + esc(o.payment_method) + '</dd></div>' +
            '<div><dt>Total</dt><dd><b>' + money(o.total) + '</b></dd></div>';
          $('#checkoutSuccess').hidden = false;
          window.scrollTo({ top: 0, behavior: 'smooth' });
        })
        .catch(function (err) {
          toast(err.message || 'Network error. Please try again.', 'err');
          btn.disabled = false; btn.innerHTML = label;
        });
    });

    var copy = $('#copyOrderId');
    if (copy) copy.addEventListener('click', function () {
      var id = $('#successOrderId').textContent;
      (navigator.clipboard ? navigator.clipboard.writeText(id) : Promise.reject()).then(
        function () { toast('Order ID copied'); }, function () { toast(id); });
    });
  }

  /* ---------- Banner slider ---------- */
  $$('[data-slider]').forEach(function (sl) {
    var track = sl.querySelector('.slider-track'), n = track.children.length, i = 0, timer;
    var dots = $$('[data-slide-to]', sl);
    function go(k) {
      i = (k + n) % n;
      track.style.transform = 'translateX(' + (-100 * i) + '%)';
      dots.forEach(function (d, j) { d.classList.toggle('is-active', j === i); });
    }
    function auto() { clearInterval(timer); if (n > 1) timer = setInterval(function () { go(i + 1); }, 5000); }
    sl.addEventListener('click', function (ev) {
      var b = ev.target.closest('button'); if (!b) return;
      ev.preventDefault();
      if (b.hasAttribute('data-slide-prev')) go(i - 1);
      else if (b.hasAttribute('data-slide-next')) go(i + 1);
      else if (b.hasAttribute('data-slide-to')) go(+b.getAttribute('data-slide-to'));
      auto();
    });
    var x0 = null;
    sl.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    sl.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 40) { go(i + (dx < 0 ? 1 : -1)); auto(); }
    });
    sl.addEventListener('mouseenter', function () { clearInterval(timer); });
    sl.addEventListener('mouseleave', auto);
    go(0); auto();
  });

  /* ---------- Category rail ---------- */
  $$('[data-rail]').forEach(function (rail) {
    var tr = rail.querySelector('[data-rail-track]'), prev = rail.querySelector('[data-rail-prev]'), next = rail.querySelector('[data-rail-next]');
    function upd() {
      rail.classList.toggle('is-scrollable', tr.scrollWidth > tr.clientWidth + 4);
      prev.disabled = tr.scrollLeft < 4;
      next.disabled = tr.scrollLeft + tr.clientWidth >= tr.scrollWidth - 4;
    }
    prev.addEventListener('click', function () { tr.scrollBy({ left: -tr.clientWidth * .8 }); });
    next.addEventListener('click', function () { tr.scrollBy({ left: tr.clientWidth * .8 }); });
    tr.addEventListener('scroll', upd, { passive: true });
    window.addEventListener('resize', upd);
    upd();
  });

  render();
})();
