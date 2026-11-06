/**
 * Nahid Honey - Main Application JavaScript
 * Inspired by Ghorer Bazar Design Patterns
 * Features:
 *   - Real-time Product Grid Search & Live Dropdown Search
 *   - Multi-category Filter (All, Honey, Gur & Rosh, Oils, Seasonal)
 *   - Sticky Header & Mobile Navigation Toggles
 *   - Swiper.js Carousel Initializations
 *   - Floating Action Buttons (Back to Top, WhatsApp, Reviews)
 *   - Wishlist Toggle & UI Micro-interactions
 */

(function (window, document, $) {
  'use strict';

  var NahidApp = {
    activeCategory: 'all',
    searchQuery: '',
    searchDebounceTimer: null,

    /**
     * Initialize Application Modules
     */
    init: function () {
      this.initStickyHeader();
      this.initMobileNav();
      this.initCategoryFilter();
      this.initProductSearch();
      this.initSwiperSliders();
      this.initBackToTop();
      this.initQuantitySpinners();
      this.initWishlist();
      this.handleUrlParameters();
      console.log('Nahid Honey Main App initialized.');
    },

    /**
     * 1. Sticky Header Behavior & Scroll Triggers
     */
    initStickyHeader: function () {
      var header = document.querySelector('.main-header');
      if (!header) return;

      var lastScrollY = window.scrollY;

      function onScroll() {
        var currentScrollY = window.scrollY;

        if (currentScrollY > 60) {
          header.classList.add('is-sticky', 'sticky-shadow');
        } else {
          header.classList.remove('is-sticky', 'sticky-shadow');
        }

        lastScrollY = currentScrollY;
      }

      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    },

    /**
     * 2. Mobile Navigation Toggle & Drawer
     */
    initMobileNav: function () {
      var self = this;
      var toggleBtn = document.querySelector('.mobile-nav-toggle');
      var nav = document.querySelector('.main-nav');

      if (!toggleBtn || !nav) return;

      // Create mobile nav backdrop overlay if not present
      var overlay = document.getElementById('mobileNavOverlay');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'mobileNavOverlay';
        overlay.className = 'mobile-nav-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:998;opacity:0;visibility:hidden;transition:all 0.3s ease;';
        document.body.appendChild(overlay);
      }

      function openMobileNav() {
        nav.classList.add('mobile-open');
        overlay.style.opacity = '1';
        overlay.style.visibility = 'visible';
        document.body.classList.add('mobile-nav-active');
      }

      function closeMobileNav() {
        nav.classList.remove('mobile-open');
        overlay.style.opacity = '0';
        overlay.style.visibility = 'hidden';
        document.body.classList.remove('mobile-nav-active');
      }

      toggleBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (nav.classList.contains('mobile-open')) {
          closeMobileNav();
        } else {
          openMobileNav();
        }
      });

      overlay.addEventListener('click', closeMobileNav);

      // Close mobile nav when clicking any nav link
      var navLinks = nav.querySelectorAll('.nav-link, a');
      for (var i = 0; i < navLinks.length; i++) {
        navLinks[i].addEventListener('click', function () {
          closeMobileNav();
        });
      }

      // Close on Escape key
      document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.keyCode === 27) && nav.classList.contains('mobile-open')) {
          closeMobileNav();
        }
      });
    },

    /**
     * 3. Category Filter
     * Handles: All, Honey, Gur & Rosh, Oils, Seasonal
     */
    initCategoryFilter: function () {
      var self = this;

      // Handle category links in navigation and category circle cards
      $(document).on('click', '.nav-link[data-category], .category-circle-card[data-category], .category-pill[data-category], [data-filter-category]', function (e) {
        var clickedCat = $(this).attr('data-category');
        if (!clickedCat) return;

        // If link points to another page, allow navigation unless on index
        var href = $(this).attr('href');
        var isAnchorOrSamePage = !href || href.startsWith('#') || href.indexOf('index.php') > -1 || window.location.pathname.endsWith('index.php') || window.location.pathname === '/';

        if (isAnchorOrSamePage) {
          e.preventDefault();
          self.setCategory(clickedCat);

          // Scroll to product section smoothly
          var gridSec = document.querySelector('.products-section, #products');
          if (gridSec) {
            gridSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    },

    /**
     * Normalize category slug for matching
     */
    normalizeCategory: function (cat) {
      if (!cat) return 'all';
      cat = String(cat).trim().toLowerCase();

      if (cat === 'all' || cat === 'সকল' || cat === 'সকল পণ্য') {
        return 'all';
      }
      if (cat === 'honey' || cat === 'মধু' || cat === 'natural-honey' || cat === 'raw-honey') {
        return 'honey';
      }
      if (cat === 'gur' || cat === 'gur-rosh' || cat === 'rosh' || cat === 'খেজুর গুড় ও রস' || cat === 'গুড়' || cat === 'গুড় ও মিষ্টি') {
        return 'gur';
      }
      if (cat === 'oils' || cat === 'oil' || cat === 'oil-ghee' || cat === 'ঘি ও সরিষার তেল' || cat === 'তেল ও ঘি' || cat === 'mustard-oil') {
        return 'oil-ghee';
      }
      if (cat === 'seasonal' || cat === 'organic' || cat === 'অর্গানিক ও সিজনাল' || cat === 'মৌসুমি' || cat === 'সিজনাল') {
        return 'organic';
      }

      return cat;
    },

    /**
     * Check if product matches normalized target category
     */
    categoryMatches: function (productCat, targetCat) {
      if (targetCat === 'all') return true;

      var normProductCat = this.normalizeCategory(productCat);
      var normTargetCat = this.normalizeCategory(targetCat);

      if (normProductCat === normTargetCat) return true;

      // Sub-aliases check
      if (normTargetCat === 'gur' && (normProductCat === 'gur-rosh' || normProductCat === 'rosh')) return true;
      if (normTargetCat === 'oil-ghee' && (normProductCat === 'oils' || normProductCat === 'oil')) return true;
      if (normTargetCat === 'organic' && (normProductCat === 'seasonal' || normProductCat === 'fruits')) return true;

      return false;
    },

    /**
     * Set active category and filter products grid
     */
    setCategory: function (catSlug) {
      this.activeCategory = this.normalizeCategory(catSlug);

      // Update active state on nav links
      $('.nav-link').removeClass('active');
      $('.nav-link').each(function () {
        var linkCat = $(this).attr('data-category');
        if (linkCat && NahidApp.normalizeCategory(linkCat) === NahidApp.activeCategory) {
          $(this).addClass('active');
        }
      });

      // Update active state on category circles/pills
      $('.category-circle-card, .category-pill').removeClass('active');
      $('.category-circle-card[data-category], .category-pill[data-category]').each(function () {
        var pillCat = $(this).attr('data-category');
        if (pillCat && NahidApp.normalizeCategory(pillCat) === NahidApp.activeCategory) {
          $(this).addClass('active');
        }
      });

      this.filterProductGrid();
    },

    /**
     * 4. Product Search & Filter Engine
     * Handles live dropdown suggestions and instant product grid filtering
     */
    initProductSearch: function () {
      var self = this;
      var searchInput = $('.search-input, #product-search, input[name="search"]');
      var searchForm = $('.header-search form, #headerSearchForm');
      var dropdown = $('.search-results-dropdown');

      // Live search input handler with debounce
      searchInput.on('input', function () {
        var query = $(this).val().trim();
        self.searchQuery = query;

        clearTimeout(self.searchDebounceTimer);
        self.searchDebounceTimer = setTimeout(function () {
          self.filterProductGrid();
          self.handleLiveSearchDropdown(query, dropdown);
        }, 200);
      });

      // Search form submission
      searchForm.on('submit', function (e) {
        var query = searchInput.val().trim();
        self.searchQuery = query;

        var productGrid = document.querySelector('.products-section, #products');
        if (productGrid) {
          e.preventDefault();
          self.filterProductGrid();
          productGrid.scrollIntoView({ behavior: 'smooth', block: 'start' });
          dropdown.removeClass('active');
        }
      });

      // Close search dropdown on click outside
      $(document).on('click', function (e) {
        if (!$(e.target).closest('.header-search').length) {
          dropdown.removeClass('active');
        }
      });

      // Prevent dropdown close when clicking inside dropdown
      dropdown.on('click', function (e) {
        e.stopPropagation();
      });
    },

    /**
     * Populate Live Search Dropdown (.search-results-dropdown)
     */
    handleLiveSearchDropdown: function (query, $dropdown) {
      if (!$dropdown.length) return;

      if (!query || query.length < 2) {
        $dropdown.removeClass('active').empty();
        return;
      }

      var queryLower = query.toLowerCase();
      var matches = [];

      // First check local DOM product cards
      var cards = $('.product-card, .shelf-product');
      if (cards.length > 0) {
        cards.each(function () {
          var title = $(this).find('.product-title, h3, h4').first().text().trim();
          var price = $(this).find('.current-price, .product-price').first().text().trim();
          var origPrice = $(this).find('.original-price, del').first().text().trim();
          var img = $(this).find('img').first().attr('src') || '';
          var id = $(this).attr('data-id') || '';
          var cat = $(this).attr('data-category') || '';

          if (title.toLowerCase().indexOf(queryLower) > -1 || cat.toLowerCase().indexOf(queryLower) > -1) {
            matches.push({
              id: id,
              title: title,
              price: price,
              original_price: origPrice,
              image: img
            });
          }
        });

        this.renderSearchDropdown(matches, $dropdown, query);
      } else {
        // Query Backend API (api.php?action=get_products&search=...)
        $.ajax({
          url: 'api.php',
          method: 'GET',
          data: { action: 'get_products', search: query, limit: 6 },
          dataType: 'json'
        }).done(function (res) {
          if (res && res.success && Array.isArray(res.data)) {
            NahidApp.renderSearchDropdown(res.data, $dropdown, query);
          } else {
            $dropdown.removeClass('active').empty();
          }
        }).fail(function () {
          $dropdown.removeClass('active').empty();
        });
      }
    },

    /**
     * Render items inside .search-results-dropdown
     */
    renderSearchDropdown: function (items, $dropdown, query) {
      if (!items || items.length === 0) {
        $dropdown.html('<div style="padding:16px;text-align:center;color:#64748b;font-size:0.88rem;">"<strong>' + this.escapeHtml(query) + '</strong>" এর জন্য কোনো পণ্য পাওয়া যায়নি।</div>').addClass('active');
        return;
      }

      var html = [];
      for (var i = 0; i < Math.min(items.length, 6); i++) {
        var p = items[i];
        var priceDisplay = p.price ? (String(p.price).indexOf('৳') > -1 ? p.price : '৳' + Number(p.price).toLocaleString('en-US')) : '';
        var origDisplay = p.original_price ? (String(p.original_price).indexOf('৳') > -1 ? p.original_price : '৳' + Number(p.original_price).toLocaleString('en-US')) : '';

        html.push([
          '<div class="search-item" data-id="' + (p.id || '') + '" onclick="NahidApp.onSearchItemClick(' + (p.id || '\'\'') + ')">',
          '  <img class="search-item-img" src="' + (p.image || 'assets/images/placeholder.jpg') + '" alt="' + this.escapeHtml(p.title) + '">',
          '  <div class="search-item-info">',
          '    <div class="search-item-title">' + this.escapeHtml(p.title) + '</div>',
          '    <div class="search-item-price">' + priceDisplay + (origDisplay ? ' <del>' + origDisplay + '</del>' : '') + '</div>',
          '  </div>',
          '</div>'
        ].join('\n'));
      }

      $dropdown.html(html.join('\n')).addClass('active');
    },

    /**
     * Search item click action
     */
    onSearchItemClick: function (id) {
      var dropdown = $('.search-results-dropdown');
      dropdown.removeClass('active');

      if (!id) return;

      var targetCard = document.querySelector('.product-card[data-id="' + id + '"]');
      if (targetCard) {
        targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        targetCard.style.outline = '3px solid #15803d';
        setTimeout(function () {
          targetCard.style.outline = '';
        }, 2000);
      } else {
        window.location.href = 'index.php#products';
      }
    },

    /**
     * Filter Grid Cards by both Active Category & Search Query
     */
    filterProductGrid: function () {
      var self = this;
      var cards = $('.products-grid .product-card, .products-grid .product-item');
      if (!cards.length) return;

      var targetCat = self.activeCategory;
      var query = self.searchQuery.trim().toLowerCase();
      var visibleCount = 0;

      cards.each(function () {
        var card = $(this);
        var productCat = card.attr('data-category') || '';
        var title = card.find('.product-title, h3, h4').first().text().trim().toLowerCase();
        var catName = card.find('.product-cat-name').first().text().trim().toLowerCase();
        var desc = card.find('.product-desc, p').first().text().trim().toLowerCase();

        // Check category match
        var matchesCat = (targetCat === 'all') || self.categoryMatches(productCat, targetCat) || self.categoryMatches(catName, targetCat);

        // Check search match
        var matchesSearch = true;
        if (query.length > 0) {
          matchesSearch = title.indexOf(query) > -1 || catName.indexOf(query) > -1 || desc.indexOf(query) > -1 || productCat.indexOf(query) > -1;
        }

        if (matchesCat && matchesSearch) {
          card.stop(true, true).fadeIn(250);
          visibleCount++;
        } else {
          card.stop(true, true).hide();
        }
      });

      // Handle Empty State Message
      var emptyNotice = $('#productsEmptyNotice');
      if (visibleCount === 0) {
        if (!emptyNotice.length) {
          var noticeHtml = [
            '<div id="productsEmptyNotice" style="grid-column: 1 / -1; text-align: center; padding: 40px 20px; background: #ffffff; border-radius: 12px; border: 1px dashed #cbd5e1; margin: 20px 0;">',
            '  <div style="font-size: 2.5rem; margin-bottom: 10px;">🔍</div>',
            '  <h4 style="font-size: 1.2rem; font-weight: 700; color: #1e293b; margin-bottom: 8px;">কোনো পণ্য খুঁজে পাওয়া যায়নি!</h4>',
            '  <p style="font-size: 0.9rem; color: #64748b; margin-bottom: 16px;">অনুগ্রহ করে বানান যাচাই করুন অথবা অন্য ক্যাটাগরি বেছে নিন।</p>',
            '  <button type="button" class="btn-reset-filters" style="background:#15803d;color:#ffffff;padding:10px 22px;border-radius:24px;font-weight:700;font-size:0.9rem;cursor:pointer;">সকল পণ্য দেখুন</button>',
            '</div>'
          ].join('\n');
          $('.products-grid').append(noticeHtml);

          $('#productsEmptyNotice .btn-reset-filters').on('click', function () {
            self.resetFilters();
          });
        } else {
          emptyNotice.show();
        }
      } else {
        if (emptyNotice.length) {
          emptyNotice.hide();
        }
      }
    },

    /**
     * Reset all filters to show all products
     */
    resetFilters: function () {
      this.searchQuery = '';
      $('.search-input, #product-search, input[name="search"]').val('');
      this.setCategory('all');
    },

    /**
     * 5. Swiper.js Slider Initializations
     */
    initSwiperSliders: function () {
      if (typeof Swiper === 'undefined') {
        console.warn('Swiper.js not loaded. Skipping slider initialization.');
        return;
      }

      // Hero Banner Swiper
      var heroEl = document.querySelector('.hero-slider-container, .hero-slider, .hero-swiper');
      if (heroEl) {
        new Swiper(heroEl, {
          loop: true,
          speed: 800,
          autoplay: {
            delay: 4500,
            disableOnInteraction: false,
            pauseOnMouseEnter: true
          },
          pagination: {
            el: '.swiper-pagination',
            clickable: true
          },
          navigation: {
            nextEl: '.swiper-button-next',
            prevEl: '.swiper-button-prev'
          },
          effect: 'fade',
          fadeEffect: {
            crossFade: true
          }
        });
      }

      // Customer Reviews Swiper
      var reviewsEl = document.querySelector('.reviews-slider, .testimonials-slider');
      if (reviewsEl) {
        new Swiper(reviewsEl, {
          slidesPerView: 1,
          spaceBetween: 20,
          loop: true,
          autoplay: {
            delay: 4000,
            disableOnInteraction: false
          },
          pagination: {
            el: '.reviews-pagination',
            clickable: true
          },
          breakpoints: {
            640: { slidesPerView: 2, spaceBetween: 16 },
            1024: { slidesPerView: 3, spaceBetween: 24 }
          }
        });
      }

      // Products Shelf Swiper
      var shelfEl = document.querySelector('.shelf-slider, .products-carousel');
      if (shelfEl) {
        new Swiper(shelfEl, {
          slidesPerView: 2,
          spaceBetween: 14,
          navigation: {
            nextEl: '.shelf-next',
            prevEl: '.shelf-prev'
          },
          breakpoints: {
            768: { slidesPerView: 3, spaceBetween: 16 },
            1024: { slidesPerView: 4, spaceBetween: 20 }
          }
        });
      }
    },

    /**
     * 6. Floating Action Buttons & Back to Top
     */
    initBackToTop: function () {
      var btn = document.querySelector('.floating-backtotop');
      if (!btn) return;

      window.addEventListener('scroll', function () {
        if (window.scrollY > 300) {
          btn.classList.add('visible');
        } else {
          btn.classList.remove('visible');
        }
      }, { passive: true });

      btn.addEventListener('click', function (e) {
        e.preventDefault();
        window.scrollTo({
          top: 0,
          behavior: 'smooth'
        });
      });
    },

    /**
     * 7. Quantity Steppers on Product Cards / Product Pages
     */
    initQuantitySpinners: function () {
      $(document).on('click', '.product-qty-minus', function (e) {
        e.preventDefault();
        var input = $(this).siblings('.qty-input, input[name="quantity"]');
        var val = parseInt(input.val(), 10) || 1;
        if (val > 1) {
          input.val(val - 1).trigger('change');
        }
      });

      $(document).on('click', '.product-qty-plus', function (e) {
        e.preventDefault();
        var input = $(this).siblings('.qty-input, input[name="quantity"]');
        var val = parseInt(input.val(), 10) || 1;
        input.val(val + 1).trigger('change');
      });
    },

    /**
     * 8. Wishlist Button Interactive Toggle
     */
    initWishlist: function () {
      $(document).on('click', '.wishlist-toggle-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.toggleClass('active');

        if (typeof toastr !== 'undefined') {
          if ($btn.hasClass('active')) {
            toastr.success('পণ্যটি আপনার পছন্দের তালিকায় যুক্ত হয়েছে!', 'উইশলিস্ট');
          } else {
            toastr.info('পণ্যটি উইশলিস্ট থেকে সরানো হয়েছে।', 'উইশলিস্ট');
          }
        }
      });
    },

    /**
     * 9. Handle URL Query Parameters (e.g. ?category=honey or ?search=rosh)
     */
    handleUrlParameters: function () {
      try {
        var params = new URLSearchParams(window.location.search);
        var cat = params.get('category') || params.get('cat');
        var search = params.get('search') || params.get('q');

        if (cat) {
          this.setCategory(cat);
        }

        if (search) {
          $('.search-input, #product-search, input[name="search"]').val(search);
          this.searchQuery = search;
          this.filterProductGrid();
        }

        // Handle Hash (#category-honey, #honey)
        if (window.location.hash) {
          var hash = window.location.hash.replace('#', '').replace('category-', '');
          if (hash === 'honey' || hash === 'gur' || hash === 'oil-ghee' || hash === 'organic' || hash === 'oils' || hash === 'seasonal') {
            this.setCategory(hash);
          }
        }
      } catch (e) {
        // Fallback for older browsers
      }
    },

    /**
     * Helper to escape HTML characters
     */
    escapeHtml: function (str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
  };

  // Expose NahidApp globally
  window.NahidApp = NahidApp;

  // Initialize once DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      NahidApp.init();
    });
  } else {
    NahidApp.init();
  }

})(window, document, window.jQuery || window.$);
