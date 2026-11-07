<?php
/**
 * Store settings. Defaults live in config/site.php; this page saves overrides
 * to setting('site'). Each tab only writes its own keys, and "Reset" removes a
 * tab's overrides so the defaults apply again. category_images is managed on
 * the Categories page and never touched here.
 */
require __DIR__ . '/inc/admin.php';
require_perm('settings.manage');

$DEFAULTS = require __DIR__ . '/../../config/site.php';
$saved = (array)setting('site', []);

$TABS = [
    'general'       => ['General',          'fa-store',         ['brand_name', 'tagline', 'logo_url', 'announcement', 'meta_description', 'currency_symbol']],
    'contact'       => ['Contact & social', 'fa-address-book',  ['phone', 'whatsapp', 'email', 'address', 'facebook_url', 'instagram_url', 'youtube_url']],
    'homepage'      => ['Homepage',         'fa-house',         ['banners', 'side_banner', 'hero', 'trust_items']],
    'delivery'      => ['Delivery',         'fa-truck-fast',    ['delivery_zones', 'free_delivery_threshold']],
    'payments'      => ['Payments',         'fa-wallet',        ['payment_methods', 'payment_instructions']],
    'notifications' => ['Notifications',    'fa-bell',          ['order_notify_telegram']],
];
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'general');
if (!isset($TABS[$tab])) $tab = 'general';

/** '' or http(s) URL or a site-relative path like assets/img/x.png or /index.php?x=1 */
function settings_link_ok(string $v): bool {
    if ($v === '') return true;
    if (preg_match('#^https?://#i', $v)) return (bool)filter_var($v, FILTER_VALIDATE_URL);
    return !str_starts_with($v, '//') && (bool)preg_match('#^[A-Za-z0-9_./\-?=&%\#~+]+$#', $v);
}
function settings_key(string $v): string {
    return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($v))), '_');
}
function settings_txt(string $k, int $max = 300): string {
    return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
}
function settings_rows(string $k): array {
    $r = $_POST[$k] ?? [];
    return is_array($r) ? array_values(array_filter($r, 'is_array')) : [];
}

