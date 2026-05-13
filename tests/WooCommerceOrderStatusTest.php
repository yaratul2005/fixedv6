<?php
/**
 * Tests for ServerTrack_Source_WooCommerce v3.3.1
 * Section: Order Status Events (BUG-09 fix)
 *
 * Covers:
 *   - on-hold  → Lead dispatched to all platforms
 *   - failed   → Contact dispatched to all platforms
 *   - cancelled→ SubmitForm dispatched to all platforms
 *   - Unknown status → no dispatch
 *   - BUG-09: dedup skips only when ALL three platforms already sent
 *   - BUG-09: event fires when only ONE platform already sent
 */

use PHPUnit\Framework\TestCase;

class WooCommerceOrderStatusTest extends TestCase {

    private WC_Order $order;

    protected function setUp(): void {
        ServerTrack_Core::reset();
        ServerTrack_Dedup::reset();
        ServerTrack_Logger::reset();

        $this->order     = new WC_Order();
        $this->order->id = 101;
    }

    // ── Status → event mapping ───────────────────────────────────────────

    public function test_on_hold_fires_lead(): void {
        ServerTrack_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'on-hold', $this->order );

        $this->assertCount( 1, ServerTrack_Core::$dispatched );
        $this->assertSame( 'Lead', ServerTrack_Core::$dispatched[0]['event'] );
    }

    public function test_failed_fires_contact(): void {
        ServerTrack_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'failed', $this->order );

        $this->assertCount( 1, ServerTrack_Core::$dispatched );
        $this->assertSame( 'Contact', ServerTrack_Core::$dispatched[0]['event'] );
    }

    public function test_cancelled_fires_submit_form(): void {
        ServerTrack_Source_WooCommerce::handle_order_status_change( 101, 'processing', 'cancelled', $this->order );

        $this->assertCount( 1, ServerTrack_Core::$dispatched );
        $this->assertSame( 'SubmitForm', ServerTrack_Core::$dispatched[0]['event'] );
    }

    public function test_unknown_status_fires_nothing(): void {
        ServerTrack_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'refunded', $this->order );

        $this->assertEmpty( ServerTrack_Core::$dispatched, 'Unknown status must not dispatch any event.' );
    }

    // ── Dedup (BUG-09) ───────────────────────────────────────────────────

    public function test_bug09_skips_when_all_platforms_already_sent(): void {
        $key = 'order_status_101_on-hold';
        ServerTrack_Dedup::mark_as_sent( $key, 'meta' );
        ServerTrack_Dedup::mark_as_sent( $key, 'tiktok' );
        ServerTrack_Dedup::mark_as_sent( $key, 'google' );

        ServerTrack_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'on-hold', $this->order );

        $this->assertEmpty( ServerTrack_Core::$dispatched,
            'BUG-09: event must be skipped when all 3 platforms have already been sent.'
        );
    }

    public function test_bug09_fires_when_only_one_platform_already_sent(): void {
        // Only Meta is already sent — original BUG-09 would have skipped all platforms
        $key = 'order_status_101_failed';
        ServerTrack_Dedup::mark_as_sent( $key, 'meta' );

        ServerTrack_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'failed', $this->order );

        $this->assertCount( 1, ServerTrack_Core::$dispatched,
            'BUG-09: event must still fire when only 1 of 3 platforms was already sent.'
        );
    }

    public function test_dedup_key_format_is_correct(): void {
        ServerTrack_Source_WooCommerce::handle_order_status_change( 202, 'pending', 'cancelled', $this->order );

        $dispatched = ServerTrack_Core::$dispatched[0];
        $this->assertSame( 'order_status_202_cancelled', $dispatched['dedup_key'] );
    }

    public function test_custom_data_includes_order_status(): void {
        ServerTrack_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'on-hold', $this->order );

        $custom = ServerTrack_Core::$dispatched[0]['custom'];
        $this->assertSame( 'on-hold', $custom['order_status'] );
        $this->assertSame( 101, $custom['order_id'] );
    }
}
