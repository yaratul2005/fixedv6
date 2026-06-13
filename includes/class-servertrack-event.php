<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Event  v2.1
 *
 * Lightweight DTO (Data Transfer Object) that carries event data
 * between sources (WooCommerce, CF7, EDD) and platform senders
 * (Meta, Google, TikTok).
 *
 * IMPORTANT FIX (v2.1):
 *   Added 'event_source_url' field to the DTO so that the real page URL
 *   captured in browser context can be passed through to async cron sends.
 *   Previously, platform senders built event_source_url from
 *   $_SERVER['REQUEST_URI'] which resolves to '/wp-cron.php' in cron —
 *   giving Meta wrong attribution data on every Purchase event.
 *
 * Usage:
 *   $event = new ServerTrack_Event( 'Purchase', $event_id );
 *   $event->set_user_data( $user_data )
 *         ->set_custom_data( $custom_data )
 *         ->set_source_url( 'https://example.com/checkout/order-received/123/' );
 */
class ServerTrack_Event {

    /** @var string Standard event name (e.g. 'Purchase', 'ViewContent'). */
    public string $event_name;

    /** @var string UUID v4 event ID for deduplication across browser + server. */
    public string $event_id;

    /**
     * @var array Hashed/raw user identifiers.
     * Keys: email, phone, first_name, last_name, city, state, zip, country,
     *       ip, user_agent, fbp, fbc, ttclid, gclid, external_id
     */
    public array $user_data = [];

    /**
     * @var array Platform-specific event parameters.
     * Keys: currency, value, contents, content_ids, content_type,
     *       order_id, num_items, content_name, status, ...
     */
    public array $custom_data = [];

    /**
     * @var string The real page URL where the event originated.
     * Captured in browser context and passed to async cron.
     * If empty, platform senders fall back to home_url().
     */
    public string $event_source_url = '';

    public function __construct( string $event_name, string $event_id ) {
        $this->event_name = $event_name;
        $this->event_id   = $event_id;
    }

    /** @return static Fluent setter. */
    public function set_user_data( array $user_data ): static {
        $this->user_data = $user_data;
        return $this;
    }

    /** @return static Fluent setter. */
    public function set_custom_data( array $custom_data ): static {
        $this->custom_data = $custom_data;
        return $this;
    }

    /**
     * Set the originating page URL.
     * Call this in browser context before dispatching async cron.
     *
     * @param string $url  Full URL e.g. 'https://shop.com/checkout/order-received/99/'
     * @return static Fluent setter.
     */
    public function set_source_url( string $url ): static {
        $this->event_source_url = esc_url_raw( $url );
        return $this;
    }
}
