/* Nahid Honey — admin behaviours (no dependencies). */
(function () {
  'use strict';

  var doc = document;
  var $ = function (sel, root) { return (root || doc).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); };

  /* ---------- Toasts ---------- */
  function toast(msg, type) {
    type = type || 'success';
    var stack = $('.toast-stack');
    if (!stack) { stack = doc.createElement('div'); stack.className = 'toast-stack'; stack.setAttribute('aria-live', 'polite'); doc.body.appendChild(stack); }
    var icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    var el = doc.createElement('div');
    el.className = 'toast toast-' + type;
    el.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.success) + '"></i><span></span>';
    el.lastChild.textContent = msg;
    stack.appendChild(el);
    setTimeout(function () { el.classList.add('is-hiding'); setTimeout(function () { el.remove(); }, 320); }, 3200);
  }
  window.adminToast = toast;

  /* ---------- CSRF-aware fetch ---------- */
  window.adminFetch = function (url, opts) {
    opts = opts || {};
    var meta = $('meta[name="csrf-token"]');
    var headers = new Headers(opts.headers || {});
    if (meta) headers.set('X-CSRF-Token', meta.getAttribute('content'));
    headers.set('X-Requested-With', 'XMLHttpRequest');
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData) && !(opts.body instanceof URLSearchParams) && !(opts.body instanceof Blob)) {
      headers.set('Content-Type', 'application/json');
      opts.body = JSON.stringify(opts.body);
    }
    opts.headers = headers;
    opts.credentials = opts.credentials || 'same-origin';
    return fetch(url, opts);
  };

  /* ---------- Sidebar (mobile) ---------- */
  function setSidebar(open) {
    var sb = $('#sidebar') || $('.sidebar');
    if (!sb) return;
    sb.classList.toggle('is-open', open);
    doc.body.classList.toggle('sidebar-open', open);
  }

  /* ---------- Dropdowns ---------- */
  function closeDropdowns(except) {
    $$('[data-dropdown]').forEach(function (btn) {
      var dd = dropdownFor(btn);
      if (dd && dd !== except) { dd.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
    });
  }
  function dropdownFor(btn) {
    var sib = btn.nextElementSibling;
    while (sib && !sib.classList.contains('dropdown')) sib = sib.nextElementSibling;
    return sib;
  }

  /* ---------- Modals ---------- */
  var lastFocus = null;
  function openModal(m) {
    if (typeof m === 'string') m = doc.getElementById(m);
    if (!m) return;
    lastFocus = doc.activeElement;
    m.hidden = false;
    doc.body.classList.add('modal-open');
    var f = m.querySelector('[autofocus], input:not([type=hidden]), select, textarea, button');
    if (f) setTimeout(function () { f.focus(); }, 30);
  }
  function closeModal(m) {
    if (!m) return;
    m.hidden = true;
    if (!$$('.modal').some(function (x) { return !x.hidden; })) doc.body.classList.remove('modal-open');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  window.adminModal = { open: openModal, close: function (m) { closeModal(typeof m === 'string' ? doc.getElementById(m) : m); } };

  /* ---------- Confirm dialog ---------- */
  var confirmEl = null;
  function confirmDialog(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!confirmEl) {
        confirmEl = doc.createElement('div');
        confirmEl.className = 'modal modal-confirm';
        confirmEl.hidden = true;
        confirmEl.setAttribute('role', 'dialog');
        confirmEl.setAttribute('aria-modal', 'true');
        confirmEl.innerHTML =
          '<div class="modal-card" style="max-width:440px">' +
          '<div class="modal-body"><span class="confirm-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>' +
          '<div><h3 data-c-title></h3><p data-c-msg></p></div></div>' +
          '<div class="modal-footer"><button type="button" class="btn" data-c-cancel>Cancel</button>' +
          '<button type="button" class="btn btn-danger" data-c-ok></button></div></div>';
        doc.body.appendChild(confirmEl);
      }
      $('[data-c-title]', confirmEl).textContent = opts.title || 'Are you sure?';
      $('[data-c-msg]', confirmEl).textContent = message || 'This action cannot be undone.';
      var ok = $('[data-c-ok]', confirmEl), cancel = $('[data-c-cancel]', confirmEl);
      ok.textContent = opts.ok || 'Confirm';
      ok.className = 'btn ' + (opts.tone === 'primary' ? 'btn-primary' : 'btn-danger');
      function done(v) {
        ok.onclick = cancel.onclick = confirmEl.onclick = null;
        doc.removeEventListener('keydown', onKey, true);
        closeModal(confirmEl);
        resolve(v);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); done(false); }
        if (e.key === 'Enter') { e.preventDefault(); done(true); }
      }
      ok.onclick = function () { done(true); };
      cancel.onclick = function () { done(false); };
      confirmEl.onclick = function (e) { if (e.target === confirmEl) done(false); };
      doc.addEventListener('keydown', onKey, true);
      openModal(confirmEl);
      setTimeout(function () { ok.focus(); }, 40);
    });
  }
  window.adminConfirm = confirmDialog;

  function confirmOpts(el) {
    return { title: el.getAttribute('data-confirm-title') || undefined, ok: el.getAttribute('data-confirm-ok') || undefined, tone: el.getAttribute('data-confirm-tone') || undefined };
  }

  /* ---------- Global click handling ---------- */
  doc.addEventListener('click', function (e) {
    var t = e.target;

    var tog = t.closest('[data-sidebar-toggle]');
    if (tog) { e.preventDefault(); setSidebar(!doc.body.classList.contains('sidebar-open')); return; }
    if (t.closest('[data-sidebar-close]')) { setSidebar(false); return; }

    var ddBtn = t.closest('[data-dropdown]');
    if (ddBtn) {
      e.preventDefault();
      var dd = dropdownFor(ddBtn);
      if (dd) { var open = dd.hidden; closeDropdowns(dd); dd.hidden = !open; ddBtn.setAttribute('aria-expanded', String(open)); }
      return;
    }
    if (!t.closest('.dropdown')) closeDropdowns();

    var mo = t.closest('[data-modal-open]');
    if (mo) { e.preventDefault(); openModal(mo.getAttribute('data-modal-open')); return; }
    var mc = t.closest('[data-modal-close]');
    if (mc) { e.preventDefault(); closeModal(mc.closest('.modal')); return; }
    if (t.classList && t.classList.contains('modal') && t !== confirmEl && !t.hasAttribute('data-static')) { closeModal(t); return; }

    var cp = t.closest('[data-copy]');
    if (cp) {
      e.preventDefault();
      var text = cp.getAttribute('data-copy');
      var fin = function () { toast(cp.getAttribute('data-copy-msg') || 'Copied to clipboard'); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(fin, function () { legacyCopy(text); fin(); });
      else { legacyCopy(text); fin(); }
      return;
    }

    // data-confirm on links and buttons (forms handled on submit)
    var cf = t.closest('a[data-confirm], button[data-confirm], input[type=submit][data-confirm]');
    if (cf && !cf.__confirmed) {
      e.preventDefault();
      e.stopPropagation();
      confirmDialog(cf.getAttribute('data-confirm'), confirmOpts(cf)).then(function (yes) {
        if (!yes) return;
        if (cf.tagName === 'A') { window.location.href = cf.href; return; }
        cf.__confirmed = true;
        var form = cf.form || cf.closest('form');
        if (form) {
          form.__confirmed = true;
          if (form.requestSubmit) form.requestSubmit(cf); else cf.click();
        } else cf.click();
        setTimeout(function () { cf.__confirmed = false; if (form) form.__confirmed = false; }, 0);
      });
    }
  }, true);

  function legacyCopy(text) {
    var ta = doc.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    doc.body.appendChild(ta); ta.select();
    try { doc.execCommand('copy'); } catch (err) { /* ignore */ }
    ta.remove();
  }

  doc.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-confirm') && !form.__confirmed) {
      e.preventDefault();
      var submitter = e.submitter;
      confirmDialog(form.getAttribute('data-confirm'), confirmOpts(form)).then(function (yes) {
        if (!yes) return;
        form.__confirmed = true;
        if (form.requestSubmit) form.requestSubmit(submitter || undefined); else form.submit();
        setTimeout(function () { form.__confirmed = false; }, 0);
      });
      return;
    }
    // Prevent double submits
    var method = ((e.submitter && e.submitter.getAttribute('formmethod')) || form.getAttribute('method') || 'get').toLowerCase();
    var newTab = (e.submitter && e.submitter.getAttribute('formtarget')) || form.getAttribute('target');
    if (!e.defaultPrevented && !newTab && !form.hasAttribute('data-allow-resubmit') && method === 'post') {
      setTimeout(function () {
        $$('button[type=submit], button:not([type])', form).forEach(function (b) { b.classList.add('is-disabled'); });
        setTimeout(function () { $$('.is-disabled', form).forEach(function (b) { b.classList.remove('is-disabled'); }); }, 4000);
      }, 0);
    }
  });

  doc.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = $$('.modal').filter(function (m) { return !m.hidden && m !== confirmEl; });
    if (open.length) { closeModal(open[open.length - 1]); return; }
    closeDropdowns();
    setSidebar(false);
  });

  /* ---------- Change handlers ---------- */
  doc.addEventListener('change', function (e) {
    var t = e.target;

    if (t.matches('[data-check-all]')) {
      var scope = t.closest('table') || t.closest('form') || doc;
      var sel = t.getAttribute('data-check-all');
      var boxes = sel ? $$(sel, scope) : $$('tbody input[type=checkbox]', scope);
      boxes.forEach(function (b) { if (!b.disabled) { b.checked = t.checked; markRow(b); } });
      updateBulk(scope);
      return;
    }
    if (t.matches('tbody input[type=checkbox]')) {
      markRow(t);
      var tbl = t.closest('table');
      if (tbl) {
        var all = $('[data-check-all]', tbl);
        if (all) {
          var bx = $$('tbody input[type=checkbox]', tbl);
          all.checked = bx.length > 0 && bx.every(function (b) { return b.checked; });
          all.indeterminate = !all.checked && bx.some(function (b) { return b.checked; });
        }
        updateBulk(tbl);
      }
    }

    if (t.matches('[data-autosubmit]')) {
      var f = t.form || t.closest('form');
      if (f) { if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
    }

    if (t.matches('input[type=file][data-preview]')) previewFile(t);
  });

  function markRow(cb) { var tr = cb.closest('tr'); if (tr) tr.classList.toggle('is-selected', cb.checked); }

  /** Elements with [data-bulk-count] show how many rows are selected; [data-bulk-show] appear when > 0. */
  function updateBulk(scope) {
    var root = (scope && scope.closest && scope.closest('form, .card')) || doc;
    var n = $$('tbody input[type=checkbox]:checked', root).length;
    $$('[data-bulk-count]', root).forEach(function (el) { el.textContent = n; });
    $$('[data-bulk-show]', root).forEach(function (el) { el.hidden = n === 0; });
  }

  function previewFile(input) {
    var target = $(input.getAttribute('data-preview'));
    var file = input.files && input.files[0];
    if (!target || !file || !/^image\//.test(file.type)) return;
    var reader = new FileReader();
    reader.onload = function (ev) {
      if (target.tagName === 'IMG') { target.src = ev.target.result; target.hidden = false; }
      else target.style.backgroundImage = 'url(' + ev.target.result + ')';
    };
    reader.readAsDataURL(file);
  }

  /* ---------- Dropzone drag styling ---------- */
  ['dragenter', 'dragover'].forEach(function (ev) {
    doc.addEventListener(ev, function (e) { var dz = e.target.closest && e.target.closest('.dropzone'); if (dz) dz.classList.add('is-dragover'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    doc.addEventListener(ev, function (e) { var dz = e.target.closest && e.target.closest('.dropzone'); if (dz) dz.classList.remove('is-dragover'); });
  });

  /* ---------- Auto-hide alerts ---------- */
  function autohide() {
    $$('[data-autohide]').forEach(function (el) {
      var ms = parseInt(el.getAttribute('data-autohide'), 10) || 4500;
      setTimeout(function () {
        el.style.maxHeight = el.offsetHeight + 'px';
        el.classList.add('is-hiding');
        setTimeout(function () { el.style.maxHeight = '0'; el.style.paddingTop = el.style.paddingBottom = '0'; el.style.marginTop = '0'; el.style.borderWidth = '0'; }, 250);
        setTimeout(function () { el.remove(); }, 700);
      }, ms);
    });
  }

  /* ---------- Clickable rows: <tr data-href="..."> ---------- */
  doc.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-href]');
    if (!tr || e.target.closest('a, button, input, select, label, textarea, .check, .actions')) return;
    if (e.metaKey || e.ctrlKey) window.open(tr.getAttribute('data-href'), '_blank');
    else window.location.href = tr.getAttribute('data-href');
  });

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', autohide); else autohide();
})();
