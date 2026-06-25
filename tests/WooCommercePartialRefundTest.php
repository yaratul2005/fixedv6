<?php
/**
 * Tests for ServerTrack_Source_WooCommerce v3.3.1
 * Section: Partial Refund Events + Full Refund Events (BUG-12 fix)
 *
 * Covers:
 *   - Partial refund dispatches Purchase with negative value
 *   - Full refund (amount == total) is skipped by handle_partial_refund
 *   - Negative value equals exact refund amount, not order total
 *   - Dedup prevents double-fire for same refund_id
 *   - BUG-12: handle_full_refund skipped TikTok/Google if Meta already sent
 *   - handle_full_refund fires when no platforms sent yet
 *   - handle_full_refund skips when all 3 platforms already sent
 *   - Missing order or refund object → no dispatch
 */

use PHPUnit\Framework\TestCase;

class WooCommercePartialRefundTest extends TestCase {

    protected function setUp(): void {
        ServerTrack_Core::reset();
        ServerTrack_Dedup::reset();

        // Order: total $100
        $order        = new WC_Order();
        $order->id    = 200;
        $order->total = 100.00;

        // Partial refund: $25 (not equal to $100 total)
        $partial        = new WC_Order();
        $partial->id    = 301;
        $partial->total = 25.00;

        // Full refund: $100 (equals order total)
        $full        = new WC_Order();
        $full->id    = 302;
        $full->total = 100.00;

        $GLOBALS['_st_wc_orders'] = [
            200 => $order,
            301 => $partial,
            302 => $full,
        ];
    }

    protected function tearDown(): void {
        $GLOBALS['_st_wc_orders'] = [];
    }

    // ── handle_partial_refund() ──────────────────────────────────────────

    public function test_partial_refund_dispatches_purchase_event(): void {
        ServerTrack_Source_WooCommerce::handle_partial_refund( 200, 301 );

        $this->assertCount( 1, ServerTrack_Core::$dispatched );
        $this->assertSame( 'Purchase', ServerTrack_Core::$dispatched[0]['event'] );
    }

    public function test_partial_refund_value_is_negative(): void {
        ServerTrack_Source_WooCommerce::handle_partial_refund( 200, 301 );

        $value = ServerTrack_Core::$dispatched[0]['custom']['value'];
        $this->assertLessThan( 0, $value, 'Partial refund value must be negative.' );
    }

    public function test_partial_refund_value_equals_exact_refund_amount(): void {
        ServerTrack_Source_WooCommerce::handle_partial_refund( 200, 301 );

        $value = ServerTrack_Core::$dispatched[0]['custom']['value'];
        $this->assertSame( -25.00, $value, 'Value must equal the exact refund amount, not the order total.' );
    }

    public function test_partial_refund_custom_data_has_refund_type(): void {
        ServerTrack_Source_WooCommerce::handle_partial_refund( 200, 301 );

        $this->assertSame( 'partial', ServerTrack_Core::$dispatched[0]['custom']['refund_type'] );
    }

    public function test_full_refund_is_skipped_by_partial_handler(): void {
        // Refund ID 302 has amount == order total ($100) — should be skipped
        ServerTrack_Source_WooCommerce::handle_partial_refund( 200, 302 );

        $this->assertEmpty( ServerTrack_Core::$dispatched,
            'Full refund must be skipped by handle_partial_refund().' );
    }

    public function test_partial_refund_dedup_key_is_per_refund_id(): void {
        ServerTrack_Source_WooCommerce::handle_partial_refund( 200, 301 );

        $dedup = ServerTrack_Core::$dispatched[0]['dedup_key'];
        $this->assertSame( 'partial_refund_301', $dedup );
    }

    public function test_partial_refund_dedup_prevents_double_fire(): void {
        ServerTrack_Dedup::mark_as_sent( 'partial_refund_301', 'meta' );
        ServerTrack_Dedup::mark_as_sent( 'partial_refund_301', 'tiktok' );
        ServerTrack_Dedup::mark_as_sent( 'partial_refund_301', 'google' );

        ServerTrack_Source_WooCommerce::handle_partial_refund( 200, 301 );

        $this->assertEmpty( ServerTrack_Core::$dispatched,
            'Dedup must prevent partial refund from firing twice for the same refund_id.' );
    }

    public function test_partial_refund_missing_order_returns_early(): void {
        ServerTrack_Source_WooCommerce::handle_partial_refund( 999, 301 ); // 999 not in stub store

        $this->assertEmpty( ServerTrack_Core::$dispatched,
            'Missing order must result in no dispatch.' );
    }

    // ── handle_full_refund() (BUG-12) ────────────────────────────────────

    public function test_full_refund_dispatches_negative_purchase(): void {
        ServerTrack_Source_WooCommerce::handle_full_refund( 200, 302 );

        $this->assertCount( 1, ServerTrack_Core::$dispatched );
        $this->assertSame( 'Purchase', ServerTrack_Core::$dispatched[0]['event'] );
        $this->assertLessThan( 0, ServerTrack_Core::$dispatched[0]['custom']['value'] );
    }

    public function test_bug12_full_refund_fires_when_no_platforms_sent(): void {
        ServerTrack_Source_WooCommerce::handle_full_refund( 200, 302 );

        $this->assertCount( 1, ServerTrack_Core::$dispatched,
            'BUG-12: full refund must fire when no platforms have been sent yet.' );
    }

    public function test_bug12_full_refund_skips_when_all_platforms_sent(): void {
        $key = 'full_refund_200';
        ServerTrack_Dedup::mark_as_sent( $key, 'meta' );
        ServerTrack_Dedup::mark_as_sent( $key, 'tiktok' );
        ServerTrack_Dedup::mark_as_sent( $key, 'google' );

        ServerTrack_Source_WooCommerce::handle_full_refund( 200, 302 );

        $this->assertEmpty( ServerTrack_Core::$dispatched,
            'BUG-12: full refund must be skipped when all 3 platforms already sent.' );
    }

    public function test_bug12_full_refund_fires_when_only_meta_sent(): void {
        // BUG-12 original: only 'meta' was checked — if Meta was sent, TikTok+Google were skipped too
        $key = 'full_refund_200';
        ServerTrack_Dedup::mark_as_sent( $key, 'meta' ); // only Meta sent

        ServerTrack_Source_WooCommerce::handle_full_refund( 200, 302 );

        $this->assertCount( 1, ServerTrack_Core::$dispatched,
            'BUG-12: full refund must still fire for TikTok+Google when only Meta was sent.' );
    }
}
