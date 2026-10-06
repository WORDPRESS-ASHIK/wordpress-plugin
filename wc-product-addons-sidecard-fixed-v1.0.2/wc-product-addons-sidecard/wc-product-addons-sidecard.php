<?php
/**
 * Plugin Name: WooCommerce Product Add-ons & Side Card
 * Plugin URI: https://github.com/woocommerce/wc-product-addons-sidecard
 * Description: Lightweight WooCommerce Product Add-ons plugin with a sleek restaurant side-card interface, live selection counters, maximum limits, and seamless Side Cart integration.
 * Version: 1.0.2
 * Author: Antigravity
 * Author URI: https://antigravity.dev
 * Text Domain: wc-product-addons-sidecard
 * Domain Path: /languages
 * WC requires at least: 5.0.0
 * WC tested up to: 9.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'WC_PASC_VERSION', '1.0.2' );
define( 'WC_PASC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_PASC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Plugin Class
 */
final class WC_Product_Addons_Sidecard {

	/**
	 * Single instance
	 * @var WC_Product_Addons_Sidecard
	 */
	private static $instance = null;

	/**
	 * Admin handler
	 * @var WC_PASC_Admin
	 */
	public $admin;

	/**
	 * Cart & Order handler
	 * @var WC_PASC_Cart
	 */
	public $cart;

	/**
	 * Frontend handler
	 * @var WC_PASC_Frontend
	 */
	public $frontend;

	/**
	 * Main Instance
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include files
	 */
	private function includes() {
		require_once WC_PASC_PLUGIN_DIR . 'includes/class-wc-pasc-admin.php';
		require_once WC_PASC_PLUGIN_DIR . 'includes/class-wc-pasc-cart.php';
		require_once WC_PASC_PLUGIN_DIR . 'includes/class-wc-pasc-frontend.php';
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
	}

	/**
	 * When plugins are loaded
	 */
	public function on_plugins_loaded() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->admin    = new WC_PASC_Admin();
		$this->cart     = new WC_PASC_Cart();
		$this->frontend = new WC_PASC_Frontend();
	}

	/**
	 * Notice if WooCommerce is inactive
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'WooCommerce Product Add-ons & Side Card requires WooCommerce to be installed and active.', 'wc-product-addons-sidecard' ); ?></p>
		</div>
		<?php
	}
}

/**
 * Global accessor function
 */
function wc_pasc() {
	return WC_Product_Addons_Sidecard::instance();
}

/**
 * Universal helper to parse Box / Pack presets from legacy raw text
 */
function wc_pasc_parse_box_presets( $raw_text ) {
	$presets = array();
	if ( empty( $raw_text ) ) {
		return $presets;
	}

	$lines = explode( "\n", str_replace( "\r", '', $raw_text ) );
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		if ( strpos( $line, '|' ) !== false ) {
			$parts = explode( '|', $line, 2 );
			$label = trim( $parts[0] );
			$mult  = max( 1, intval( trim( $parts[1] ) ) );
		} elseif ( is_numeric( $line ) ) {
			$mult  = max( 1, intval( $line ) );
			$label = $line;
		} else {
			$label = $line;
			if ( preg_match( '/\b(\d+)\b/', $line, $matches ) ) {
				$mult = max( 1, intval( $matches[1] ) );
			} else {
				$mult = 1;
			}
		}

		$presets[] = array(
			'raw'        => $line,
			'label'      => $label,
			'multiplier' => $mult,
		);
	}

	return $presets;
}

/**
 * Retrieve all configured dynamic pack sizes for a product
 */
