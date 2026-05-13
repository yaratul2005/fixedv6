<?php
/**
 * Tests for ServerTrack_Source_WooCommerce v3.3.1
 * Section: AddToWishlist Events (BUG-10 fix)
 *
 * Covers:
 *   - YITH hook dispatches AddToWishlist
 *   - TI hook dispatches AddToWishlist
 *   - Only Meta and TikTok receive the event (not Google)
 *   - BUG-10: dedup loop result was discarded — event must NOT fire when both platforms already sent
 *   - BUG-10: event fires when only one platform already sent (pending_platforms logic)
 *   - Product not found → no dispatch
 *   - custom_data includes content_ids, content_name, value, currency
 */

use PHPUnit\Framework\TestCase;

class WooCommerceWishlistTest extends TestCase {

    protected function setUp(): void {
        ServerTrack_Core::reset();
        ServerTrack_Dedup::reset();

        $product        = new WC_Product();
        $product->id    = 55;
        $product->name  = 'Blue Sneakers';
        $product->price = 79.99;

        $GLOBALS['_st_wc_products'] = [ 55 => $product ];
    }

    protected function tearDown(): void {
        $GLOBALS['_st_wc_products'] = [];
    }

    public function test_yith_hook_dispatches_add_to_wishlist(): void {
        ServerTrack_Source_WooCommerce::handle_add_to_wishlist( 55, 1 );

        $this->assertCount( 1, ServerTrack_Core::$dispatched );
        $this->assertSame( 'AddToWishlist', ServerTrack_Core::$dispatched[0]['event'] );
    }

    public function test_ti_hook_dispatches_add_to_wishlist(): void {
        ServerTrack_Source_WooCommerce::handle_add_to_wishlist_ti( 55, 0 );

        $this->assertCount( 1, ServerTrack_Core::$dispatched );
        $this->assertSame( 'AddToWishlist', ServerTrack_Core::$dispatched[0]['event'] );
    }

    public function test_dispatches_to_meta_and_tiktok_only(): void {
        ServerTrack_Source_WooCommerce::handle_add_to_wishlist( 55, 1 );

        $platforms = ServerTrack_Core::$dispatched[0]['platforms'];
        $this->assertContains( 'meta',   $platforms );
        $this->assertContains( 'tiktok', $platforms );
        $this->assertNotContains( 'google', $platforms,
            'Google must not receive AddToWishlist (no native GA4 event).' );
    }

    public function test_bug10_no_dispatch_when_all_platforms_sent(): void {
        // Simulate both Meta and TikTok already sent
        // uid_part = 0 (no user, no session in test env)
        $key = 'wishlist_yith_0_55';
        ServerTrack_Dedup::mark_as_sent( $key, 'meta' );
        ServerTrack_Dedup::mark_as_sent( $key, 'tiktok' );

        ServerTrack_Source_WooCommerce::handle_add_to_wishlist( 55, 1 );

        $this->assertEmpty( ServerTrack_Core::$dispatched,
            'BUG-10: event must not fire when both Meta and TikTok already received it.' );
    }

    public function test_bug10_fires_to_pending_platform_only(): void {
        // Only Meta already sent — original BUG-10 would have fired to both
        $key = 'wishlist_yith_0_55';
        ServerTrack_Dedup::mark_as_sent( $key, 'meta' );

        ServerTrack_Source_WooCommerce::handle_add_to_wishlist( 55, 1 );

        $this->assertCount( 1, ServerTrack_Core::$dispatched,
            'BUG-10: event must still fire to pending platform (TikTok) when only Meta was sent.' );

        $platforms = ServerTrack_Core::$dispatched[0]['platforms'];
        $this->assertNotContains( 'meta',   $platforms, 'Meta must be excluded (already sent).' );
        $this->assertContains(    'tiktok', $platforms, 'TikTok must be included (pending).' );
    }

    public function test_no_dispatch_for_unknown_product(): void {
        // product ID 999 is not in the stub store
        ServerTrack_Source_WooCommerce::handle_add_to_wishlist( 999, 1 );

        $this->assertEmpty( ServerTrack_Core::$dispatched,
            'Unknown product must result in no dispatch.' );
    }

    public function test_custom_data_structure(): void {
        ServerTrack_Source_WooCommerce::handle_add_to_wishlist( 55, 1 );

        $custom = ServerTrack_Core::$dispatched[0]['custom'];
        $this->assertSame( [ '55' ],           $custom['content_ids'] );
        $this->assertSame( 'Blue Sneakers',    $custom['content_name'] );
        $this->assertSame( 79.99,              $custom['value'] );
        $this->assertSame( 'USD',              $custom['currency'] );
    }
}
