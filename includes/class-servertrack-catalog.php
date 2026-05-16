<?php
/**
 * ServerTrack — Product Catalog Signal Enrichment (Feature #7)
 *
 * Enriches every product-level CAPI event (ViewContent, AddToCart, Purchase)
 * with rich catalog data that Meta, TikTok, and Google use to:
 *   - Match events to the correct catalog item (improves Dynamic Ads)
 *   - Power Dynamic Product Ads retargeting
 *   - Build value-based audiences using product category and margin signals
 *
 * Fields added:
 *   content_ids      — array of SKUs/product IDs
 *   content_name     — product title(s)
 *   content_category — WooCommerce category slug(s)
 *   content_type     — 'product' | 'product_group' (for variants)
 *   contents         — [{id, quantity, item_price, brand, category}]
 *   num_items        — total quantity across all items
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Catalog {

    /**
     * Register filters.
     */
    public static function init(): void {
        // Enrich ViewContent events
        add_filter( 'servertrack_view_content_custom_data',  [ __CLASS__, 'enrich_view_content' ],  10, 2 );
        // Enrich AddToCart events (product ID passed as context)
        add_filter( 'servertrack_add_to_cart_custom_data',   [ __CLASS__, 'enrich_add_to_cart' ],   10, 2 );
        // Enrich Purchase events (already has order; we upgrade contents array)
        add_filter( 'servertrack_purchase_custom_data',      [ __CLASS__, 'enrich_purchase' ],      20, 2 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Per-event enrichment
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enrich a ViewContent event with full product catalog data.
     *
     * @param array $custom_data
     * @param int   $product_id
     * @return array
     */
    public static function enrich_view_content( array $custom_data, int $product_id ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return $custom_data;
        }

        $catalog = self::get_product_catalog_data( $product );

        return array_merge( $custom_data, [
            'content_ids'      => [ $catalog['id'] ],
            'content_name'     => $catalog['name'],
            'content_category' => $catalog['category'],
            'content_type'     => $catalog['content_type'],
            'contents'         => [ [
                'id'         => $catalog['id'],
                'quantity'   => 1,
                'item_price' => $catalog['price'],
                'brand'      => $catalog['brand'],
                'category'   => $catalog['category'],
            ] ],
            'num_items'        => 1,
            'value'            => $catalog['price'],
        ] );
    }

    /**
     * Enrich an AddToCart event.
     *
     * @param array $custom_data
     * @param array $cart_item  WooCommerce cart item array
     * @return array
     */
    public static function enrich_add_to_cart( array $custom_data, array $cart_item ): array {
        $product_id = $cart_item['product_id'] ?? 0;
        $quantity   = $cart_item['quantity']   ?? 1;
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            return $custom_data;
        }

        $catalog = self::get_product_catalog_data( $product );

        return array_merge( $custom_data, [
            'content_ids'      => [ $catalog['id'] ],
            'content_name'     => $catalog['name'],
            'content_category' => $catalog['category'],
            'content_type'     => $catalog['content_type'],
            'contents'         => [ [
                'id'         => $catalog['id'],
                'quantity'   => $quantity,
                'item_price' => $catalog['price'],
                'brand'      => $catalog['brand'],
                'category'   => $catalog['category'],
            ] ],
            'num_items'        => $quantity,
            'value'            => round( $catalog['price'] * $quantity, 2 ),
        ] );
    }

    /**
     * Upgrade the Purchase contents array with full catalog fields.
     *
     * @param array    $custom_data
     * @param WC_Order $order
     * @return array
     */
    public static function enrich_purchase( array $custom_data, WC_Order $order ): array {
        $content_ids  = [];
        $content_names = [];
        $categories   = [];
        $contents     = [];
        $num_items    = 0;

        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $catalog = self::get_product_catalog_data( $product );
            $qty     = $item->get_quantity();

            $content_ids[]   = $catalog['id'];
            $content_names[] = $catalog['name'];
            if ( $catalog['category'] ) {
                $categories[] = $catalog['category'];
            }
            $num_items += $qty;

            $contents[] = [
                'id'         => $catalog['id'],
                'quantity'   => $qty,
                'item_price' => round( (float) $item->get_total() / max( 1, $qty ), 2 ),
                'brand'      => $catalog['brand'],
                'category'   => $catalog['category'],
                'name'       => $catalog['name'],
            ];
        }

        $enriched = [
            'content_ids'      => array_unique( $content_ids ),
            'content_name'     => implode( ', ', array_unique( $content_names ) ),
            'content_category' => implode( ', ', array_unique( $categories ) ),
            'content_type'     => count( $content_ids ) === 1 ? $contents[0]['content_type'] ?? 'product' : 'product',
            'contents'         => $contents,
            'num_items'        => $num_items,
        ];

        return array_merge( $custom_data, $enriched );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Product data extraction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extract standardised catalog data from a WC_Product.
     *
     * @param WC_Product $product
     * @return array { id, name, price, category, brand, content_type }
     */
    public static function get_product_catalog_data( WC_Product $product ): array {
        // ID: prefer SKU for catalog matching; fall back to numeric ID
        $sku = $product->get_sku();
        $id  = $sku ?: (string) $product->get_id();

        // Price: use sale price if active, else regular price
        $price = (float) ( $product->is_on_sale()
            ? $product->get_sale_price()
            : $product->get_regular_price() );

        // Categories: first category slug
        $category = '';
        $term_ids  = $product->get_category_ids();
        if ( ! empty( $term_ids ) ) {
            $term = get_term( $term_ids[0], 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $category = $term->name;
            }
        }

        // Brand: support WooCommerce Brands plugin attribute 'pa_brand'
        $brand = '';
        $brand_terms = wp_get_post_terms( $product->get_id(), 'pa_brand', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $brand_terms ) && ! empty( $brand_terms ) ) {
            $brand = $brand_terms[0];
        }
        // Fallback: use store name
        if ( ! $brand ) {
            $brand = apply_filters( 'servertrack_default_brand', get_bloginfo( 'name' ), $product );
        }

        // content_type: 'product_group' for variable products (Meta Dynamic Ads)
        $content_type = $product->is_type( 'variable' ) ? 'product_group' : 'product';

        $data = [
            'id'           => $id,
            'name'         => $product->get_name(),
            'price'        => $price,
            'category'     => $category,
            'brand'        => $brand,
            'content_type' => $content_type,
        ];

        return apply_filters( 'servertrack_product_catalog_data', $data, $product );
    }

    /**
     * Build catalog data from an order.
     */
    public static function from_order( WC_Order $order ): array {
        $custom_data = [
            'currency' => $order->get_currency(),
            'value'    => (float) $order->get_total(),
            'order_id' => $order->get_id(),
        ];
        return self::enrich_purchase( $custom_data, $order );
    }

    /**
     * Build summary catalog data from an order (e.g. for ViewContent).
     */
    public static function from_order_summary( WC_Order $order ): array {
        $custom_data = [
            'currency' => $order->get_currency(),
            'value'    => (float) $order->get_total(),
            'order_id' => $order->get_id(),
        ];
        return self::enrich_purchase( $custom_data, $order );
    }

    /**
     * Build catalog data from the current WooCommerce cart.
     */
    public static function from_cart(): array {
        $custom_data = [
            'currency' => get_woocommerce_currency(),
            'value'    => (float) WC()->cart->get_total( 'edit' ),
            'contents' => [],
            'content_ids' => [],
            'content_type' => 'product',
        ];
        
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( $product instanceof WC_Product ) {
                $catalog = self::get_product_catalog_data( $product );
                $custom_data['contents'][] = [
                    'id'         => $catalog['id'],
                    'quantity'   => $cart_item['quantity'],
                    'item_price' => $catalog['price']
                ];
                $custom_data['content_ids'][] = $catalog['id'];
            }
        }
        
        $custom_data['content_ids'] = array_unique( $custom_data['content_ids'] );
        return $custom_data;
    }
}
