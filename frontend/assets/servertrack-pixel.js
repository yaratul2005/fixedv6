/**
 * servertrack-pixel.js  v3.0
 *
 * Full browser + server dual-tracking with complete event deduplication.
 *
 * Deduplication contract:
 *   Every browser event that has a CAPI counterpart MUST share the same
 *   event_id string.  For the Purchase event the id comes from PHP config
 *   (stored in order meta by the server-side handler).  For all other
 *   conversion events (ViewContent, AddToCart, InitiateCheckout,
 *   AddPaymentInfo) we generate a single id in the browser, fire the pixel
 *   immediately with that id, then POST the same id to the REST bridge so
 *   CAPI sends the identical id — Meta/TikTok deduplicate on their end.
 *
 *   PageView, ViewCategory, Search and engagement events (scroll, video,
 *   wishlist) are browser-only or REST-only; they do not need dedup.
 */
(function () {
    'use strict';

    var cfg = window.servertrack_config;
    if (!cfg) return;

    // ─── FLAGS ──────────────────────────────────────────────────────────────
    var metaReady   = false;
    var ttqReady    = false;
    var gtagReady   = false;
    var scrollFired = {};
    var videoFired  = {};

    // ─── UTILITIES ──────────────────────────────────────────────────────────
    function genId() {
        return 'br_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
    }

    function getCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return m ? decodeURIComponent(m[2]) : '';
    }

    function parseFloat2(v) {
        var n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }

    // ─── TT EVENT MAP ────────────────────────────────────────────────────────
    var TT_MAP = {
        Purchase:             'CompletePayment',
        ViewContent:          'ViewContent',
        AddToCart:            'AddToCart',
        InitiateCheckout:     'InitiateCheckout',
        AddPaymentInfo:       'AddPaymentInfo',
        Search:               'Search',
        CompleteRegistration: 'CompleteRegistration',
        Lead:                 'SubmitForm',
        ViewCategory:         'ViewContent',
    };

    // ─── LOW-LEVEL SEND HELPERS ──────────────────────────────────────────────
    function sendMeta(eventName, params, eventID) {
        if (!metaReady) return;
        try { window.fbq('track', eventName, params || {}, { eventID: eventID }); } catch (e) {}
    }

    function sendMetaCustom(eventName, params, eventID) {
        if (!metaReady) return;
        try { window.fbq('trackCustom', eventName, params || {}, { eventID: eventID }); } catch (e) {}
    }

    function sendTT(eventName, params, eventID) {
        if (!ttqReady) return;
        var ttName = TT_MAP[eventName] || eventName;
        try { window.ttq.track(ttName, params || {}, { event_id: eventID }); } catch (e) {}
    }

    function sendTTCustom(eventName, params, eventID) {
        if (!ttqReady) return;
        try { window.ttq.track(eventName, params || {}, { event_id: eventID }); } catch (e) {}
    }

    function sendGtag(eventName, params) {
        if (!gtagReady) return;
        try { window.gtag('event', eventName, params || {}); } catch (e) {}
    }

    // ─── REST BRIDGE ─────────────────────────────────────────────────────────
    // Posts a browser-generated event to the server-side REST endpoint so CAPI
    // fires with the SAME event_id — this is the deduplication mechanism.
    function sendToServer(eventName, params, eventID, isCustom) {
        if (!cfg.rest_url || !cfg.rest_nonce) return;
        var payload = {
            event_name: eventName,
            event_id:   eventID,
            params:     params || {},
            is_custom:  isCustom || false,
            url:        window.location.href,
            fbc:        getCookie('_fbc'),
            fbp:        getCookie('_fbp'),
            ttclid:     getCookie('ttclid'),
        };
        // Use sendBeacon when available (non-blocking, survives page unload)
        var body = JSON.stringify(payload);
        var url  = cfg.rest_url.replace(/\/$/, '') + '/servertrack/v1/custom-event';
        var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce };
        if (navigator.sendBeacon) {
            var blob = new Blob([body], { type: 'application/json' });
            navigator.sendBeacon(url, blob);
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            Object.keys(headers).forEach(function (k) { xhr.setRequestHeader(k, headers[k]); });
            xhr.send(body);
        }
    }

    // ─── DUAL FIRE (browser pixel + REST bridge with SHARED event_id) ────────
    // This is the core of deduplication. One call fires both sides.
    function dualFire(eventName, browserParams, serverParams, eventID) {
        var id = eventID || genId();
        sendMeta(eventName, browserParams, id);
        sendTT(eventName, browserParams, id);
        sendToServer(eventName, serverParams || browserParams, id, false);
        return id;
    }

    // ─── META PIXEL INIT ─────────────────────────────────────────────────────
    function initMetaPixel() {
        if (!cfg.meta_enabled || !cfg.meta_pixel) return;

        /* Meta Pixel base snippet */
        /* eslint-disable */
        !function(f,b,e,v,n,t,s){
            if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};
            if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
            n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)
        }(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
        /* eslint-enable */

        // Advanced Matching: pass raw email for logged-in users (pixel hashes internally)
        // Never pass a pre-hashed string to fbq('init') — Meta cannot normalise it.
        var advancedMatch = {};
        if (cfg.user_email) {
            advancedMatch.em = cfg.user_email;
        }
        if (cfg.user_external_id) {
            advancedMatch.external_id = cfg.user_external_id;
        }

        if (Object.keys(advancedMatch).length) {
            window.fbq('init', cfg.meta_pixel, advancedMatch);
        } else {
            window.fbq('init', cfg.meta_pixel);
        }

        metaReady = true;

        // On the Purchase (order-received) page fire Purchase with stored event_id
        // instead of PageView. The server has already sent (or will send) Purchase
        // with this same id — Meta deduplicates on event_id match.
        if (cfg.event_name === 'Purchase' && cfg.event_id) {
            sendMeta('Purchase', {
                value:        cfg.value    || 0,
                currency:     cfg.currency || 'USD',
                content_ids:  cfg.content_ids || [],
                contents:     cfg.contents   || [],
                content_type: 'product',
                order_id:     cfg.order_id   || '',
                num_items:    (cfg.contents || []).length,
            }, cfg.event_id);
            // No PageView on the thank-you page — Purchase is the conversion event.
        } else {
            // Every other page: PageView (browser-only, no CAPI counterpart needed)
            window.fbq('track', 'PageView');

            // Product page: ViewContent (dual-fire)
            if (cfg.is_product && cfg.product_id) {
                dualFire('ViewContent', {
                    content_ids:  cfg.content_ids || [String(cfg.product_id)],
                    content_name: cfg.product_name || '',
                    content_type: 'product',
                    value:        cfg.product_price || 0,
                    currency:     cfg.store_currency || 'USD',
                }, null, null);
            }

            // Category / archive page: ViewCategory (browser-only custom event)
            if (cfg.is_product_archive && cfg.current_category) {
                sendMetaCustom('ViewCategory', { content_category: cfg.current_category }, genId());
            }

            // Search: Search standard event (browser-only)
            if (cfg.is_search && cfg.search_query) {
                sendMeta('Search', { search_string: cfg.search_query }, genId());
            }
        }
    }

    // ─── TIKTOK PIXEL INIT ───────────────────────────────────────────────────
    function initTikTokPixel() {
        if (!cfg.tt_enabled || !cfg.tiktok_pixel) return;

        /* TikTok Pixel base snippet */
        /* eslint-disable */
        !function(w,d,t){
            w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];
            ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie','holdConsent','revokeConsent','grantConsent'];
            ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};
            for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);
            ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};
            ttq.load=function(e,n){var r='https://analytics.tiktok.com/i18n/pixel/events.js',o=n&&n.partner;
            ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=r;ttq._t=ttq._t||{};
            ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};
            n=document.createElement('script');n.type='text/javascript';
            n.async=!0;n.src=r+'?sdkid='+e+'&lib='+t;
            e=document.getElementsByTagName('script')[0];
            e.parentNode.insertBefore(n,e)};
            ttq.load(cfg.tiktok_pixel);
            // NOTE: ttq.page() is called automatically by the snippet — do NOT call it manually
        }(window,document,'ttq');
        /* eslint-enable */

        // Identify logged-in user (hashed email / phone handled server-side;
        // here we only pass the external_id to tie browser session to CAPI)
        if (cfg.user_external_id) {
            try { window.ttq.identify({ external_id: cfg.user_external_id }); } catch(e) {}
        }

        ttqReady = true;

        // Purchase page
        if (cfg.event_name === 'Purchase' && cfg.event_id) {
            sendTT('Purchase', {
                value:    cfg.value    || 0,
                currency: cfg.currency || 'USD',
                contents: (cfg.contents || []).map(function(c) {
                    return { content_id: c.id, quantity: c.quantity, price: c.item_price };
                }),
            }, cfg.event_id);
        }

        // ViewContent on product page (browser pixel only — dualFire already handles server)
        if (cfg.is_product && cfg.product_id) {
            // Already fired via dualFire in Meta init — TT side was included there
            // (sendTT is called inside dualFire). Nothing extra needed here.
        }
    }

    // ─── GOOGLE GTAG INIT ────────────────────────────────────────────────────
    function initGoogleGtag() {
        if (!cfg.google_enabled || !cfg.gtag_id) return;

        // FIX: gtag('js') MUST come before gtag('config') — reverse order causes warning
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + cfg.gtag_id;
        document.head.appendChild(s);

        window.dataLayer = window.dataLayer || [];
        window.gtag = function () { window.dataLayer.push(arguments); };
        window.gtag('js', new Date());
        window.gtag('config', cfg.gtag_id, { send_page_view: true });
        gtagReady = true;

        // Purchase conversion
        if (cfg.event_name === 'Purchase' && cfg.event_id && cfg.gtag_label) {
            window.gtag('event', 'conversion', {
                send_to:        cfg.gtag_id + '/' + cfg.gtag_label,
                value:          cfg.value    || 0,
                currency:       cfg.currency || 'USD',
                transaction_id: String(cfg.order_id || ''),
                gclid:          cfg.gclid    || undefined,
            });
        }
    }

    // ─── WOOCOMMERCE EVENT BINDINGS ──────────────────────────────────────────

    // Add To Cart — handles both standard form submit and AJAX add-to-cart
    function bindAddToCart() {
        // Classic "add to cart" button on product page
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.single_add_to_cart_button, .add_to_cart_button');
            if (!btn) return;

            var id       = btn.getAttribute('data-product_id') || (cfg.product_id ? String(cfg.product_id) : '');
            var price    = parseFloat2(btn.getAttribute('data-price') || (cfg.product_price ? String(cfg.product_price) : '0'));
            var name     = btn.getAttribute('data-product_name') || cfg.product_name || '';
            var qty      = 1;
            var qtyInput = document.querySelector('.qty');
            if (qtyInput) qty = parseInt(qtyInput.value, 10) || 1;

            var params = {
                content_ids:  [id],
                content_name: name,
                content_type: 'product',
                value:        price * qty,
                currency:     cfg.store_currency || 'USD',
                contents:     [{ id: id, quantity: qty, item_price: price }],
            };
            dualFire('AddToCart', params, null, null);
            sendGtag('add_to_cart', { items: [{ item_id: id, item_name: name, price: price, quantity: qty }], currency: cfg.store_currency || 'USD', value: price * qty });
        });

        // WooCommerce AJAX add-to-cart success (archive / shop page)
        document.addEventListener('added_to_cart', function (e) {
            var btn      = e.detail && e.detail.$button ? e.detail.$button[0] : null;
            var id       = btn ? (btn.getAttribute('data-product_id') || '') : '';
            var price    = parseFloat2(btn ? btn.getAttribute('data-price') : '0');
            var name     = btn ? (btn.getAttribute('data-product_name') || '') : '';
            if (!id) return;
            var params = {
                content_ids:  [id],
                content_name: name,
                content_type: 'product',
                value:        price,
                currency:     cfg.store_currency || 'USD',
                contents:     [{ id: id, quantity: 1, item_price: price }],
            };
            dualFire('AddToCart', params, null, null);
        });
    }

    // Initiate Checkout
    function bindInitiateCheckout() {
        if (!cfg.is_checkout) return;
        var fired = false;
        document.addEventListener('click', function (e) {
            if (fired) return;
            var btn = e.target.closest('#place_order, .checkout-button, .wc-proceed-to-checkout .button');
            if (!btn) return;
            fired = true;
            dualFire('InitiateCheckout', {
                num_items: (cfg.contents || []).reduce(function (s, c) { return s + (c.quantity || 1); }, 0),
                currency:  cfg.store_currency || 'USD',
            }, null, null);
            sendGtag('begin_checkout', { currency: cfg.store_currency || 'USD' });
        });
    }

    // Add Payment Info — fires when payment method is selected at checkout
    function bindAddPaymentInfo() {
        if (!cfg.is_checkout) return;
        var fired = false;
        document.addEventListener('change', function (e) {
            if (fired) return;
            var el = e.target;
            if (!el || el.getAttribute('name') !== 'payment_method') return;
            fired = true;
            dualFire('AddPaymentInfo', {
                currency:     cfg.store_currency || 'USD',
                payment_type: el.value || '',
            }, null, null);
            sendGtag('add_payment_info', { payment_type: el.value || '', currency: cfg.store_currency || 'USD' });
        });
    }

    // ─── ENGAGEMENT TRACKING ────────────────────────────────────────────────

    // Scroll depth: 25 / 50 / 75 / 100 %
    function bindScrollDepth() {
        if (!cfg.scroll_depth_enabled) return;
        var milestones = [25, 50, 75, 100];
        function onScroll() {
            var scrolled = window.scrollY + window.innerHeight;
            var total    = document.documentElement.scrollHeight;
            var pct      = Math.round((scrolled / total) * 100);
            milestones.forEach(function (m) {
                if (pct >= m && !scrollFired[m]) {
                    scrollFired[m] = true;
                    var id = genId();
                    sendMetaCustom('ScrollDepth', { percent: m }, id);
                    sendTTCustom('ScrollDepth', { percent: m }, id);
                    sendToServer('ScrollDepth', { percent: m }, id, true);
                }
            });
        }
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    // Video progress: 25 / 50 / 75 / 100 % for HTML5 <video> elements
    function bindVideoTracking() {
        if (!cfg.video_tracking_enabled) return;
        var milestones = [25, 50, 75, 100];
        function attachToVideo(video) {
            var vidId = video.src || video.currentSrc || 'video_' + Date.now();
            if (!videoFired[vidId]) videoFired[vidId] = {};
            video.addEventListener('timeupdate', function () {
                if (!video.duration) return;
                var pct = Math.round((video.currentTime / video.duration) * 100);
                milestones.forEach(function (m) {
                    if (pct >= m && !videoFired[vidId][m]) {
                        videoFired[vidId][m] = true;
                        var id = genId();
                        sendMetaCustom('VideoProgress', { percent: m, video_url: vidId }, id);
                        sendTTCustom('VideoProgress',  { percent: m, video_url: vidId }, id);
                        sendToServer('VideoProgress',  { percent: m, video_url: vidId }, id, true);
                    }
                });
            });
        }
        document.querySelectorAll('video').forEach(attachToVideo);
        // Observe dynamically inserted videos
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    m.addedNodes.forEach(function (node) {
                        if (node.nodeName === 'VIDEO') attachToVideo(node);
                        if (node.querySelectorAll) node.querySelectorAll('video').forEach(attachToVideo);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    // Wishlist: YITH / WooCommerce Wishlist button clicks
    function bindWishlist() {
        if (!cfg.wishlist_enabled) return;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.yith-wcwl-add-button a, .add_to_wishlist, [data-product-id].wishlist-btn');
            if (!btn) return;
            var id  = btn.getAttribute('data-product-id') || btn.getAttribute('data-product_id') || '';
            var evId = genId();
            sendMetaCustom('AddToWishlist', { content_ids: [id] }, evId);
            sendTTCustom('AddToWishlist',  { content_ids: [id] }, evId);
            sendToServer('AddToWishlist',  { content_ids: [id] }, evId, true);
        });
    }

    // ─── BOOT ───────────────────────────────────────────────────────────────
    // Pixels must be initialised synchronously (before DOMContentLoaded) so
    // that any events fired during page load are captured in the queue.
    initMetaPixel();
    initTikTokPixel();
    initGoogleGtag();

    // Bind interaction events after DOM is ready
    function onReady(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    onReady(function () {
        bindAddToCart();
        bindInitiateCheckout();
        bindAddPaymentInfo();
        bindScrollDepth();
        bindVideoTracking();
        bindWishlist();
    });

}());
