<?php
/**
 * Cart, Checkout, Order and Side Cart Handler for WooCommerce Product Add-ons & Box Presets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PASC_Cart {

	/**
	 * Track rendered side cart items to prevent duplicate hooks
	 * @var array
	 */
	private $rendered_sidecart_items = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		// Validate Add-on selections on Add-to-cart
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );

		// Attach Add-on & Box data to cart item
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 4 );

		// Adjust Cart Item Price dynamically
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'calculate_cart_item_prices' ), 20, 1 );

		// Display Add-on & Box data in Cart, Checkout, Emails & Order details (read-only information)
		add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );

		// Save Add-on & Box metadata to Order Line Item
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_item_meta' ), 10, 4 );

		// Treat products with pack sizes as sold individually (removes default +/- stepper)
		add_filter( 'woocommerce_is_sold_individually', array( $this, 'filter_is_sold_individually' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_sold_individually', array( $this, 'filter_cart_item_sold_individually' ), 20, 3 );
		add_filter( 'woocommerce_add_to_cart_quantity', array( $this, 'filter_add_to_cart_quantity' ), 10, 2 );
	}

	/**
	 * Mark products with pack sizes enabled as sold individually
	 */
	public function filter_is_sold_individually( $return, $product ) {
		if ( ! $product ) {
			return $return;
		}
		$product_id = $product->get_id();
		$box_qty_enabled = get_post_meta( $product_id, '_wc_pasc_box_qty_enabled', true );
		if ( empty( $box_qty_enabled ) && $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			$box_qty_enabled = get_post_meta( $product->get_parent_id(), '_wc_pasc_box_qty_enabled', true );
		}
		if ( 'yes' === $box_qty_enabled ) {
			return true;
		}
		return $return;
	}

	/**
	 * Filter cart item sold individually for pack size items
	 */
	public function filter_cart_item_sold_individually( $return, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['wc_pasc_box'] ) ) {
			return true;
		}
		return $return;
	}

	/**
	 * Ensure pack size items are added with quantity 1
	 */
	public function filter_add_to_cart_quantity( $quantity, $product_id ) {
		$box_qty_enabled = get_post_meta( $product_id, '_wc_pasc_box_qty_enabled', true );
		if ( 'yes' === $box_qty_enabled ) {
			return 1;
		}
		return $quantity;
	}

	/**
	 * Parse posted addons and compute structured data & total price
	 */
	public function parse_addons( $product_id, $posted_addons ) {
		$addons_data = get_post_meta( $product_id, '_wc_pasc_data', true );
		if ( empty( $addons_data ) || ! is_array( $addons_data ) ) {
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id > 0 ) {
				$addons_data = get_post_meta( $parent_id, '_wc_pasc_data', true );
			}
		}

		if ( empty( $addons_data ) || ! is_array( $addons_data ) ) {
			return array(
				'selected' => array(),
				'total'    => 0.00,
				'raw'      => array(),
			);
		}

		$selected_addons = array();
		$addons_total    = 0.00;
		$clean_raw       = array();

		foreach ( $addons_data as $group_idx => $group ) {
			if ( ! isset( $posted_addons[ $group_idx ] ) ) {
				continue;
			}

			$group_title = ! empty( $group['title'] ) ? $group['title'] : __( 'Options', 'wc-product-addons-sidecard' );
			$options_map = array();

			if ( ! empty( $group['options'] ) && is_array( $group['options'] ) ) {
				foreach ( $group['options'] as $opt_idx => $opt ) {
					$options_map[ $opt_idx ] = $opt;
				}
			}

			$raw_selections = $posted_addons[ $group_idx ];
			if ( ! is_array( $raw_selections ) ) {
				$raw_selections = array( $raw_selections );
			}

			$group_selected_items = array();
			$group_clean_indices  = array();

			foreach ( $raw_selections as $sel_idx ) {
				$sel_idx = intval( $sel_idx );
				if ( isset( $options_map[ $sel_idx ] ) ) {
					$opt           = $options_map[ $sel_idx ];
					$price         = isset( $opt['price'] ) ? floatval( $opt['price'] ) : 0.00;
					$addons_total += $price;

					$group_selected_items[] = array(
						'name'        => $opt['name'],
						'price'       => $price,
						'description' => isset( $opt['description'] ) ? $opt['description'] : '',
						'index'       => $sel_idx,
					);
					$group_clean_indices[] = $sel_idx;
				}
			}

			if ( ! empty( $group_selected_items ) ) {
				$selected_addons[] = array(
					'group_idx'   => $group_idx,
					'group_title' => $group_title,
					'items'       => $group_selected_items,
				);
				$clean_raw[ $group_idx ] = $group_clean_indices;
			}
		}

		return array(
			'selected' => $selected_addons,
			'total'    => $addons_total,
			'raw'      => $clean_raw,
		);
	}

	/**
	 * Validate add to cart server-side
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		$enabled = get_post_meta( $product_id, '_wc_pasc_enabled', true );
		if ( 'yes' !== $enabled ) {
			return $passed;
		}

		$addons_data = get_post_meta( $product_id, '_wc_pasc_data', true );
		if ( empty( $addons_data ) || ! is_array( $addons_data ) ) {
			return $passed;
		}

		$posted_addons = isset( $_POST['wc_pasc_addons'] ) ? (array) $_POST['wc_pasc_addons'] : array();

		foreach ( $addons_data as $group_idx => $group ) {
			$group_title = ! empty( $group['title'] ) ? $group['title'] : sprintf( __( 'Group #%d', 'wc-product-addons-sidecard' ), $group_idx + 1 );
			$min_limit   = isset( $group['min_limit'] ) ? intval( $group['min_limit'] ) : 0;
			$max_limit   = isset( $group['max_limit'] ) ? intval( $group['max_limit'] ) : ( 'radio' === $group['type'] ? 1 : 99 );
			$is_required = ! empty( $group['required'] ) || $min_limit > 0;

			$selected_count = 0;
			if ( isset( $posted_addons[ $group_idx ] ) ) {
				$selected_count = is_array( $posted_addons[ $group_idx ] ) ? count( $posted_addons[ $group_idx ] ) : 1;
			}

			// Required / Min limit validation
			if ( $is_required && $selected_count < max( 1, $min_limit ) ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: Group title, 2: minimum selection required */
						__( 'Please select at least %2$d option(s) for "%1$s".', 'wc-product-addons-sidecard' ),
						esc_html( $group_title ),
						max( 1, $min_limit )
					),
					'error'
				);
				return false;
			}

			// Max limit validation
			if ( $max_limit > 0 && $selected_count > $max_limit ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: Group title, 2: maximum selection limit */
						__( 'You cannot select more than %2$d option(s) for "%1$s".', 'wc-product-addons-sidecard' ),
						esc_html( $group_title ),
						$max_limit
					),
					'error'
				);
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Attach add-ons & box quantity to cart item data
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id, $quantity ) {
		$check_id        = ( ! empty( $variation_id ) ) ? $variation_id : $product_id;
		$parent_id       = ( ! empty( $variation_id ) ) ? $product_id : wp_get_post_parent_id( $check_id );

		$enabled         = get_post_meta( $check_id, '_wc_pasc_enabled', true );
		$box_qty_enabled = get_post_meta( $check_id, '_wc_pasc_box_qty_enabled', true );

		if ( empty( $enabled ) && $parent_id > 0 ) {
			$enabled = get_post_meta( $parent_id, '_wc_pasc_enabled', true );
		}
		if ( empty( $box_qty_enabled ) && $parent_id > 0 ) {
			$box_qty_enabled = get_post_meta( $parent_id, '_wc_pasc_box_qty_enabled', true );
		}

		if ( 'yes' !== $enabled && 'yes' !== $box_qty_enabled ) {
			return $cart_item_data;
		}

		$target_id     = ( $parent_id > 0 && ! get_post_meta( $check_id, '_wc_pasc_data', true ) ) ? $parent_id : $check_id;
		$posted_addons = isset( $_POST['wc_pasc_addons'] ) ? (array) $_POST['wc_pasc_addons'] : array();
		$posted_box    = isset( $_POST['wc_pasc_box_option'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_pasc_box_option'] ) ) : '';

		// If no addons posted directly, check if product has default checked options
		$addons_data = get_post_meta( $target_id, '_wc_pasc_data', true );
		if ( empty( $posted_addons ) && ! empty( $addons_data ) && is_array( $addons_data ) ) {
			foreach ( $addons_data as $g_idx => $grp ) {
				if ( ! empty( $grp['options'] ) && is_array( $grp['options'] ) ) {
					foreach ( $grp['options'] as $o_idx => $opt ) {
						if ( ! empty( $opt['default'] ) ) {
							if ( 'radio' === $grp['type'] ) {
								$posted_addons[ $g_idx ] = $o_idx;
							} else {
								$posted_addons[ $g_idx ][] = $o_idx;
							}
						}
					}
				}
			}
		}

		$parsed   = $this->parse_addons( $target_id, $posted_addons );
		$box_data = wc_pasc_build_box_data( $target_id, $posted_box );

		if ( ! empty( $parsed['selected'] ) || ! empty( $box_data ) ) {
			$cart_item_data['wc_pasc_addons']       = $parsed['selected'];
			$cart_item_data['wc_pasc_addons_raw']   = $parsed['raw'];
			$cart_item_data['wc_pasc_addons_total'] = $parsed['total'];
			$cart_item_data['wc_pasc_box']          = $box_data;
			$cart_item_data['wc_pasc_unique_key']   = md5( wp_json_encode( array( $parsed['selected'], $box_data, microtime() ) ) );
		}

		return $cart_item_data;
	}

	/**
	 * Adjust cart item prices with dynamic pack size prices & add-on extras
	 */
	public function calculate_cart_item_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['wc_pasc_addons_total'] ) || isset( $cart_item['wc_pasc_box'] ) ) {
				$product = $cart_item['data'];
				$prod_id = $product->get_id();

				$pack_data = isset( $cart_item['wc_pasc_box'] ) ? $cart_item['wc_pasc_box'] : null;

				// Calculate base price for the selected pack
				if ( ! empty( $pack_data ) && isset( $pack_data['price'] ) && floatval( $pack_data['price'] ) > 0 ) {
					// Use individual explicit price configured for this pack size
					$item_base_price = floatval( $pack_data['price'] );
				} elseif ( ! empty( $pack_data['multiplier'] ) && $pack_data['multiplier'] > 1 ) {
					// Multiplier calculation fallback
					$orig_price = floatval( get_post_meta( $prod_id, '_price', true ) );
					if ( $orig_price <= 0 ) {
						$orig_price = floatval( $product->get_regular_price() );
					}
					if ( $orig_price <= 0 && $product->is_type( 'variation' ) ) {
						$orig_price = floatval( get_post_meta( $product->get_parent_id(), '_price', true ) );
					}
					$item_base_price = $orig_price * intval( $pack_data['multiplier'] );
				} else {
					$orig_price = floatval( get_post_meta( $prod_id, '_price', true ) );
					if ( $orig_price <= 0 ) {
						$orig_price = floatval( $product->get_regular_price() );
					}
					if ( $orig_price <= 0 && $product->is_type( 'variation' ) ) {
						$orig_price = floatval( get_post_meta( $product->get_parent_id(), '_price', true ) );
					}
					$item_base_price = $orig_price;
				}

				$addons_extra = isset( $cart_item['wc_pasc_addons_total'] ) ? floatval( $cart_item['wc_pasc_addons_total'] ) : 0.00;

				$new_price = $item_base_price + $addons_extra;
				$product->set_price( $new_price );
			}
		}
	}

	/**
	 * Render item metadata in WooCommerce Cart & Checkout
	 */
	public function get_item_data( $item_data, $cart_item ) {
		// Pack Size
		if ( ! empty( $cart_item['wc_pasc_box']['label'] ) ) {
			$pack_price_html = '';
			if ( ! empty( $cart_item['wc_pasc_box']['price'] ) && $cart_item['wc_pasc_box']['price'] > 0 ) {
				$pack_price_html = ' (' . wc_price( $cart_item['wc_pasc_box']['price'] ) . ')';
			}
			$item_data[] = array(
				'key'     => __( 'Pack Size', 'wc-product-addons-sidecard' ),
				'value'   => $cart_item['wc_pasc_box']['label'] . ( ! empty( $cart_item['wc_pasc_box']['price'] ) ? ' (' . wc_price( $cart_item['wc_pasc_box']['price'] ) . ')' : '' ),
				'display' => '<span class="wc-pasc-cart-box-badge">' . esc_html( $cart_item['wc_pasc_box']['label'] ) . ( $pack_price_html ? ' ' . $pack_price_html : '' ) . '</span>',
			);
		}

		// Selected Add-ons (for Cart page, Checkout & Emails)
		if ( ! empty( $cart_item['wc_pasc_addons'] ) && is_array( $cart_item['wc_pasc_addons'] ) ) {
			foreach ( $cart_item['wc_pasc_addons'] as $group ) {
				$group_name = $group['group_title'];
				$item_names = array();

				foreach ( $group['items'] as $item ) {
					$price_str = '';
					if ( ! empty( $item['price'] ) && $item['price'] > 0 ) {
						$price_str = ' (+' . wc_price( $item['price'] ) . ')';
					}
					$item_names[] = esc_html( $item['name'] ) . $price_str;
				}

				if ( ! empty( $item_names ) ) {
					$item_data[] = array(
						'key'     => $group_name,
						'value'   => implode( ', ', $item_names ),
						'display' => '<div class="wc-pasc-cart-addon-list">' . implode( '<br>', $item_names ) . '</div>',
					);
				}
			}
		}

		return $item_data;
	}

	/**
	 * Save Add-on selections permanently to WooCommerce Order Line Item
	 */
	public function save_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
		// Pack Size
		if ( ! empty( $values['wc_pasc_box']['label'] ) ) {
			$price_str = '';
			if ( ! empty( $values['wc_pasc_box']['price'] ) && $values['wc_pasc_box']['price'] > 0 ) {
				$price_str = ' (' . wc_price( $values['wc_pasc_box']['price'] ) . ')';
			}
			$item->add_meta_data(
				__( 'Pack Size', 'wc-product-addons-sidecard' ),
				$values['wc_pasc_box']['label'] . $price_str,
				true
			);
		}

		// Add-ons
		if ( ! empty( $values['wc_pasc_addons'] ) && is_array( $values['wc_pasc_addons'] ) ) {
			foreach ( $values['wc_pasc_addons'] as $group ) {
				$group_name = $group['group_title'];
				$item_names = array();

				foreach ( $group['items'] as $opt ) {
					$price_str = '';
					if ( ! empty( $opt['price'] ) && $opt['price'] > 0 ) {
						$price_str = ' (+' . wc_price( $opt['price'] ) . ')';
					}
					$item_names[] = $opt['name'] . $price_str;
				}

				if ( ! empty( $item_names ) ) {
					$item->add_meta_data(
						$group_name,
						implode( ', ', $item_names ),
						true
					);
				}
			}
		}
	}
}