/** Parse + validate one tab's form. Returns [values, errors]. */
function settings_parse(string $tab): array {
    $v = []; $err = [];
    switch ($tab) {
        case 'general':
            $v['brand_name'] = settings_txt('brand_name', 80);
            $v['tagline'] = settings_txt('tagline', 160);
            $v['logo_url'] = settings_txt('logo_url', 500);
            $v['announcement'] = settings_txt('announcement', 200);
            $v['meta_description'] = settings_txt('meta_description', 300);
            $v['currency_symbol'] = settings_txt('currency_symbol', 8);
            if ($v['brand_name'] === '') $err['brand_name'] = 'The store name is required.';
            if (!settings_link_ok($v['logo_url'])) $err['logo_url'] = 'Use a full https:// link or a path like assets/img/logo.png.';
            if ($v['currency_symbol'] === '') $err['currency_symbol'] = 'Enter a currency symbol, e.g. ৳.';
            break;

        case 'contact':
            foreach (['phone', 'email', 'address', 'facebook_url', 'instagram_url', 'youtube_url'] as $k) $v[$k] = settings_txt($k, $k === 'address' ? 300 : 200);
            $v['whatsapp'] = preg_replace('/\D/', '', settings_txt('whatsapp', 30));
            if ($v['phone'] !== '' && !preg_match('/^\+?[0-9 \-]{6,20}$/', $v['phone'])) $err['phone'] = 'Enter a valid phone number.';
            if ($v['whatsapp'] !== '' && strlen($v['whatsapp']) < 10) $err['whatsapp'] = 'Digits with country code, e.g. 8801XXXXXXXXX.';
            if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err['email'] = 'Enter a valid email address.';
            foreach (['facebook_url', 'instagram_url', 'youtube_url'] as $k) {
                if ($v[$k] !== '' && (!preg_match('#^https?://#i', $v[$k]) || !filter_var($v[$k], FILTER_VALIDATE_URL))) $err[$k] = 'Enter a full link starting with https://';
            }
            break;

        case 'homepage':
            $v['banners'] = [];
            foreach (settings_rows('banners') as $i => $b) {
                $row = ['image' => mb_substr(trim((string)($b['image'] ?? '')), 0, 500), 'link' => mb_substr(trim((string)($b['link'] ?? '')), 0, 500), 'alt' => mb_substr(trim((string)($b['alt'] ?? '')), 0, 160)];
                if ($row['image'] === '' && $row['link'] === '' && $row['alt'] === '') continue;
                if ($row['image'] === '' || !settings_link_ok($row['image'])) $err['banners'] = 'Every banner needs a valid image URL or path.';
                if (!settings_link_ok($row['link'])) $err['banners'] = 'Banner links must be a full https:// link or a site path.';
                $v['banners'][] = $row;
            }
            $sb = (array)($_POST['side_banner'] ?? []);
            $side = ['image' => trim((string)($sb['image'] ?? '')), 'link' => trim((string)($sb['link'] ?? '')), 'alt' => trim((string)($sb['alt'] ?? ''))];
            if ($side['image'] === '') $side = [];
            elseif (!settings_link_ok($side['image']) || !settings_link_ok($side['link'])) $err['side_banner'] = 'Use a valid image and link for the side banner.';
            $v['side_banner'] = $side;
            $h = (array)($_POST['hero'] ?? []);
            $v['hero'] = [];
            foreach (['eyebrow' => 60, 'title' => 120, 'subtitle' => 300, 'cta_label' => 40, 'image' => 500] as $k => $max) $v['hero'][$k] = mb_substr(trim((string)($h[$k] ?? '')), 0, $max);
            if (!settings_link_ok($v['hero']['image'])) $err['hero'] = 'The hero image must be a valid URL or path.';
            $v['trust_items'] = [];
            foreach (settings_rows('trust_items') as $t) {
                $row = ['icon' => preg_replace('/[^a-z0-9\- ]/', '', strtolower(trim((string)($t['icon'] ?? '')))), 'title' => mb_substr(trim((string)($t['title'] ?? '')), 0, 60), 'text' => mb_substr(trim((string)($t['text'] ?? '')), 0, 120)];
                if ($row['title'] === '' && $row['text'] === '') continue;
                if ($row['title'] === '') $err['trust_items'] = 'Each promise needs a title.';
                $v['trust_items'][] = $row;
            }
            break;

        case 'delivery':
            $v['delivery_zones'] = [];
            foreach (settings_rows('delivery_zones') as $z) {
                $label = mb_substr(trim((string)($z['label'] ?? '')), 0, 80);
                $key = settings_key((string)($z['key'] ?? '')) ?: settings_key($label);
                $fee = trim((string)($z['fee'] ?? ''));
                if ($label === '' && $fee === '') continue;
                if ($label === '' || $key === '') { $err['delivery_zones'] = 'Each zone needs a name.'; continue; }
                if (!is_numeric($fee) || (float)$fee < 0) { $err['delivery_zones'] = 'Delivery fees must be numbers (0 or more).'; }
                if (isset($v['delivery_zones'][$key])) $err['delivery_zones'] = 'Two zones have the same key “' . $key . '”.';
                $v['delivery_zones'][$key] = ['label' => $label, 'fee' => is_numeric($fee) ? 0 + $fee : $fee];
            }
            if (!$v['delivery_zones'] && !isset($err['delivery_zones'])) $err['delivery_zones'] = 'Add at least one delivery zone — checkout needs it.';
            $thr = trim((string)($_POST['free_delivery_threshold'] ?? ''));
            if ($thr !== '' && (!is_numeric($thr) || (float)$thr < 0)) $err['free_delivery_threshold'] = 'Enter a number (0 or empty = off).';
            $v['free_delivery_threshold'] = is_numeric($thr) ? 0 + $thr : 0;
            break;

        case 'payments':
            $v['payment_methods'] = []; $v['payment_instructions'] = [];
            foreach (settings_rows('payment_methods') as $m) {
                $label = mb_substr(trim((string)($m['label'] ?? '')), 0, 80);
                $key = settings_key((string)($m['key'] ?? '')) ?: settings_key($label);
                $ins = mb_substr(trim((string)($m['instructions'] ?? '')), 0, 1000);
                if ($label === '' && $ins === '') continue;
                if ($label === '' || $key === '') { $err['payment_methods'] = 'Each payment method needs a name.'; continue; }
                if (isset($v['payment_methods'][$key])) $err['payment_methods'] = 'Two methods have the same key “' . $key . '”.';
                $v['payment_methods'][$key] = $label;
                if ($ins !== '') $v['payment_instructions'][$key] = $ins;
            }
            if (!$v['payment_methods'] && !isset($err['payment_methods'])) $err['payment_methods'] = 'Add at least one payment method — checkout needs it.';
            break;

        case 'notifications':
            $v['order_notify_telegram'] = !empty($_POST['order_notify_telegram']);
            break;
    }
    return [$v, $err];
}

