/**
 * ServerTrack - Browser Pixel & CAPI Bridge
 */
(function() {
    'use strict';

    if (typeof servertrack_config === 'undefined') return;
    const ST_Data = servertrack_config;

    // --- 1. Click IDs Capture ---
    const captureClickIds = function() {
        const params = new URLSearchParams(window.location.search);
        const ids = {
            fbclid: params.get('fbclid'),
            ttclid: params.get('ttclid'),
            gclid:  params.get('gclid'),
            msclkid: params.get('msclkid'),
            ScCid:  params.get('ScCid')
        };

        const captured = Object.entries(ids).filter(([, v]) => v);
        if (!captured.length) return;

        navigator.sendBeacon(
            ST_Data.rest_url + 'capture-clickids',
            JSON.stringify({
                ids:       Object.fromEntries(captured),
                page_url:  location.href,
                timestamp: Math.floor(Date.now() / 1000),
                _wpnonce:  ST_Data.nonce
            })
        );
    };
    captureClickIds();

    // --- 2. PII Email Capture at Checkout ---
    const setupCheckoutCapture = function() {
        const emailField = document.getElementById('billing_email');
        if (!emailField) return;

        let debounceTimer;
        emailField.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(async function() {
                const email = emailField.value.trim().toLowerCase();
                if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) return;

                // Hash client-side using SHA-256
                const hashBuffer = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(email));
                const hashHex = Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');

                navigator.sendBeacon(ST_Data.rest_url + 'capture-pii', JSON.stringify({
                    em: hashHex,
                    _wpnonce: ST_Data.nonce
                }));
            }, 800);
        });
    };
    if (ST_Data.is_checkout) setupCheckoutCapture();

    // --- 3. Pixel Initialization & Consent Gate ---
    let pixelsLoaded = false;
    const loadPixels = function(platforms) {
        if (pixelsLoaded) return;
        pixelsLoaded = true;

        if (platforms.includes('meta') && ST_Data.meta_pixel) {
            !function(f,b,e,v,n,t,s)
            {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};
            if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
            n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)}(window, document,'script',
            'https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', ST_Data.meta_pixel, ST_Data.advanced_matching || {});
            fbq('track', 'PageView', {}, { eventID: ST_Data.event_ids.PageView });
        }

        if (platforms.includes('tiktok') && ST_Data.tiktok_pixel) {
            !function (w, d, t) {
              w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
              ttq.load(ST_Data.tiktok_pixel);
              ttq.page();
            }(window, document, 'ttq');
        }

        // Additional ViewContent for Products
        if (ST_Data.is_product) {
            const productParams = {
                content_ids: ST_Data.content_ids,
                content_type: 'product',
                content_name: ST_Data.product_name,
                value: ST_Data.product_price,
                currency: ST_Data.store_currency
            };
            if (ST_Data.meta_pixel) fbq('track', 'ViewContent', productParams, { eventID: ST_Data.event_ids.ViewContent });
            if (ST_Data.tiktok_pixel && typeof ttq !== 'undefined') ttq.track('ViewContent', productParams, { event_id: ST_Data.event_ids.ViewContent });
        }
    };

    // Consent listeners
    window.addEventListener('st:consent:granted', function(e) {
        loadPixels(e.detail.platforms);
    });

    document.addEventListener('cookieyes_consent_update', function(e) {
        if (e.detail.accepted.includes('analytics') && e.detail.accepted.includes('advertisement')) {
            window.dispatchEvent(new CustomEvent('st:consent:granted', {
                detail: { platforms: ['meta', 'tiktok', 'google'] }
            }));
        }
    });

    document.addEventListener('cmplz_status_change', function() {
        if (typeof cmplz_has_consent === 'function' && cmplz_has_consent('statistics') && cmplz_has_consent('marketing')) {
            window.dispatchEvent(new CustomEvent('st:consent:granted', {
                detail: { platforms: ['meta', 'tiktok', 'google'] }
            }));
        }
    });

    // If no consent mode active, simulate granted immediately
    // Wait until document ready
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof cookieyes_consent_update === 'undefined' && typeof cmplz_has_consent === 'undefined') {
            window.dispatchEvent(new CustomEvent('st:consent:granted', {
                detail: { platforms: ['meta', 'tiktok', 'google'] }
            }));
        }
    });


    // --- 4. Synchronized Event Firing ---
    if (typeof jQuery !== 'undefined') {
        jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, $btn) {
            const productId = $btn.data('product_id');
            const price     = $btn.data('price') || 0;
            const eventId   = ST_Data.event_ids.AddToCart || crypto.randomUUID();

            const params = {
                content_ids: [productId ? productId.toString() : ''],
                content_type: 'product',
                value: price,
                currency: ST_Data.store_currency,
            };

            if (ST_Data.meta_pixel && typeof fbq !== 'undefined') {
                fbq('track', 'AddToCart', params, { eventID: eventId });
            }

            if (ST_Data.tiktok_pixel && typeof ttq !== 'undefined') {
                ttq.track('AddToCart', params, { event_id: eventId });
            }

            navigator.sendBeacon(ST_Data.rest_url + 'custom-event', JSON.stringify({
                event_name: 'AddToCart',
                event_id:   eventId,
                params:     params,
                _wpnonce:   ST_Data.nonce
            }));
        });
    }

})();
