<?php
/**
 * PHPUnit bootstrap for ServerTrack.
 *
 * Defines all WordPress / WooCommerce stubs needed to load the plugin
 * classes in isolation, without a running WordPress installation.
 *
 * Run from repo root:
 *   vendor/bin/phpunit --testdox
 */

// ── WordPress core stubs ────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../' );
}

/** Global option store used by get_option / update_option stubs. */
$GLOBALS['_st_options'] = [];

function get_option( string $key, $default = false ) {
    return $GLOBALS['_st_options'][ $key ] ?? $default;
}

function update_option( string $key, $value, $autoload = null ): bool {
    $GLOBALS['_st_options'][ $key ] = $value;
    return true;
}

function delete_option( string $key ): bool {
    unset( $GLOBALS['_st_options'][ $key ] );
    return true;
}

function add_action(): void {}
function add_filter(): void {}
function get_woocommerce_currency(): string { return 'USD'; }
function get_current_user_id(): int { return 0; }
function absint( $val ): int { return abs( (int) $val ); }
function sanitize_text_field( $str ): string { return (string) $str; }
function wp_unslash( $val ) { return $val; }
function current_user_can( string $cap ): bool { return true; }
function check_ajax_referer(): void {}
function wp_send_json_success( $data = null ): void {}
function wp_send_json_error( $data = null ): void {}
function gmdate( string $format, $timestamp = null ): string {
    return \date( $format, $timestamp ?? time() );
}

// ── WooCommerce stubs ───────────────────────────────────────────────────

class WooCommerce {}

function wc_get_product( int $id ) {
    return $GLOBALS['_st_wc_products'][ $id ] ?? null;
}

function wc_get_order( int $id ) {
    return $GLOBALS['_st_wc_orders'][ $id ] ?? null;
}

class WC_Order {
    public int    $id           = 0;
    public float  $total        = 100.00;
    public string $currency     = 'USD';
    public array  $items        = [];

    public function get_id(): int        { return $this->id; }
    public function get_total(): float   { return $this->total; }
    public function get_currency(): string { return $this->currency; }
    public function get_items(): array   { return $this->items; }
    public function get_billing_email(): string { return 'test@example.com'; }
    public function get_billing_first_name(): string { return 'Test'; }
    public function get_billing_last_name(): string  { return 'User'; }
    public function get_billing_phone(): string      { return '+8801700000000'; }
    public function get_user_id(): int               { return 1; }
    public function get_amount(): float { return $this->total; }
}

class WC_Product {
    public int    $id    = 0;
    public string $name  = 'Test Product';
    public float  $price = 50.00;

    public function get_id(): int       { return $this->id; }
    public function get_name(): string  { return $this->name; }
    public function get_price(): float  { return $this->price; }
}

// ── ServerTrack dependency stubs ────────────────────────────────────────

/**
 * Stub logger — records calls so tests can assert on them.
 */
class ServerTrack_Logger {
    public static array $log = [];

    public static function info( string $msg ): void    { self::$log[] = [ 'level' => 'info',    'msg' => $msg ]; }
    public static function warning( string $msg ): void { self::$log[] = [ 'level' => 'warning', 'msg' => $msg ]; }
    public static function error( string $msg ): void   { self::$log[] = [ 'level' => 'error',   'msg' => $msg ]; }

    public static function reset(): void { self::$log = []; }
}

/**
 * Stub dedup — records mark_as_sent calls; already_sent is option-backed.
 */
class ServerTrack_Dedup {
    public static array $sent = []; // [ "$key:$platform" => true ]

    public static function already_sent( $key, string $platform ): bool {
        return isset( self::$sent[ "{$key}:{$platform}" ] );
    }

    public static function mark_as_sent( $key, string $platform ): void {
        self::$sent[ "{$key}:{$platform}" ] = true;
    }

    public static function reset(): void { self::$sent = []; }
}

/**
 * Stub identity.
 */
class ServerTrack_Identity {
    public static function from_order( $order ): array {
        return [ 'em' => hash( 'sha256', $order->get_billing_email() ) ];
    }
    public static function from_current_user(): array { return []; }
    public static function from_user_id( int $id ): array { return []; }
}

/**
 * Stub catalog.
 */
class ServerTrack_Catalog {
    public static function from_order( $order ): array {
        return [ 'value' => $order->get_total(), 'currency' => 'USD' ];
    }
    public static function from_order_summary( $order ): array { return []; }
    public static function from_cart(): array { return []; }
}

/**
 * Stub hasher.
 */
class ServerTrack_Hasher {
    public static function event_id( string $event, $seed ): string {
        return md5( $event . $seed );
    }
}

/**
 * Stub event.
 */
class ServerTrack_Event {
    public string $event_name;
    public string $event_id;
    public array  $user_data   = [];
    public array  $custom_data = [];

    public function __construct( string $event_name, string $event_id ) {
        $this->event_name = $event_name;
        $this->event_id   = $event_id;
    }

    public function set_user_data( array $data ): self {
        $this->user_data = $data;
        return $this;
    }

    public function set_custom_data( array $data ): self {
        $this->custom_data = $data;
        return $this;
    }
}

/**
 * Stub core dispatcher — records dispatched events.
 */
class ServerTrack_Core {
    public static array $dispatched = [];

    public static function dispatch_to_all( ServerTrack_Event $event, $dedup_key = null ): void {
        self::$dispatched[] = [
            'event'     => $event->event_name,
            'event_id'  => $event->event_id,
            'platforms' => [ 'meta', 'tiktok', 'google' ],
            'dedup_key' => $dedup_key,
            'custom'    => $event->custom_data,
        ];
    }

    public static function dispatch_to_platforms( ServerTrack_Event $event, array $platforms, $dedup_key = null ): void {
        self::$dispatched[] = [
            'event'     => $event->event_name,
            'event_id'  => $event->event_id,
            'platforms' => $platforms,
            'dedup_key' => $dedup_key,
            'custom'    => $event->custom_data,
        ];
    }

    public static function reset(): void { self::$dispatched = []; }
}

/**
 * Stub platform senders.
 */
class ServerTrack_Meta   { public static function send( $e ): array { return [ 'status' => 'success' ]; } }
class ServerTrack_TikTok { public static function send( $e ): array { return [ 'status' => 'success' ]; } }
class ServerTrack_Google { public static function send( $e ): array { return [ 'status' => 'success' ]; } }

// ── Autoload plugin classes ─────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-servertrack-retry.php';
require_once __DIR__ . '/../sources/class-servertrack-source-woocommerce.php';