$errors = [];
$posted = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');
    $keys = $TABS[$tab][2];
    if ($action === 'reset') {
        foreach ($keys as $k) unset($saved[$k]);
        setting_set('site', $saved);
        log_activity('settings.reset', 'Reset ' . $TABS[$tab][0] . ' settings to defaults', ['keys' => $keys]);
        flash($TABS[$tab][0] . ' settings were reset to the defaults.');
        redirect(admin_url('settings.php?tab=' . $tab));
    }
    if ($action === 'test_telegram' && $tab === 'notifications') {
        $ok = false;
        try { $ok = $db->notifyChannel('✅ Test message from ' . $SITE['brand_name'] . ' admin — order alerts are working.'); } catch (\Throwable $e) {}
        log_activity('settings.telegram_test', $ok ? 'Sent a Telegram test message' : 'Telegram test message failed');
        flash($ok ? 'Test message sent to your Telegram channel.' : 'Could not reach Telegram. Check the bot token and channel ID.', $ok ? 'success' : 'error');
        redirect(admin_url('settings.php?tab=notifications'));
    }
    [$values, $errors] = settings_parse($tab);
    if (!$errors) {
        $changed = array_keys(array_filter($values, fn($val, $k) => ($SITE[$k] ?? null) !== $val, ARRAY_FILTER_USE_BOTH));
        $saved = array_replace($saved, $values);
        unset($saved['category_images']); // owned by the Categories page via its own merge
        $current = (array)setting('site', []);
        if (array_key_exists('category_images', $current)) $saved['category_images'] = $current['category_images'];
        setting_set('site', $saved);
        log_activity('settings.update', 'Updated ' . $TABS[$tab][0] . ' settings' . ($changed ? ': ' . implode(', ', $changed) : ''), ['keys' => $changed]);
        flash($TABS[$tab][0] . ' settings saved.');
        redirect(admin_url('settings.php?tab=' . $tab));
    }
    $posted = $values;
}

// Values shown in the form: posted (when validation failed) or the current effective settings.
$S = $posted !== null ? array_replace($SITE, $posted) : $SITE;
$overridden = array_values(array_intersect($TABS[$tab][2], array_keys($saved)));
$err = fn($k) => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$cur = $SITE['currency_symbol'] ?? '';

admin_header('Settings', 'settings', ['actions' => '<a class="btn btn-ghost" href="/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> View store</a>']);
?>
<nav class="tabs">
  <?php foreach ($TABS as $k => [$label, $icon]): ?>
    <a class="tab <?= $k === $tab ? 'is-active' : '' ?>" href="<?= admin_url('settings.php?tab=' . $k) ?>"><i class="fa-solid <?= $icon ?>"></i> <?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($errors): ?><div class="alert alert-error">Nothing was saved — please fix the highlighted fields.</div><?php endif; ?>