function wc_pasc_get_product_pack_sizes( $product_id ) {
	$pack_qty_enabled = get_post_meta( $product_id, '_wc_pasc_box_qty_enabled', true );
	$raw_packs        = get_post_meta( $product_id, '_wc_pasc_pack_sizes', true );
	$legacy_options   = get_post_meta( $product_id, '_wc_pasc_box_options', true );

	// If not found on variation, check parent product
	if ( empty( $pack_qty_enabled ) ) {
		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 ) {
			$pack_qty_enabled = get_post_meta( $parent_id, '_wc_pasc_box_qty_enabled', true );
			$raw_packs        = get_post_meta( $parent_id, '_wc_pasc_pack_sizes', true );
			$legacy_options   = get_post_meta( $parent_id, '_wc_pasc_box_options', true );
		}
	}

	if ( 'yes' !== $pack_qty_enabled ) {
		return array();
	}

	$packs = array();

	// 1. Structured pack sizes from the dynamic repeater
	if ( ! empty( $raw_packs ) && is_array( $raw_packs ) ) {
		foreach ( $raw_packs as $idx => $item ) {
			$name   = isset( $item['name'] ) ? trim( $item['name'] ) : '';
			$pieces = isset( $item['pieces'] ) && '' !== $item['pieces'] ? max( 1, intval( $item['pieces'] ) ) : 1;
			$price  = isset( $item['price'] ) && '' !== $item['price'] ? floatval( $item['price'] ) : 0.00;
			$is_def = ! empty( $item['default'] ) ? 1 : 0;

			if ( empty( $name ) && $pieces <= 0 ) {
				continue;
			}

			// Build human-friendly label (e.g. "Box of 9 pc" or "9 pc")
			if ( ! empty( $name ) && $pieces > 0 ) {
				if ( preg_match( '/\b' . $pieces . '\b/i', $name ) ) {
					$label = $name;
				} else {
					$label = sprintf( '%s %d pc', $name, $pieces );
				}
			} elseif ( ! empty( $name ) ) {
				$label = $name;
			} else {
				$label = sprintf( '%d pc', $pieces );
			}

			$packs[] = array(
				'id'         => 'pack_' . $idx,
				'name'       => $name,
				'pieces'     => $pieces,
				'price'      => $price,
				'label'      => $label,
				'default'    => $is_def,
				'raw'        => (string) $idx,
				'multiplier' => max( 1, $pieces ),
			);
		}
	}

	// 2. Fallback to legacy presets if dynamic pack array is empty
	if ( empty( $packs ) && ! empty( $legacy_options ) ) {
		$legacy_presets = wc_pasc_parse_box_presets( $legacy_options );
		$base_price     = floatval( get_post_meta( $product_id, '_price', true ) );
		if ( $base_price <= 0 ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$base_price = floatval( $product->get_regular_price() );
			}
		}

		foreach ( $legacy_presets as $l_idx => $lp ) {
			$packs[] = array(
				'id'         => 'legacy_' . $l_idx,
				'name'       => $lp['label'],
				'pieces'     => $lp['multiplier'],
				'price'      => $base_price * $lp['multiplier'],
				'label'      => $lp['label'],
				'default'    => ( 0 === $l_idx ) ? 1 : 0,
				'raw'        => $lp['raw'],
				'multiplier' => $lp['multiplier'],
			);
		}
	}

	return $packs;
}

/**
 * Helper to build structured box/pack data from posted value or raw string
 */
function wc_pasc_build_box_data( $product_id, $posted_box = '' ) {
	$packs = wc_pasc_get_product_pack_sizes( $product_id );
	if ( empty( $packs ) ) {
		return null;
	}

	$posted_box_str = trim( (string) $posted_box );

	if ( '' !== $posted_box_str ) {
		foreach ( $packs as $pack ) {
			if (
				(string) $pack['raw'] === $posted_box_str ||
				(string) $pack['id'] === $posted_box_str ||
				(string) $pack['label'] === $posted_box_str ||
				(string) $pack['name'] === $posted_box_str ||
				(string) $pack['pieces'] === $posted_box_str ||
				( isset( $pack['multiplier'] ) && (string) $pack['multiplier'] === $posted_box_str )
			) {
				return $pack;
			}
		}
	}

	// Default to marked default pack
	foreach ( $packs as $pack ) {
		if ( ! empty( $pack['default'] ) ) {
			return $pack;
		}
	}

	// Fallback to first configured pack
	return reset( $packs );
}

