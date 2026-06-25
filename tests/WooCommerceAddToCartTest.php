<?php
/**
 * Tests for ServerTrack_Source_WooCommerce v3.3.1
 * Section: AddToCart + BUG-11 signature fix
 *
 * Covers:
 *   - AddToCart dispatches to all platforms
 *   - custom_data includes correct value (price * quantity)
 *   - BUG-11: handler accepts 6 args without PHP warnings
 *   - Missing product → no dispatch
 */

use PHPUnit\Framework\TestCase;

class WooCommerceAddToCartTest extends TestCase {

    protected function setUp(): void {
        ServerTrack_Core::reset();
        ServerTrack_Dedup::reset();

        $product        = new WC_Product();
        $product->id    = 77;
        $product->name  = 'Red T-Shirt';
        $product->price = 20.00;

        $GLOBALS['_st_wc_products'] = [ 77 => $product ];
    }

    protected function tearDown(): void {
        $GLOBALS['_st_wc_products'] = [];
    }

    public function test_add_to_cart_dispatches_event(): void {
        // Call with all 6 args (BUG-11 fix verification)
        ServerTrack_Source_WooCommerce::handle_add_to_cart(
            'cart_key_abc', 77, 2, 0, [], []
        );

        $this->assertCount( 1, ServerTrack_Core::$dispatched );
        $this->assertSame( 'AddToCart', ServerTrack_Core::$dispatched[0]['event'] );
    }

    public function test_add_to_cart_value_is_price_times_quantity(): void {
        ServerTrack_Source_WooCommerce::handle_add_to_cart(
            'cart_key_abc', 77, 3, 0, [], []
        );

        $custom = ServerTrack_Core::$dispatched[0]['custom'];
        $this->assertSame( 60.00, $custom['value'],   'Value must be price ($20) × quantity (3) = $60.' );
        $this->assertSame( 3,     $custom['num_items'] );
    }

    public function test_add_to_cart_content_ids_contains_product_id(): void {
        ServerTrack_Source_WooCommerce::handle_add_to_cart(
            'cart_key_abc', 77, 1, 0, [], []
        );

        $this->assertSame( [ '77' ], ServerTrack_Core::$dispatched[0]['custom']['content_ids'] );
    }

    public function test_bug11_accepts_six_arguments_without_error(): void {
        // If PHP raises an ArgumentCountError or warning, this test fails
        $this->expectNotToPerformAssertions();

        ServerTrack_Source_WooCommerce::handle_add_to_cart(
            'ck', 77, 1,
            0,             // $variation_id
            [],            // $variation
            [ 'meta' => 'data' ]  // $cart_item_data
        );
    }

    public function test_add_to_cart_missing_product_fires_nothing(): void {
        ServerTrack_Source_WooCommerce::handle_add_to_cart(
            'ck_missing', 9999, 1, 0, [], []
        );

        $this->assertEmpty( ServerTrack_Core::$dispatched,
            'Unknown product must result in no dispatch.' );
    }
}