<form method="post" class="card" novalidate id="settings-form">
  <?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="card-header">
    <h2><?= e($TABS[$tab][0]) ?></h2>
    <span class="muted"><?= $overridden ? 'Customised' : 'Using defaults' ?></span>
  </div>
  <div class="card-body">

  <?php if ($tab === 'general'): ?>
    <div class="form-grid">
      <div class="field"><label for="brand_name">Store name</label><input id="brand_name" name="brand_name" value="<?= e($S['brand_name'] ?? '') ?>" required><?= $err('brand_name') ?></div>
      <div class="field"><label for="tagline">Tagline</label><input id="tagline" name="tagline" value="<?= e($S['tagline'] ?? '') ?>"></div>
      <div class="field span-2"><label for="logo_url">Logo image URL</label><input id="logo_url" name="logo_url" value="<?= e($S['logo_url'] ?? '') ?>" placeholder="https://… or assets/img/logo.png">
        <div class="hint">Leave empty to show the store name as text. A transparent PNG or SVG works best.</div><?= $err('logo_url') ?>
        <?php if (!empty($S['logo_url'])): ?><img class="thumb" src="<?= e($S['logo_url']) ?>" alt="Logo preview" style="max-height:48px;margin-top:8px"><?php endif; ?></div>
      <div class="field span-2"><label for="announcement">Announcement bar</label><input id="announcement" name="announcement" value="<?= e($S['announcement'] ?? '') ?>" maxlength="200"><div class="hint">Thin bar at the top of every page. Leave empty to hide it.</div></div>
      <div class="field span-2"><label for="meta_description">Search engine description</label><textarea id="meta_description" name="meta_description" rows="2" maxlength="300"><?= e($S['meta_description'] ?? '') ?></textarea><div class="hint">Shown by Google and when links are shared. About 150 characters.</div></div>
      <div class="field"><label for="currency_symbol">Currency symbol</label><input id="currency_symbol" name="currency_symbol" value="<?= e($S['currency_symbol'] ?? '') ?>" maxlength="8"><?= $err('currency_symbol') ?></div>
    </div>

  <?php elseif ($tab === 'contact'): ?>
    <p class="muted">Empty fields are hidden on the storefront.</p>
    <div class="form-grid">
      <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= e($S['phone'] ?? '') ?>" inputmode="tel" placeholder="+8801XXXXXXXXX"><?= $err('phone') ?></div>
      <div class="field"><label for="whatsapp">WhatsApp number</label><input id="whatsapp" name="whatsapp" value="<?= e($S['whatsapp'] ?? '') ?>" inputmode="numeric" placeholder="8801XXXXXXXXX"><div class="hint">Digits only, with country code.</div><?= $err('whatsapp') ?></div>
      <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= e($S['email'] ?? '') ?>"><?= $err('email') ?></div>
      <div class="field"><label for="address">Address</label><input id="address" name="address" value="<?= e($S['address'] ?? '') ?>"></div>
      <div class="field"><label for="facebook_url"><i class="fa-brands fa-facebook"></i> Facebook page</label><input id="facebook_url" name="facebook_url" value="<?= e($S['facebook_url'] ?? '') ?>" placeholder="https://facebook.com/…"><?= $err('facebook_url') ?></div>
      <div class="field"><label for="instagram_url"><i class="fa-brands fa-instagram"></i> Instagram</label><input id="instagram_url" name="instagram_url" value="<?= e($S['instagram_url'] ?? '') ?>" placeholder="https://instagram.com/…"><?= $err('instagram_url') ?></div>
      <div class="field"><label for="youtube_url"><i class="fa-brands fa-youtube"></i> YouTube</label><input id="youtube_url" name="youtube_url" value="<?= e($S['youtube_url'] ?? '') ?>" placeholder="https://youtube.com/…"><?= $err('youtube_url') ?></div>
    </div>

  <?php elseif ($tab === 'homepage'):
      $banners = (array)($S['banners'] ?? []); $side = (array)($S['side_banner'] ?? []); $hero = (array)($S['hero'] ?? []); $trust = (array)($S['trust_items'] ?? []); ?>
    <h3>Banner slider</h3>
    <p class="muted">Recommended size 1760×700. Remove every banner to show the text hero instead.</p>
    <?= $err('banners') ?>
    <div class="stack" data-repeat="banners">
      <?php foreach ($banners as $i => $b): ?>
        <div class="repeat-row">
          <img class="thumb" src="<?= e($b['image'] ?? '') ?>" alt="">
          <div class="field"><label>Image URL</label><input name="banners[<?= $i ?>][image]" value="<?= e($b['image'] ?? '') ?>" data-preview></div>
          <div class="field"><label>Link</label><input name="banners[<?= $i ?>][link]" value="<?= e($b['link'] ?? '') ?>" placeholder="index.php?category=honey"></div>
          <div class="field"><label>Alt text</label><input name="banners[<?= $i ?>][alt]" value="<?= e($b['alt'] ?? '') ?>"></div>
          <button type="button" class="icon-btn" data-remove title="Remove"><i class="fa-solid fa-trash"></i></button>
        </div>
      <?php endforeach; ?>
    </div>
    <template data-template="banners">
      <div class="repeat-row">
        <img class="thumb" src="" alt="">
        <div class="field"><label>Image URL</label><input name="banners[__i__][image]" data-preview></div>
        <div class="field"><label>Link</label><input name="banners[__i__][link]" placeholder="index.php?category=honey"></div>
        <div class="field"><label>Alt text</label><input name="banners[__i__][alt]"></div>
        <button type="button" class="icon-btn" data-remove title="Remove"><i class="fa-solid fa-trash"></i></button>
      </div>
    </template>
    <button type="button" class="btn btn-secondary btn-sm" data-add="banners"><i class="fa-solid fa-plus"></i> Add banner</button>

    <h3 style="margin-top:28px">Side banner</h3>
    <p class="muted">Tall banner beside the slider (860×700). Leave the image empty to hide it.</p>
    <?= $err('side_banner') ?>
    <div class="repeat-row">
      <img class="thumb" src="<?= e($side['image'] ?? '') ?>" alt="">
      <div class="field"><label>Image URL</label><input name="side_banner[image]" value="<?= e($side['image'] ?? '') ?>" data-preview></div>
      <div class="field"><label>Link</label><input name="side_banner[link]" value="<?= e($side['link'] ?? '') ?>"></div>
      <div class="field"><label>Alt text</label><input name="side_banner[alt]" value="<?= e($side['alt'] ?? '') ?>"></div>
    </div>

    <h3 style="margin-top:28px">Text hero</h3>
    <p class="muted">Shown only when there are no slider banners.</p>
    <?= $err('hero') ?>
    <div class="form-grid">
      <div class="field"><label>Small heading</label><input name="hero[eyebrow]" value="<?= e($hero['eyebrow'] ?? '') ?>"></div>
      <div class="field"><label>Button label</label><input name="hero[cta_label]" value="<?= e($hero['cta_label'] ?? '') ?>"></div>
      <div class="field span-2"><label>Title</label><input name="hero[title]" value="<?= e($hero['title'] ?? '') ?>"></div>
      <div class="field span-2"><label>Subtitle</label><textarea name="hero[subtitle]" rows="2"><?= e($hero['subtitle'] ?? '') ?></textarea></div>
      <div class="field span-2"><label>Image URL <span class="muted">(optional)</span></label><input name="hero[image]" value="<?= e($hero['image'] ?? '') ?>"></div>
    </div>

    <h3 style="margin-top:28px">Promise strip</h3>
    <p class="muted">Small highlights under the banner. Icons use <a href="https://fontawesome.com/search?o=r&amp;m=free&amp;s=solid" target="_blank" rel="noopener">Font Awesome</a> names, e.g. <code>fa-truck-fast</code>.</p>
    <?= $err('trust_items') ?>
    <div class="stack" data-repeat="trust_items">
      <?php foreach ($trust as $i => $t): ?>
        <div class="repeat-row">
          <span class="icon-preview"><i class="fa-solid <?= e($t['icon'] ?? '') ?>"></i></span>
          <div class="field"><label>Icon</label><input name="trust_items[<?= $i ?>][icon]" value="<?= e($t['icon'] ?? '') ?>" data-icon></div>
          <div class="field"><label>Title</label><input name="trust_items[<?= $i ?>][title]" value="<?= e($t['title'] ?? '') ?>"></div>
          <div class="field"><label>Text</label><input name="trust_items[<?= $i ?>][text]" value="<?= e($t['text'] ?? '') ?>"></div>
          <button type="button" class="icon-btn" data-remove title="Remove"><i class="fa-solid fa-trash"></i></button>
        </div>
      <?php endforeach; ?>
    </div>
    <template data-template="trust_items">
      <div class="repeat-row">
        <span class="icon-preview"><i class="fa-solid fa-circle-check"></i></span>
        <div class="field"><label>Icon</label><input name="trust_items[__i__][icon]" value="fa-circle-check" data-icon></div>
        <div class="field"><label>Title</label><input name="trust_items[__i__][title]"></div>
        <div class="field"><label>Text</label><input name="trust_items[__i__][text]"></div>
        <button type="button" class="icon-btn" data-remove title="Remove"><i class="fa-solid fa-trash"></i></button>
      </div>
    </template>
    <button type="button" class="btn btn-secondary btn-sm" data-add="trust_items"><i class="fa-solid fa-plus"></i> Add item</button>

  <?php elseif ($tab === 'delivery'): $zones = (array)($S['delivery_zones'] ?? []); ?>
    <h3>Delivery zones</h3>
    <p class="muted">Customers choose one of these at checkout. The key is an internal id — leave it empty to create it from the name.</p>
    <?= $err('delivery_zones') ?>
    <div class="stack" data-repeat="delivery_zones">
      <?php $i = 0; foreach ($zones as $key => $z): ?>
        <div class="repeat-row cols-3">
          <div class="field"><label>Zone name</label><input name="delivery_zones[<?= $i ?>][label]" value="<?= e($z['label'] ?? '') ?>"></div>
          <div class="field"><label>Delivery fee (<?= e($cur) ?>)</label><input name="delivery_zones[<?= $i ?>][fee]" type="number" min="0" step="1" value="<?= e($z['fee'] ?? '') ?>"></div>
          <div class="field"><label>Key</label><input name="delivery_zones[<?= $i ?>][key]" value="<?= e($key) ?>"></div>
          <button type="button" class="icon-btn" data-remove title="Remove"><i class="fa-solid fa-trash"></i></button>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <template data-template="delivery_zones">
      <div class="repeat-row cols-3">
        <div class="field"><label>Zone name</label><input name="delivery_zones[__i__][label]"></div>
        <div class="field"><label>Delivery fee (<?= e($cur) ?>)</label><input name="delivery_zones[__i__][fee]" type="number" min="0" step="1"></div>
        <div class="field"><label>Key</label><input name="delivery_zones[__i__][key]"></div>
        <button type="button" class="icon-btn" data-remove title="Remove"><i class="fa-solid fa-trash"></i></button>
      </div>
    </template>
    <button type="button" class="btn btn-secondary btn-sm" data-add="delivery_zones"><i class="fa-solid fa-plus"></i> Add zone</button>

    <h3 style="margin-top:28px">Free delivery</h3>
    <div class="form-grid">
      <div class="field"><label for="free_delivery_threshold">Free delivery on orders from (<?= e($cur) ?>)</label>
        <input id="free_delivery_threshold" name="free_delivery_threshold" type="number" min="0" step="1" value="<?= !empty($S['free_delivery_threshold']) ? e($S['free_delivery_threshold']) : '' ?>">
        <div class="hint">Order subtotal at or above this amount gets free delivery. Leave empty or 0 to turn off.</div><?= $err('free_delivery_threshold') ?></div>
    </div>

  <?php elseif ($tab === 'payments'): $methods = (array)($S['payment_methods'] ?? []); $ins = (array)($S['payment_instructions'] ?? []); ?>
    <p class="muted">Payment options shown at checkout. Instructions appear when the customer picks that method — for example your bKash/Nagad number and how to send the payment.</p>
    <?= $err('payment_methods') ?>
    <div class="stack" data-repeat="payment_methods">
      <?php $i = 0; foreach ($methods as $key => $label): ?>
        <div class="repeat-row cols-pay">
          <div class="field"><label>Method name</label><input name="payment_methods[<?= $i ?>][label]" value="<?= e($label) ?>"></div>
          <div class="field"><label>Key</label><input name="payment_methods[<?= $i ?>][key]" value="<?= e($key) ?>"></div>
          <div class="field span-all"><label>Instructions for the customer</label><textarea name="payment_methods[<?= $i ?>][instructions]" rows="2"><?= e($ins[$key] ?? '') ?></textarea></div>
          <button type="button" class="icon-btn" data-remove title="Remove"><i class="fa-solid fa-trash"></i></button>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <template data-template="payment_methods">
      <div class="repeat-row cols-pay">
        <div class="field"><label>Method name</label><input name="payment_methods[__i__][label]"></div>
        <div class="field"><label>Key</label><input name="payment_methods[__i__][key]"></div>
        <div class="field span-all"><label>Instructions for the customer</label><textarea name="payment_methods[__i__][instructions]" rows="2"></textarea></div>
        <button type="button" class="icon-btn" data-remove title="Remove"><i class="fa-solid fa-trash"></i></button>
      </div>
    </template>
    <button type="button" class="btn btn-secondary btn-sm" data-add="payment_methods"><i class="fa-solid fa-plus"></i> Add payment method</button>

  <?php elseif ($tab === 'notifications'): ?>
    <div class="field">
      <label class="switch"><input type="checkbox" name="order_notify_telegram" value="1" <?= !empty($S['order_notify_telegram']) ? 'checked' : '' ?>><span></span></label>
      <strong>Send new-order alerts to Telegram</strong>
      <div class="hint">Each new order is posted to your store’s Telegram channel with the customer’s details and items.</div>
    </div>
  <?php endif; ?>
  </div>
  <div class="card-footer">
    <button class="btn btn-primary" type="submit" name="action" value="save"><i class="fa-solid fa-floppy-disk"></i> Save <?= e(strtolower($TABS[$tab][0])) ?></button>
    <?php if ($tab === 'notifications'): ?>
      <button class="btn btn-secondary" type="submit" name="action" value="test_telegram" formnovalidate><i class="fa-brands fa-telegram"></i> Send test message</button>
    <?php endif; ?>
    <?php if ($overridden): ?>
      <button class="btn btn-ghost" type="submit" name="action" value="reset" data-confirm="Reset all <?= e($TABS[$tab][0]) ?> settings to the defaults? Your changes in this section will be lost."><i class="fa-solid fa-rotate-left"></i> Reset section to defaults</button>
    <?php endif; ?>
  </div>