/**
 * Helper to check if a product has customization (Pack Sizes and/or Flavours) enabled
 */
function wc_pasc_product_has_customization( $product_id ) {
	if ( ! $product_id ) {
		return false;
	}

	$enabled         = get_post_meta( $product_id, '_wc_pasc_enabled', true );
	$box_qty_enabled = get_post_meta( $product_id, '_wc_pasc_box_qty_enabled', true );
	$addons          = get_post_meta( $product_id, '_wc_pasc_data', true );
	$pack_sizes      = wc_pasc_get_product_pack_sizes( $product_id );

	// If not found on variation, check parent product
	if ( empty( $enabled ) && empty( $box_qty_enabled ) ) {
		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 ) {
			$enabled         = get_post_meta( $parent_id, '_wc_pasc_enabled', true );
			$box_qty_enabled = get_post_meta( $parent_id, '_wc_pasc_box_qty_enabled', true );
			$addons          = get_post_meta( $parent_id, '_wc_pasc_data', true );
			$pack_sizes      = wc_pasc_get_product_pack_sizes( $parent_id );
		}
	}

	$has_addons = ( 'yes' === $enabled && ! empty( $addons ) && is_array( $addons ) );
	$has_boxes  = ( 'yes' === $box_qty_enabled && ! empty( $pack_sizes ) && is_array( $pack_sizes ) );

	return ( $has_addons || $has_boxes );
}

/**
 * Helper to retrieve all product IDs (including variations) that have customization enabled
 */
function wc_pasc_get_all_customized_product_ids() {
	global $wpdb;

	$results = $wpdb->get_col( "
		SELECT DISTINCT post_id 
		FROM {$wpdb->postmeta} 
		WHERE ( meta_key = '_wc_pasc_enabled' AND meta_value = 'yes' ) 
		   OR ( meta_key = '_wc_pasc_box_qty_enabled' AND meta_value = 'yes' )
	" );

	$custom_ids = array();
	if ( ! empty( $results ) ) {
		foreach ( $results as $p_id ) {
			$p_id = intval( $p_id );
			if ( $p_id > 0 && wc_pasc_product_has_customization( $p_id ) ) {
				$custom_ids[] = $p_id;

				// If it's a variable product, also include variation IDs
				$variations = get_posts( array(
					'post_parent' => $p_id,
					'post_type'   => 'product_variation',
					'numberposts' => -1,
					'fields'      => 'ids',
				) );
				if ( ! empty( $variations ) ) {
					foreach ( $variations as $v_id ) {
						$custom_ids[] = intval( $v_id );
					}
				}
			}
		}
	}

	return array_values( array_unique( $custom_ids ) );
}

/**
 * Helper: Default plugin styling colors
 */
function wc_pasc_get_default_styles() {
	return array(
		'primary_color'            => '#10b981',
		'btn_bg_color'             => '#10b981',
		'btn_text_color'           => '#ffffff',
		'checkbox_border_color'    => '#cbd5e1',
		'checkbox_checked_color'   => '#10b981',
		'checkbox_checkmark_color' => '#ffffff',
		'flavour_selected_bg'      => '#ecfdf5',
		'flavour_selected_text'    => '#0f172a',
		'pack_selected_border'     => '#10b981',
		'pack_selected_bg'         => '#ecfdf5',
		'pack_selected_text'       => '#0f172a',
		'pack_normal_border'       => '#e2e8f0',
		'close_btn_color'          => '#334155',
		'popup_bg_color'           => '#ffffff',
		'popup_overlay_color'      => '#0f172a',
		'qty_btn_bg'               => '#ffffff',
		'qty_btn_text'             => '#0f172a',
		'qty_input_text'           => '#0f172a',
		'error_color'              => '#ef4444',
	);
}

/**
 * Helper: Retrieve current plugin styling colors merged with defaults
 */
function wc_pasc_get_styles() {
	$defaults = wc_pasc_get_default_styles();
	$saved    = get_option( 'wc_pasc_styles', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, $defaults );
}

/**
 * Helper: Sanitize color strings (HEX or RGBA)
 */
function wc_pasc_sanitize_color( $color ) {
	$color = trim( (string) $color );
	if ( empty( $color ) ) {
		return '';
	}
	if ( preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ) {
		return sanitize_hex_color( $color );
	}
	if ( preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d\.]+\s*)?\)$/i', $color ) ) {
		return $color;
	}
	return sanitize_text_field( $color );
}

