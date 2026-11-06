<?php
/**
 * Store settings — the single place to change what the storefront shows.
 * Anything left empty ('') is simply hidden on the site.
 */
return [
    'brand_name'    => 'Nahid Honey',
    'tagline'       => 'Pure & natural, from hive to home',

    // Contact — fill these in; empty values are hidden everywhere.
    'phone'         => '',   // e.g. '+8801XXXXXXXXX'
    'whatsapp'      => '',   // digits only with country code, e.g. '8801XXXXXXXXX'
    'email'         => '',
    'address'       => '',
    'facebook_url'  => '',
    'instagram_url' => '',
    'youtube_url'   => '',

    // Thin bar at the very top of every page. Empty = hidden.
    'announcement'  => 'Cash on delivery available all over Bangladesh',

    // Homepage banner slider. Replace the demo images with your own designs
    // (recommended size 1760×700). Leave the list empty to show the text hero below instead.
    'banners' => [
        ['image' => 'assets/img/banners/banner-honey.svg',  'link' => 'index.php?category=honey',           'alt' => 'Pure raw honey'],
        ['image' => 'assets/img/banners/banner-gur.svg',    'link' => 'index.php?category=khejur-gur-rosh', 'alt' => 'Fresh date palm jaggery'],
        ['image' => 'assets/img/banners/banner-combo.svg',  'link' => 'index.php#shop',                    'alt' => 'Combo deals'],
    ],
    // Tall banner beside the slider (recommended 860×700). Set to [] to hide.
    'side_banner' => ['image' => 'assets/img/banners/side-blackseed.svg', 'link' => 'index.php?category=honey', 'alt' => 'Black seed honey'],

    // Icon for each category (key = category name in lowercase, spaces/symbols → "-").
    // Categories without an icon use their first product's photo.
    'category_images' => [
        'honey'            => 'assets/img/categories/honey.svg',
        'khejur-gur-rosh'  => 'assets/img/categories/khejur-gur.svg',
        'oil-ghee'         => 'assets/img/categories/oil-ghee.svg',
        'seasonal-organic' => 'assets/img/categories/organic.svg',
        'organic'          => 'assets/img/categories/organic.svg',
        'dates'            => 'assets/img/categories/dates.svg',
        'spices'           => 'assets/img/categories/spices.svg',
        'nuts-seeds'       => 'assets/img/categories/nuts.svg',
        'rice'             => 'assets/img/categories/rice.svg',
    ],

    // Text hero — used only when 'banners' is empty.
    'hero' => [
        'eyebrow'   => '100% natural',
        'title'     => 'Pure honey, straight from the hive',
        'subtitle'  => 'Raw, unprocessed and carefully packed. Order online and pay when it reaches your door.',
        'cta_label' => 'Shop now',
        'image'     => '', // optional banner image URL; falls back to a featured product image
    ],

    // Small promise strip under the hero. Edit or remove freely.
    'trust_items' => [
        ['icon' => 'fa-hand-holding-dollar', 'title' => 'Cash on delivery',  'text' => 'Pay when you receive'],
        ['icon' => 'fa-truck-fast',          'title' => 'Nationwide delivery', 'text' => 'Anywhere in Bangladesh'],
        ['icon' => 'fa-box-open',            'title' => 'Carefully packed',  'text' => 'Leak-proof, safe packaging'],
        ['icon' => 'fa-headset',             'title' => 'Friendly support',  'text' => 'We are here to help'],
    ],

    // Checkout delivery zones (key => [label, fee in BDT]).
    'delivery_zones' => [
        'inside_dhaka'  => ['label' => 'Inside Dhaka',  'fee' => 70],
        'outside_dhaka' => ['label' => 'Outside Dhaka', 'fee' => 130],
    ],

    // Checkout payment options (key => label).
    'payment_methods' => [
        'cod'   => 'Cash on Delivery',
        'bkash' => 'bKash',
    ],

    'currency_symbol' => '৳',
];
