/* Storefront script: cart, wishlist, drawers, quick view, live search, shop filters, sliders.
   No dependencies. Public APIs: window.NHCart, window.NHWishlist, window.NHShop. */
(function () {
  'use strict';

  var CUR = (window.SITE && window.SITE.currency) || '৳';
  var FREE = Number(window.SITE && window.SITE.freeDelivery) || 0;
  var PRODUCTS = window.SITE_PRODUCTS || [];
  var KEY = 'nahid_cart';
  var WKEY = 'nahid_wishlist';
  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var body = document.body;

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
  function emit(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {}
  }
  function stars(r) {
    if (r == null) return '';
    r = Math.max(0, Math.min(5, Number(r)));
    var out = '<span class="stars" role="img" aria-label="Rated ' + r.toFixed(1) + ' out of 5">';
    for (var i = 1; i <= 5; i++) {
      out += '<i class="' + (r >= i - .25 ? 'fa-solid fa-star' : r >= i - .75 ? 'fa-solid fa-star-half-stroke' : 'fa-regular fa-star') + '" aria-hidden="true"></i>';
    }
    return out + '</span>';
  }
  function pURL(p) { return '/product.php?id=' + encodeURIComponent(p.id); }
  function haystack(p) {
    return (p.title + ' ' + (p.category || '') + ' ' + (p.unit || '') + ' ' + (p.search || '')).toLowerCase();
  }
  function matchesQuery(p, q) {
    var hay = haystack(p);
    return q.toLowerCase().split(/\s+/).every(function (w) { return !w || hay.indexOf(w) > -1; });
  }

  /* ---------- Toasts ---------- */
  function toast(msg, type, action) {
    var box = $('#toasts'); if (!box) return;
    var t = document.createElement('div');
    t.className = 'toast toast--' + (type || 'ok');
    t.setAttribute('role', type === 'err' ? 'alert' : 'status');
    t.innerHTML = '<i class="fa-solid ' + (type === 'err' ? 'fa-circle-exclamation' : type === 'info' ? 'fa-heart' : 'fa-circle-check') + '" aria-hidden="true"></i><span>' + esc(msg) + '</span>' +
      (action ? '<button type="button" class="toast-action">' + esc(action.label) + '</button>' : '');
    if (action) t.querySelector('.toast-action').addEventListener('click', function () { action.run(); t.remove(); });
    box.appendChild(t);
    while (box.children.length > 3) box.firstChild.remove();
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 3200);
  }

  /* ---------- Cart (stores only id + qty; details come from the live catalog) ---------- */
  if (window.CHECKOUT) body.classList.add('is-checkout');
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
  function qtyOf(id) { var r = cart.filter(function (x) { return String(x.id) === String(id); })[0]; return r ? r.qty : 0; }
  function maxFor(p) { return p && p.stock != null && p.stock > 0 ? Math.min(99, p.stock) : 99; }

  function add(id, qty) {
    var p = product(id); if (!p || p.in_stock === false) return false;
    qty = Math.max(1, parseInt(qty || 1, 10));
    var row = cart.filter(function (r) { return String(r.id) === String(id); })[0];
    if (row) row.qty = Math.min(maxFor(p), row.qty + qty); else cart.push({ id: p.id, qty: Math.min(maxFor(p), qty) });
    save();
    return p;
  }
  function setQty(id, qty) {
    var p = product(id);
    cart = cart.map(function (r) { if (String(r.id) === String(id)) r.qty = Math.max(1, Math.min(maxFor(p), qty)); return r; });
    save();
  }
  function remove(id) { cart = cart.filter(function (r) { return String(r.id) !== String(id); }); save(); }

  function lineHTML(l) {
    return '<div class="citem" data-line="' + esc(l.p.id) + '">' +
      '<a href="' + pURL(l.p) + '" class="citem-img"><img src="' + esc(l.p.image) + '" alt="" width="64" height="64" loading="lazy"></a>' +
      '<div class="citem-info"><a href="' + pURL(l.p) + '"><strong>' + esc(l.p.title) + '</strong></a>' +
      '<small>' + (l.p.unit ? esc(l.p.unit) + ' · ' : '') + money(l.p.price) + '</small>' +
      '<div class="citem-row"><div class="qty qty--sm">' +
      '<button type="button" data-line-dec aria-label="Decrease"><i class="fa-solid fa-minus"></i></button>' +
      '<input type="number" value="' + l.qty + '" min="1" max="99" data-line-qty aria-label="Quantity">' +
      '<button type="button" data-line-inc aria-label="Increase"><i class="fa-solid fa-plus"></i></button></div>' +
      '<b>' + money(l.total) + '</b></div></div>' +
      '<button type="button" class="icon-btn icon-btn--sm" data-line-remove aria-label="Remove ' + esc(l.p.title) + '"><i class="fa-regular fa-trash-can"></i></button>' +
      '</div>';
  }

  function renderFreeShip(sub) {
    var box = $('#freeShip'); if (!box || !FREE) return;
    var pct = Math.min(100, Math.round(sub / FREE * 100));
    var msg = box.querySelector('[data-freeship-msg]'), bar = box.querySelector('[data-freeship-bar]');
    box.classList.toggle('is-done', sub >= FREE);
    msg.innerHTML = sub >= FREE
      ? '<i class="fa-solid fa-circle-check" aria-hidden="true"></i> You\'ve unlocked <b>free delivery</b>!'
      : 'Add <b>' + money(FREE - sub) + '</b> more for <b>free delivery</b>';
    bar.style.width = pct + '%';
    box.querySelector('[role="progressbar"]').setAttribute('aria-valuenow', pct);
  }

  function renderCTAs() {
    $$('[data-cta]').forEach(function (box) {
      var q = qtyOf(box.getAttribute('data-cta'));
      var addBtn = box.querySelector('[data-add-to-cart]'), stepper = box.querySelector('[data-cta-qty]');
      if (!addBtn || !stepper) return;
      addBtn.hidden = q > 0; stepper.hidden = q === 0;
      if (q) stepper.querySelector('[data-cta-count]').textContent = q;
    });
  }

  function render() {
    var ls = lines(), sub = subtotal();
    $$('[data-cart-count]').forEach(function (el) { el.textContent = count(); });
    $$('[data-cart-total]').forEach(function (el) { el.textContent = money(sub); });
    body.classList.toggle('has-cart', ls.length > 0);

    var box = $('#cartItems');
    if (box) {
      box.innerHTML = ls.length ? ls.map(function (l) { return lineHTML(l); }).join('') :
        '<div class="drawer-empty"><span class="drawer-empty-ico"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i></span>' +
        '<h4>Your cart is empty</h4><p>Looks like you haven\'t added anything yet.</p>' +
        '<a href="/#shop" class="btn btn-primary btn-sm" data-close-cart>Start shopping</a></div>';
    }
    var co = $('#drawerCheckout'); if (co) co.classList.toggle('is-disabled', !ls.length);
    var foot = $('#cartDrawer .drawer-foot'); if (foot) foot.hidden = !ls.length;
    renderFreeShip(sub);
    renderCTAs();
    if (window.CHECKOUT && typeof renderCheckout === 'function') renderCheckout();
    emit('nhcart:change', { count: count(), subtotal: sub });
  }

  /* ---------- Wishlist ---------- */
  var wish = (function () {
    try {
      var raw = JSON.parse(localStorage.getItem(WKEY) || '[]');
      return Array.isArray(raw) ? raw.map(String).filter(function (id, i, a) { return a.indexOf(id) === i && (!PRODUCTS.length || product(id)); }) : [];
    } catch (e) { return []; }
  })();
  function wishHas(id) { return wish.indexOf(String(id)) > -1; }
  function wishSave() {
    try { localStorage.setItem(WKEY, JSON.stringify(wish)); } catch (e) {}
    renderWish();
    emit('nhwishlist:change', { ids: wish.slice(), count: wish.length });
  }
  function wishToggle(id) {
    id = String(id);
    var on = !wishHas(id);
    if (on) wish.push(id); else wish = wish.filter(function (x) { return x !== id; });
    wishSave();
    return on;
  }
  function renderWish() {
    $$('[data-wish-count]').forEach(function (el) {
      el.textContent = wish.length;
      if (el.classList.contains('count-badge')) el.hidden = !wish.length;
    });
    $$('[data-wish]').forEach(function (b) {
      var on = wishHas(b.getAttribute('data-wish'));
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      var i = b.querySelector('i'); if (i) i.className = (on ? 'fa-solid' : 'fa-regular') + ' fa-heart';
    });
    var box = $('#wishItems'); if (!box) return;
    var items = wish.map(product).filter(Boolean);
    box.innerHTML = items.length ? items.map(function (p) {
      return '<div class="citem" data-wish-line="' + esc(p.id) + '">' +
        '<a href="' + pURL(p) + '" class="citem-img"><img src="' + esc(p.image) + '" alt="" width="64" height="64" loading="lazy"></a>' +
        '<div class="citem-info"><a href="' + pURL(p) + '"><strong>' + esc(p.title) + '</strong></a>' +
        '<small>' + (p.unit ? esc(p.unit) + ' · ' : '') + '<b class="citem-price">' + money(p.price) + '</b>' +
        (p.original_price ? ' <s>' + money(p.original_price) + '</s>' : '') + '</small>' +
        '<div class="citem-row">' + (p.in_stock !== false
          ? '<button type="button" class="btn btn-primary btn-xs" data-add-to-cart="' + esc(p.id) + '"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Add to cart</button>'
          : '<span class="citem-out">Out of stock</span>') + '</div></div>' +
        '<button type="button" class="icon-btn icon-btn--sm" data-wish="' + esc(p.id) + '" aria-label="Remove from wishlist"><i class="fa-solid fa-heart"></i></button>' +
        '</div>';
    }).join('') :
      '<div class="drawer-empty"><span class="drawer-empty-ico"><i class="fa-regular fa-heart" aria-hidden="true"></i></span>' +
      '<h4>Your wishlist is empty</h4><p>Tap the heart on any product to save it for later.</p>' +
      '<a href="/#shop" class="btn btn-primary btn-sm" data-close-panel>Discover products</a></div>';
  }

  /* ---------- Panels: drawers, off-canvas menu, mobile filters ---------- */
  var overlay = $('.drawer-overlay'), openId = null, lastFocus = null;
  function setExpanded(id, on) {
    $$('[data-open-panel="' + id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', on ? 'true' : 'false'); });
  }
  function openPanel(id, focusSel) {
    var el = document.getElementById(id); if (!el) return;
    if (id === 'cartDrawer' && window.CHECKOUT) return;
    closeModal();
    if (openId && openId !== id) closePanels(true);
    if (!openId) lastFocus = document.activeElement;
    openId = id;
    if (overlay) overlay.hidden = false;
    if (!el.hasAttribute('data-shop-form')) el.setAttribute('aria-hidden', 'false');
    setExpanded(id, true);
    requestAnimationFrame(function () {
      body.classList.add('panel-open');
      if (id === 'cartDrawer') body.classList.add('cart-open');
      el.classList.add('is-open');
    });
    setTimeout(function () {
      var f = focusSel ? $(focusSel) : null;
      var first = f ? f.querySelector('a, button, input') || f : el;
      try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
    }, 80);
  }
  function closePanels(keepOverlay) {
    if (!openId) return;
    var el = document.getElementById(openId);
    if (el) { el.classList.remove('is-open'); if (!el.hasAttribute('data-shop-form')) el.setAttribute('aria-hidden', 'true'); }
    setExpanded(openId, false);
    openId = null;
    body.classList.remove('cart-open');
    if (keepOverlay === true) return;
    body.classList.remove('panel-open');
    setTimeout(function () { if (!openId && overlay) overlay.hidden = true; }, 300);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
  }
  function openCart() { openPanel('cartDrawer'); }
  function closeCart() { if (openId === 'cartDrawer') closePanels(); }

  /* Keep keyboard focus inside the open panel / modal */
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Tab') return;
    var scope = modalOpen ? $('#quickView .modal-card') : openId ? document.getElementById(openId) : null;
    if (!scope) return;
    var f = $$('a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])', scope)
      .filter(function (x) { return x.offsetParent !== null; });
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (ev.shiftKey && (document.activeElement === first || !scope.contains(document.activeElement))) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && (document.activeElement === last || !scope.contains(document.activeElement))) { ev.preventDefault(); first.focus(); }
  });

  /* ---------- Quick view ---------- */
  var modal = $('#quickView'), modalOpen = false, modalReturn = null;
  function quickView(id) {
    var p = product(id); if (!p || !modal) return;
    closePanels();
    var imgs = (p.images && p.images.length ? p.images : [p.image]);
    var avail = p.in_stock !== false;
    var saveAmt = p.original_price ? p.original_price - p.price : 0;
    $('#qvBody').innerHTML =
      '<div class="qv">' +
        '<div class="qv-gallery"><div class="qv-main">' +
          (p.discount ? '<span class="flag flag--off">-' + p.discount + '%</span>' : '') +
          '<img id="qvImg" src="' + esc(imgs[0]) + '" alt="' + esc(p.title) + '" width="600" height="600"></div>' +
          (imgs.length > 1 ? '<div class="qv-thumbs">' + imgs.map(function (src, i) {
            return '<button type="button" class="' + (i ? '' : 'is-active') + '" data-qv-thumb="' + esc(src) + '" aria-label="Image ' + (i + 1) + '"><img src="' + esc(src) + '" alt="" width="64" height="64"></button>';
          }).join('') + '</div>' : '') +
        '</div>' +
        '<div class="qv-info">' +
          (p.category ? '<span class="pcard-cat">' + esc(p.category) + '</span>' : '') +
          '<h2 id="qvTitle">' + esc(p.title) + '</h2>' +
          (p.rating != null ? '<div class="qv-rating">' + stars(p.rating) + ' <b>' + Number(p.rating).toFixed(1) + '</b>' + (p.reviews_count ? ' <span>(' + p.reviews_count + ' reviews)</span>' : '') + '</div>' : '') +
          '<div class="price-row price-row--lg"><span class="price">' + money(p.price) + '</span>' +
            (p.original_price ? '<s class="price-old">' + money(p.original_price) + '</s>' : '') +
            (p.discount_text ? '<span class="save-pill">' + esc(p.discount_text) + '</span>' : saveAmt > 0 ? '<span class="save-pill">Save ' + money(saveAmt) + '</span>' : '') + '</div>' +
          '<ul class="qv-facts">' +
            (p.unit ? '<li><span>Size</span><b>' + esc(p.unit) + '</b></li>' : '') +
            '<li><span>Availability</span><b class="' + (avail ? 'ok' : 'no') + '">' + (avail ? (p.stock != null && p.stock <= 5 ? 'Only ' + p.stock + ' left' : 'In stock') : 'Out of stock') + '</b></li>' +
          '</ul>' +
          (p.short ? '<p class="qv-desc">' + esc(p.short) + '</p>' : '') +
          (avail
            ? '<div class="qv-buy"><div class="qty"><button type="button" data-qty-dec aria-label="Decrease"><i class="fa-solid fa-minus"></i></button>' +
              '<input type="number" id="qvQty" value="1" min="1" max="' + maxFor(p) + '" aria-label="Quantity">' +
              '<button type="button" data-qty-inc aria-label="Increase"><i class="fa-solid fa-plus"></i></button></div>' +
              '<button type="button" class="btn btn-primary btn-lg" data-add-to-cart="' + esc(p.id) + '" data-qty-from="#qvQty" data-then-close><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Add to cart</button></div>' +
              '<button type="button" class="btn btn-dark btn-lg btn-block" data-buy-now="' + esc(p.id) + '" data-qty-from="#qvQty"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Buy now</button>'
            : '<button type="button" class="btn btn-soldout btn-lg btn-block" disabled>Out of stock</button>') +
          '<div class="qv-foot"><button type="button" class="linklike" data-wish="' + esc(p.id) + '"><i class="fa-regular fa-heart"></i> <span>Save to wishlist</span></button>' +
          '<a href="' + pURL(p) + '" class="qv-link">View full details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div>' +
        '</div>' +
      '</div>';
    renderWish();
    modalReturn = document.activeElement;
    modal.hidden = false;
    body.classList.add('modal-open');
    requestAnimationFrame(function () { modal.classList.add('is-open'); });
    modalOpen = true;
    setTimeout(function () { var c = modal.querySelector('.modal-close'); if (c) c.focus(); }, 60);
  }
  function closeModal() {
    if (!modalOpen) return;
    modalOpen = false;
    modal.classList.remove('is-open');
    body.classList.remove('modal-open');
    setTimeout(function () { if (!modalOpen) modal.hidden = true; }, 250);
    if (modalReturn && modalReturn.focus) { try { modalReturn.focus({ preventScroll: true }); } catch (e) {} }
  }

  function qtyFrom(btn) {
    var sel = btn.getAttribute('data-qty-from');
    var el = sel && $(sel);
    return el ? parseInt(el.value, 10) || 1 : 1;
  }

  /* ---------- Click delegation ---------- */
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('button, a, [data-close-cart], [data-close-panel], [data-close-modal]');
    if (!t) return;

    if (t.hasAttribute('data-open-cart')) { ev.preventDefault(); if (window.CHECKOUT) { window.location.href = '/checkout.php'; return; } openCart(); return; }
    if (t.hasAttribute('data-open-panel')) { ev.preventDefault(); var pid = t.getAttribute('data-open-panel'); if (openId === pid) closePanels(); else openPanel(pid, t.getAttribute('data-focus')); return; }
    if (t.hasAttribute('data-close-modal')) { closeModal(); return; }
    if (t.hasAttribute('data-close-cart') || t.hasAttribute('data-close-panel')) { closePanels(); if (t.tagName !== 'A') return; }

    if (t.hasAttribute('data-wish')) {
      ev.preventDefault();
      var wid = t.getAttribute('data-wish'), on = wishToggle(wid);
      t.classList.add('is-pop'); setTimeout(function () { t.classList.remove('is-pop'); }, 350);
      toast(on ? 'Saved to wishlist' : 'Removed from wishlist', 'info', on ? null : { label: 'Undo', run: function () { wishToggle(wid); } });
      return;
    }
    if (t.hasAttribute('data-quick-view')) { ev.preventDefault(); quickView(t.getAttribute('data-quick-view')); return; }
    if (t.hasAttribute('data-qv-thumb')) {
      var qi = $('#qvImg'); if (qi) qi.src = t.getAttribute('data-qv-thumb');
      $$('[data-qv-thumb]').forEach(function (b) { b.classList.toggle('is-active', b === t); });
      return;
    }

    if (t.hasAttribute('data-add-to-cart')) {
      var p = add(t.getAttribute('data-add-to-cart'), qtyFrom(t));
      if (p) {
        t.classList.add('is-pulse'); setTimeout(function () { t.classList.remove('is-pulse'); }, 400);
        if (t.hasAttribute('data-then-close')) closeModal();
        toast('Added to cart', 'ok', window.CHECKOUT ? null : { label: 'View cart', run: openCart });
      } else toast('Sorry, this product is unavailable', 'err');
      return;
    }
    if (t.hasAttribute('data-buy-now')) {
      if (add(t.getAttribute('data-buy-now'), qtyFrom(t))) window.location.href = '/checkout.php';
      return;
    }

    var cta = t.closest('[data-cta]');
    if (cta && (t.hasAttribute('data-cta-inc') || t.hasAttribute('data-cta-dec'))) {
      var cid = cta.getAttribute('data-cta'), cq = qtyOf(cid);
      if (t.hasAttribute('data-cta-inc')) {
        if (cq >= maxFor(product(cid))) { toast('No more stock available', 'err'); return; }
        setQty(cid, cq + 1);
      } else if (cq <= 1) remove(cid); else setQty(cid, cq - 1);
      return;
    }

    var line = t.closest('[data-line]');
    if (line) {
      var id = line.getAttribute('data-line');
      var row = cart.filter(function (r) { return String(r.id) === String(id); })[0];
      if (!row) return;
      if (t.hasAttribute('data-line-inc')) setQty(id, row.qty + 1);
      else if (t.hasAttribute('data-line-dec')) { if (row.qty <= 1) remove(id); else setQty(id, row.qty - 1); }
      else if (t.hasAttribute('data-line-remove')) {
        remove(id);
        toast('Removed from cart', 'ok', { label: 'Undo', run: function () { add(id, row.qty); } });
      }
      return;
    }

    if (t.hasAttribute('data-qty-inc') || t.hasAttribute('data-qty-dec')) {
      var input = t.parentNode.querySelector('input');
      var mx = parseInt(input.getAttribute('max'), 10) || 99;
      var v = (parseInt(input.value, 10) || 1) + (t.hasAttribute('data-qty-inc') ? 1 : -1);
      input.value = Math.max(1, Math.min(mx, v));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    if (t.hasAttribute('data-thumb')) {
      var main = $('#pdMainImg'); if (main) main.src = t.getAttribute('data-thumb');
      $$('[data-thumb]').forEach(function (b) { b.classList.toggle('is-active', b === t); });
    }
  });

  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t.hasAttribute && t.hasAttribute('data-line-qty')) {
      setQty(t.closest('[data-line]').getAttribute('data-line'), parseInt(t.value, 10) || 1);
    }
    if (window.CHECKOUT && t.name === 'zone' && typeof renderCheckout === 'function') renderCheckout();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    if (modalOpen) closeModal(); else if (openId) closePanels();
    closeMega();
  });

  /* ---------- Live search ---------- */
  var sInput = $('#searchInput'), sBox = $('#searchResults'), sForm = sInput && sInput.closest('form');
  if (sInput && sBox) {
    var timer, active = -1;
    var hide = function () { sBox.hidden = true; sInput.setAttribute('aria-expanded', 'false'); active = -1; };
    var highlight = function (text, q) {
      var s = esc(text);
      q.split(/\s+/).filter(function (w) { return w.length > 1; }).forEach(function (w) {
        s = s.replace(new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>');
      });
      return s;
    };
    var run = function () {
      var q = sInput.value.trim();
      if (q.length < 2) { hide(); return; }
      var hits = PRODUCTS.filter(function (p) { return matchesQuery(p, q); });
      var cats = [];
      hits.forEach(function (p) { if (p.category && cats.indexOf(p.category) < 0) cats.push(p.category); });
      var catSlug = function (name) { var p = PRODUCTS.filter(function (x) { return x.category === name; })[0]; return p ? p.category_slug : ''; };
      sBox.innerHTML = hits.length
        ? (cats.length ? '<div class="sres-cats">' + cats.slice(0, 4).map(function (c) {
            return '<a href="/?category=' + encodeURIComponent(catSlug(c)) + '"><i class="fa-solid fa-tag" aria-hidden="true"></i> ' + esc(c) + '</a>';
          }).join('') + '</div>' : '') +
          hits.slice(0, 6).map(function (p) {
            return '<a href="' + pURL(p) + '" class="sres" role="option"><img src="' + esc(p.image) + '" alt="" width="48" height="48" loading="lazy">' +
              '<span><strong>' + highlight(p.title, q) + '</strong><small>' + esc(p.unit || p.category || '') + (p.in_stock === false ? ' · Out of stock' : '') + '</small></span>' +
              '<span class="sres-price"><b>' + money(p.price) + '</b>' + (p.original_price ? '<s>' + money(p.original_price) + '</s>' : '') + '</span></a>';
          }).join('') +
          '<button type="submit" class="sres-all">See all ' + hits.length + ' result' + (hits.length === 1 ? '' : 's') + ' <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>'
        : '<div class="sres-none"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> No products match “' + esc(q) + '”</div>';
      sBox.hidden = false; sInput.setAttribute('aria-expanded', 'true'); active = -1;
    };
    sInput.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 110); });
    sInput.addEventListener('focus', function () { if (sInput.value.trim().length > 1) run(); sForm.classList.add('is-focus'); });
    sInput.addEventListener('blur', function () { sForm.classList.remove('is-focus'); });
    sInput.addEventListener('keydown', function (ev) {
      var opts = $$('.sres', sBox);
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        if (sBox.hidden || !opts.length) return;
        ev.preventDefault();
        active = (active + (ev.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
        opts.forEach(function (o, i) { o.classList.toggle('is-active', i === active); o.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
        opts[active].scrollIntoView({ block: 'nearest' });
      } else if (ev.key === 'Enter' && active > -1 && opts[active]) {
        ev.preventDefault(); window.location.href = opts[active].getAttribute('href');
      } else if (ev.key === 'Escape') hide();
    });
    document.addEventListener('click', function (ev) { if (!ev.target.closest('.search')) hide(); });
  }

  /* ---------- Checkout ---------- */
  /* Checkout page logic lives in assets/js/pages.js (listens for nhcart:change). */

  /* ---------- Banner slider ---------- */
  $$('[data-slider]').forEach(function (sl) {
    var track = sl.querySelector('.slider-track'), n = track.children.length, i = 0, timer;
    var dots = $$('[data-slide-to]', sl), slides = Array.prototype.slice.call(track.children);
    var DELAY = 5500;
    function go(k) {
      i = (k + n) % n;
      track.style.transform = 'translateX(' + (-100 * i) + '%)';
      dots.forEach(function (d, j) { d.classList.toggle('is-active', j === i); d.setAttribute('aria-current', j === i ? 'true' : 'false'); });
      slides.forEach(function (s, j) { s.setAttribute('aria-hidden', j === i ? 'false' : 'true'); s.tabIndex = j === i ? 0 : -1; });
      sl.classList.remove('is-ticking'); void sl.offsetWidth; if (timer) sl.classList.add('is-ticking');
    }
    function stop() { clearInterval(timer); timer = null; sl.classList.remove('is-ticking'); }
    function auto() {
      stop();
      if (n > 1 && !REDUCED) { timer = setInterval(function () { go(i + 1); }, DELAY); sl.style.setProperty('--slide-delay', DELAY + 'ms'); sl.classList.add('is-ticking'); }
    }
    sl.addEventListener('click', function (ev) {
      var b = ev.target.closest('button'); if (!b) return;
      ev.preventDefault();
      if (b.hasAttribute('data-slide-prev')) go(i - 1);
      else if (b.hasAttribute('data-slide-next')) go(i + 1);
      else if (b.hasAttribute('data-slide-to')) go(+b.getAttribute('data-slide-to'));
      auto();
    });
    sl.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowLeft') { go(i - 1); auto(); } else if (ev.key === 'ArrowRight') { go(i + 1); auto(); }
    });
    var x0 = null, y0 = null;
    sl.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
    sl.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0; x0 = null;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) { go(i + (dx < 0 ? 1 : -1)); auto(); }
    });
    sl.addEventListener('mouseenter', stop);
    sl.addEventListener('mouseleave', auto);
    sl.addEventListener('focusin', stop);
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else auto(); });
    go(0); auto();
  });

  /* ---------- Horizontal rails (categories, product rows, reviews) ---------- */
  $$('[data-rail]').forEach(function (rail) {
    var tr = rail.querySelector('[data-rail-track]'), prev = rail.querySelector('[data-rail-prev]'), next = rail.querySelector('[data-rail-next]');
    if (!tr) return;
    function upd() {
      rail.classList.toggle('is-scrollable', tr.scrollWidth > tr.clientWidth + 4);
      if (prev) prev.disabled = tr.scrollLeft < 4;
      if (next) next.disabled = tr.scrollLeft + tr.clientWidth >= tr.scrollWidth - 4;
    }
    var step = function () { return Math.max(200, tr.clientWidth * .85); };
    if (prev) prev.addEventListener('click', function () { tr.scrollBy({ left: -step(), behavior: REDUCED ? 'auto' : 'smooth' }); });
    if (next) next.addEventListener('click', function () { tr.scrollBy({ left: step(), behavior: REDUCED ? 'auto' : 'smooth' }); });
    tr.addEventListener('scroll', upd, { passive: true });
    window.addEventListener('resize', upd);
    upd();
  });

  /* ---------- Header shrink, back to top ---------- */
  var toTop = $('#toTop'), ticking = false;
  function onScroll() {
    var y = window.pageYOffset || document.documentElement.scrollTop;
    body.classList.toggle('is-scrolled', y > 80);
    if (toTop) toTop.classList.toggle('is-visible', y > 700);
    ticking = false;
  }
  window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(onScroll); } }, { passive: true });
  onScroll();
  if (toTop) toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: REDUCED ? 'auto' : 'smooth' }); });

  /* ---------- Mega menu ---------- */
  var mega = $('[data-mega]'), megaBtn = mega && mega.querySelector('.mega-toggle');
  function closeMega() { if (!mega) return; mega.classList.remove('is-open'); megaBtn.setAttribute('aria-expanded', 'false'); }
  if (mega) {
    megaBtn.addEventListener('click', function () {
      var on = !mega.classList.contains('is-open');
      mega.classList.toggle('is-open', on); megaBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    });
    document.addEventListener('click', function (ev) { if (!ev.target.closest('[data-mega]')) closeMega(); });
    mega.addEventListener('focusout', function (ev) { if (!mega.contains(ev.relatedTarget)) closeMega(); });
  }

  /* ---------- Image fade-in once loaded ---------- */
  $$('img[loading="lazy"]').forEach(function (img) { if (img.complete) img.classList.add('is-loaded'); });

  /* ---------- Skip link target on every page ---------- */
  if (!$('#main')) { var m = $('main'); if (m) m.id = 'main'; }

  /* ---------- Shop: filters, sort, load more (home #shop and listing pages) ---------- */
  var shop = $('[data-shop]');
  var NHShop = null;
  if (shop) (function () {
    var grid = $('[data-shop-grid]', shop), form = $('[data-shop-form]', shop);
    var cards = grid ? $$('.pcard', grid) : [];
    var pageSize = parseInt(shop.getAttribute('data-page-size'), 10) || 12;
    var shown = pageSize;
    var orderIndex = {};
    PRODUCTS.forEach(function (p, i) { orderIndex[String(p.id)] = i; });
    var titleEl = $('.shop-title', shop), isListing = shop.classList.contains('shop--listing');
    var els = {
      q: $('[data-shop-q]', shop), sort: $('[data-shop-sort]', shop), min: $('[data-shop-min]', shop),
      max: $('[data-shop-max]', shop), stock: $('[data-shop-stock]', shop),
      count: $('[data-shop-count]', shop), empty: $('[data-shop-empty]', shop), chips: $('[data-shop-chips]', shop),
      moreWrap: $('[data-shop-more-wrap]', shop), more: $('[data-shop-more]', shop),
      progress: $('[data-shop-progress]', shop), bar: $('[data-shop-bar]', shop), apply: $('[data-shop-apply]', shop)
    };
    function catName(slug) { var p = PRODUCTS.filter(function (x) { return x.category_slug === slug; })[0]; return p ? p.category : slug; }
    function state() {
      var cat = form ? form.querySelector('[data-shop-cat]:checked') : null;
      var num = function (el) { return el && el.value !== '' && !isNaN(el.value) ? Number(el.value) : null; };
      return {
        q: els.q ? els.q.value.trim() : '', category: cat ? cat.value : '', sort: els.sort ? els.sort.value : '',
        min: num(els.min), max: num(els.max), instock: !!(els.stock && els.stock.checked)
      };
    }
    function score(p) { return (p.reviews_count || 0) * (p.rating || 0); }
    var sorters = {
      '': function (a, b) { return orderIndex[a.id] - orderIndex[b.id]; },
      newest: function (a, b) { return (parseInt(b.id, 10) || 0) - (parseInt(a.id, 10) || 0); },
      popular: function (a, b) { return score(b) - score(a) || orderIndex[a.id] - orderIndex[b.id]; },
      price_asc: function (a, b) { return a.price - b.price; },
      price_desc: function (a, b) { return b.price - a.price; },
      discount: function (a, b) { return (b.discount || 0) - (a.discount || 0) || orderIndex[a.id] - orderIndex[b.id]; }
    };
    function syncURL(s) {
      if (!window.history || !history.replaceState) return;
      var u = new URLSearchParams();
      if (s.q) u.set('q', s.q);
      if (s.category) u.set('category', s.category);
      if (s.sort) u.set('sort', s.sort);
      if (s.min != null) u.set('min', s.min);
      if (s.max != null) u.set('max', s.max);
      if (s.instock) u.set('instock', '1');
      var qs = u.toString();
      history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
    }
    function chips(s) {
      if (!els.chips) return;
      var c = [];
      if (s.q) c.push(['q', 'Search: “' + s.q + '”']);
      if (s.category) c.push(['category', catName(s.category)]);
      if (s.min != null || s.max != null) c.push(['price', (s.min != null ? money(s.min) : money(0)) + ' – ' + (s.max != null ? money(s.max) : 'any')]);
      if (s.instock) c.push(['instock', 'In stock only']);
      els.chips.innerHTML = c.map(function (x) {
        return '<button type="button" class="chip" data-chip="' + x[0] + '">' + esc(x[1]) + ' <i class="fa-solid fa-xmark" aria-hidden="true"></i><span class="sr-only">Remove filter</span></button>';
      }).join('') + (c.length > 1 ? '<button type="button" class="chip chip--clear" data-chip="all">Clear all</button>' : '');
    }
    function apply(resetPage) {
      if (!grid) return;
      if (resetPage) shown = pageSize;
      var s = state();
      var byId = {};
      cards.forEach(function (c) { byId[c.getAttribute('data-pid')] = c; });
      var list = PRODUCTS.filter(function (p) {
        if (!byId[String(p.id)]) return false;
        if (s.category && p.category_slug !== s.category) return false;
        if (s.min != null && p.price < s.min) return false;
        if (s.max != null && p.price > s.max) return false;
        if (s.instock && p.in_stock === false) return false;
        if (s.q && !matchesQuery(p, s.q + '')) return false;
        return true;
      });
      list.sort(sorters[s.sort] || sorters['']);
      var matched = {};
      list.forEach(function (p, i) { matched[String(p.id)] = i; });
      var frag = document.createDocumentFragment();
      list.forEach(function (p) { frag.appendChild(byId[String(p.id)]); });
      cards.forEach(function (c) { if (!(c.getAttribute('data-pid') in matched)) frag.appendChild(c); });
      grid.appendChild(frag);
      cards.forEach(function (c) {
        var idx = matched[c.getAttribute('data-pid')];
        c.hidden = idx === undefined || idx >= shown;
      });
      var total = list.length;
      if (els.count) {
        els.count.textContent = total;
        var label = els.count.parentNode; if (label) label.lastChild.textContent = ' product' + (total === 1 ? '' : 's') + ' found';
      }
      if (els.empty) els.empty.hidden = total > 0;
      grid.hidden = total === 0;
      if (els.moreWrap) {
        var vis = Math.min(shown, total);
        els.moreWrap.hidden = total <= pageSize && vis >= total;
        els.more.hidden = vis >= total;
        els.progress.textContent = 'Showing ' + vis + ' of ' + total + ' products';
        els.bar.style.width = (total ? Math.round(vis / total * 100) : 0) + '%';
      }
      if (els.apply) els.apply.textContent = total ? 'Show ' + total + ' result' + (total === 1 ? '' : 's') : 'No results';
      if (titleEl && isListing) titleEl.textContent = s.q ? 'Results for “' + s.q + '”' : s.category ? catName(s.category) : 'All Products';
      chips(s);
      syncURL(s);
      emit('nhshop:change', { total: total, state: s });
    }
    if (form) {
      var qTimer;
      form.addEventListener('input', function (ev) {
        if (ev.target === els.q || ev.target === els.min || ev.target === els.max) { clearTimeout(qTimer); qTimer = setTimeout(function () { apply(true); }, 220); }
      });
      form.addEventListener('change', function (ev) { if (ev.target !== els.q) apply(true); });
      form.addEventListener('submit', function (ev) {
        ev.preventDefault(); apply(true);
        if (openId === form.id) closePanels();
        shop.scrollIntoView({ behavior: REDUCED ? 'auto' : 'smooth', block: 'start' });
      });
    }
    if (els.sort) els.sort.addEventListener('change', function () { apply(true); });
    function reset() {
      if (els.q) els.q.value = '';
      if (els.min) els.min.value = '';
      if (els.max) els.max.value = '';
      if (els.stock) els.stock.checked = false;
      var all = form && form.querySelector('[data-shop-cat][value=""]'); if (all) all.checked = true;
      apply(true);
    }
    shop.addEventListener('click', function (ev) {
      var t = ev.target.closest('[data-chip], [data-shop-reset], [data-shop-more]');
      if (!t) return;
      if (t.hasAttribute('data-shop-more')) { shown += pageSize; apply(false); return; }
      ev.preventDefault();
      if (t.hasAttribute('data-shop-reset')) { reset(); return; }
      var k = t.getAttribute('data-chip');
      if (k === 'all') { reset(); return; }
      if (k === 'q' && els.q) els.q.value = '';
      if (k === 'category') { var a = form.querySelector('[data-shop-cat][value=""]'); if (a) a.checked = true; }
      if (k === 'price') { els.min.value = ''; els.max.value = ''; }
      if (k === 'instock') els.stock.checked = false;
      apply(true);
    });
    NHShop = { apply: function () { apply(true); }, reset: reset, state: state };
    apply(true);
  })();
  window.NHShop = NHShop;

  /* ---------- Public APIs (used by assets/js/pages.js) ---------- */
  window.NHCart = {
    add: add, setQty: setQty, remove: remove, lines: lines, subtotal: subtotal, count: count,
    items: function () { return cart.map(function (r) { return { id: r.id, quantity: r.qty }; }); },
    clear: function () { cart = []; save(); },
    open: openCart, close: closeCart, product: product,
    money: money, esc: esc, toast: toast,
    qty: qtyOf, render: render, stars: stars, quickView: quickView,
    openPanel: openPanel, closePanels: closePanels
  };
  window.NHWishlist = {
    toggle: wishToggle, has: wishHas, ids: function () { return wish.slice(); }, count: function () { return wish.length; }
  };

  render();
  renderWish();
  document.dispatchEvent(new CustomEvent('nhcart:ready'));
})();