</form>

<style>
  .repeat-row{display:grid;grid-template-columns:80px 1fr 1fr 1fr auto;gap:12px;align-items:end;padding:12px;border:1px solid var(--border,#e5e7eb);border-radius:12px;margin-bottom:10px}
  .repeat-row.cols-3{grid-template-columns:1.4fr 1fr 1fr auto}
  .repeat-row.cols-pay{grid-template-columns:1.4fr 1fr auto}
  .repeat-row.cols-pay .span-all{grid-column:1 / -2;grid-row:2}
  .repeat-row .thumb{width:80px;height:44px;object-fit:cover;border-radius:8px;background:#f3f4f6}
  .repeat-row .field{margin:0}
  .icon-preview{width:44px;height:44px;display:grid;place-items:center;border-radius:10px;background:#fff7ed;color:#c2410c;font-size:18px}
  .repeat-row:has(.icon-preview){grid-template-columns:44px 1fr 1fr 1.4fr auto}
  @media (max-width:760px){.repeat-row,.repeat-row.cols-3,.repeat-row.cols-pay,.repeat-row:has(.icon-preview){grid-template-columns:1fr}.repeat-row.cols-pay .span-all{grid-column:auto;grid-row:auto}}
</style>
<script>
(function () {
  var form = document.getElementById('settings-form');
  var n = Date.now();
  form.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add]');
    if (add) {
      var name = add.getAttribute('data-add');
      var tpl = form.querySelector('template[data-template="' + name + '"]');
      var html = tpl.innerHTML.replace(/__i__/g, String(n++));
      form.querySelector('[data-repeat="' + name + '"]').insertAdjacentHTML('beforeend', html);
      return;
    }
    var rm = e.target.closest('[data-remove]');
    if (rm) rm.closest('.repeat-row').remove();
  });
  form.addEventListener('input', function (e) {
    var row = e.target.closest('.repeat-row');
    if (!row) return;
    if (e.target.hasAttribute('data-preview')) { var img = row.querySelector('img.thumb'); if (img) img.src = e.target.value.trim(); }
    if (e.target.hasAttribute('data-icon')) { var i = row.querySelector('.icon-preview i'); if (i) i.className = 'fa-solid ' + e.target.value.trim(); }
  });
})();
</script>
<?php admin_footer();