/**
 * Helper: Generate dynamic CSS variables from saved settings
 */
function wc_pasc_generate_dynamic_css() {
	$styles = wc_pasc_get_styles();

	// Convert overlay color if hex to rgba backdrop with 0.72 alpha
	$overlay = $styles['popup_overlay_color'];
	if ( preg_match( '/^#([A-Fa-f0-9]{6})$/', $overlay ) ) {
		$r = hexdec( substr( $overlay, 1, 2 ) );
		$g = hexdec( substr( $overlay, 3, 2 ) );
		$b = hexdec( substr( $overlay, 5, 2 ) );
		$overlay_css = sprintf( 'rgba(%d, %d, %d, 0.72)', $r, $g, $b );
	} elseif ( preg_match( '/^#([A-Fa-f0-9]{3})$/', $overlay ) ) {
		$r = hexdec( str_repeat( substr( $overlay, 1, 1 ), 2 ) );
		$g = hexdec( str_repeat( substr( $overlay, 2, 1 ), 2 ) );
		$b = hexdec( str_repeat( substr( $overlay, 3, 1 ), 2 ) );
		$overlay_css = sprintf( 'rgba(%d, %d, %d, 0.72)', $r, $g, $b );
	} else {
		$overlay_css = $overlay;
	}

	$css = "
		:root {
			--wc-pasc-primary: " . esc_attr( $styles['primary_color'] ) . ";
			--wc-pasc-btn-bg: " . esc_attr( $styles['btn_bg_color'] ) . ";
			--wc-pasc-btn-text: " . esc_attr( $styles['btn_text_color'] ) . ";
			--wc-pasc-checkbox-border: " . esc_attr( $styles['checkbox_border_color'] ) . ";
			--wc-pasc-checkbox-checked: " . esc_attr( $styles['checkbox_checked_color'] ) . ";
			--wc-pasc-checkbox-checkmark: " . esc_attr( $styles['checkbox_checkmark_color'] ) . ";
			--wc-pasc-flavour-selected-bg: " . esc_attr( $styles['flavour_selected_bg'] ) . ";
			--wc-pasc-flavour-selected-text: " . esc_attr( $styles['flavour_selected_text'] ) . ";
			--wc-pasc-pack-selected-border: " . esc_attr( $styles['pack_selected_border'] ) . ";
			--wc-pasc-pack-selected-bg: " . esc_attr( $styles['pack_selected_bg'] ) . ";
			--wc-pasc-pack-selected-text: " . esc_attr( $styles['pack_selected_text'] ) . ";
			--wc-pasc-pack-normal-border: " . esc_attr( $styles['pack_normal_border'] ) . ";
			--wc-pasc-close-btn: " . esc_attr( $styles['close_btn_color'] ) . ";
			--wc-pasc-popup-bg: " . esc_attr( $styles['popup_bg_color'] ) . ";
			--wc-pasc-popup-overlay: " . esc_attr( $overlay_css ) . ";
			--wc-pasc-qty-btn-bg: " . esc_attr( $styles['qty_btn_bg'] ) . ";
			--wc-pasc-qty-btn-text: " . esc_attr( $styles['qty_btn_text'] ) . ";
			--wc-pasc-qty-input-text: " . esc_attr( $styles['qty_input_text'] ) . ";
			--wc-pasc-error: " . esc_attr( $styles['error_color'] ) . ";
		}
	";

	return $css;
}

// Kickoff plugin
wc_pasc();

