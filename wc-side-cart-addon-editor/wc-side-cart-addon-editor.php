<?php
/**
 * Plugin Name: WC Side Cart Add-On Editor
 * Description: Lets customers edit WooCommerce Product Add-Ons directly inside Xootix Side Cart for WooCommerce and provides a Select Option popup modal for variable products.
 * Version: 1.2.0
 * Author: Custom
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wc-side-cart-addon-editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WCSCAE_Plugin {
    const VERSION = '1.2.0';
    const NONCE_ACTION = 'wcscae_nonce';

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'append_edit_control' ), 999, 2 );

        // Variable product "Select Option" text and loop button hooks
        add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'change_variable_add_to_cart_text' ), 99, 2 );
        add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'filter_loop_add_to_cart_link' ), 99, 3 );

        // AJAX for existing cart item add-on editing
        add_action( 'wp_ajax_wcscae_get_form', array( __CLASS__, 'ajax_get_form' ) );
        add_action( 'wp_ajax_nopriv_wcscae_get_form', array( __CLASS__, 'ajax_get_form' ) );
        add_action( 'wp_ajax_wcscae_update_addons', array( __CLASS__, 'ajax_update_addons' ) );
        add_action( 'wp_ajax_nopriv_wcscae_update_addons', array( __CLASS__, 'ajax_update_addons' ) );

        // AJAX for variable product quick-selection & add to cart
        add_action( 'wp_ajax_wcscae_get_variation_form', array( __CLASS__, 'ajax_get_variation_form' ) );
        add_action( 'wp_ajax_nopriv_wcscae_get_variation_form', array( __CLASS__, 'ajax_get_variation_form' ) );
        add_action( 'wp_ajax_wcscae_add_variation_to_cart', array( __CLASS__, 'ajax_add_variation_to_cart' ) );
        add_action( 'wp_ajax_nopriv_wcscae_add_variation_to_cart', array( __CLASS__, 'ajax_add_variation_to_cart' ) );

        // High-performance WooCommerce AJAX endpoints (faster than admin-ajax)
        add_action( 'wc_ajax_wcscae_get_variation_form', array( __CLASS__, 'ajax_get_variation_form' ) );
        add_action( 'wc_ajax_wcscae_add_variation_to_cart', array( __CLASS__, 'ajax_add_variation_to_cart' ) );

        // Cache invalidation
        add_action( 'woocommerce_update_product', array( __CLASS__, 'clear_product_cache' ) );
        add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'clear_product_cache' ) );

        add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
    }

    public static function clear_product_cache( $product_id ) {
        if ( ! $product_id ) return;
        delete_transient( 'wcscae_vform_' . $product_id );
        $parent_id = wp_get_post_parent_id( $product_id );
        if ( $parent_id ) {
            delete_transient( 'wcscae_vform_' . $parent_id );
        }
    }

    public static function dependency_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-error"><p><strong>WC Side Cart Add-On Editor:</strong> WooCommerce must be active.</p></div>';
        }
    }

    private static function addons_available() {
        return class_exists( 'WC_Product_Addons_Helper' ) || function_exists( 'get_product_addons' );
    }

    public static function change_variable_add_to_cart_text( $text, $product ) {
        if ( $product && $product->is_type( 'variable' ) ) {
            return __( 'Select Option', 'wc-side-cart-addon-editor' );
        }
        return $text;
    }

    public static function filter_loop_add_to_cart_link( $link, $product, $args = array() ) {
        if ( $product && $product->is_type( 'variable' ) ) {
            $product_id  = $product->get_id();
            $button_text = __( 'Select Option', 'wc-side-cart-addon-editor' );

            // Strip conflicting AJAX/Cart trigger classes so Xootix/WooCommerce don't treat it as direct add-to-cart
            $link = preg_replace( '/\b(ajax_add_to_cart|add_to_cart_button|xoo-wsc-cart-trigger)\b/', '', $link );

            if ( strpos( $link, 'wcscae-select-options' ) === false ) {
                if ( strpos( $link, 'class="' ) !== false ) {
                    $link = str_replace( 'class="', 'class="wcscae-select-options ', $link );
                } else {
                    $link = str_replace( '<a ', '<a class="wcscae-select-options" ', $link );
                }
            }

            if ( strpos( $link, 'data-product_id=' ) === false ) {
                $link = str_replace( '<a ', '<a data-product_id="' . esc_attr( $product_id ) . '" ', $link );
            }

            if ( strpos( $link, 'data-product_type=' ) === false ) {
                $link = str_replace( '<a ', '<a data-product_type="variable" ', $link );
            }

            $link = preg_replace( '/>([^<]*?)<\/a>/i', '>' . esc_html( $button_text ) . '</a>', $link );
        }
        return $link;
    }

    public static function enqueue_assets() {
        if ( is_admin() || ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        wp_enqueue_style(
            'wcscae',
            plugins_url( 'assets/wcscae.css', __FILE__ ),
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'wcscae',
            plugins_url( 'assets/wcscae.js', __FILE__ ),
            array( 'jquery' ),
            self::VERSION,
            true
        );

        $wc_ajax_url = class_exists( 'WC_AJAX' )
            ? WC_AJAX::get_endpoint( '%%endpoint%%' )
            : add_query_arg( 'wc-ajax', '%%endpoint%%', home_url( '/' ) );

        wp_localize_script( 'wcscae', 'WCSCAE', array(
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'wcAjaxUrl'       => $wc_ajax_url,
            'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
            'i18n'            => array(
                'title'           => __( 'Edit add-ons', 'wc-side-cart-addon-editor' ),
                'addTitle'        => __( 'Add add-ons', 'wc-side-cart-addon-editor' ),
                'selectOption'    => __( 'Select Option', 'wc-side-cart-addon-editor' ),
                'loading'         => __( 'Loading options…', 'wc-side-cart-addon-editor' ),
                'saving'          => __( 'Updating…', 'wc-side-cart-addon-editor' ),
                'adding'          => __( 'Adding to cart…', 'wc-side-cart-addon-editor' ),
                'save'            => __( 'Update add-ons', 'wc-side-cart-addon-editor' ),
                'addToCart'       => __( 'Add to cart', 'wc-side-cart-addon-editor' ),
                'close'           => __( 'Close', 'wc-side-cart-addon-editor' ),
                'error'           => __( 'Could not update add-ons. Please try again.', 'wc-side-cart-addon-editor' ),
                'atcError'        => __( 'Could not add to cart. Please try again.', 'wc-side-cart-addon-editor' ),
                'selectVariation' => __( 'Please select all product options before adding to cart.', 'wc-side-cart-addon-editor' ),
                'outOfStock'      => __( 'Sorry, this option is out of stock.', 'wc-side-cart-addon-editor' ),
                'chooseOption'    => __( 'Choose an option', 'wc-side-cart-addon-editor' ),
            ),
        ) );
    }

    private static function get_cart_key_from_item( $cart_item ) {
        if ( ! WC()->cart ) {
            return '';
        }

        foreach ( WC()->cart->get_cart() as $key => $candidate ) {
            if ( $candidate === $cart_item || $candidate == $cart_item ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
                return $key;
            }
        }

        return '';
    }

    private static function get_product_addons( $product_id ) {
        if ( class_exists( 'WC_Product_Addons_Helper' ) && is_callable( array( 'WC_Product_Addons_Helper', 'get_product_addons' ) ) ) {
            return (array) WC_Product_Addons_Helper::get_product_addons( $product_id );
        }
        if ( function_exists( 'get_product_addons' ) ) {
            return (array) get_product_addons( $product_id );
        }
        return array();
    }

    public static function append_edit_control( $item_data, $cart_item ) {
        if ( is_admin() || ! self::addons_available() ) {
            return $item_data;
        }

        $product_id = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : absint( $cart_item['product_id'] ?? 0 );
        if ( ! $product_id ) {
            return $item_data;
        }

        $addons = self::get_product_addons( $product_id );
        if ( empty( $addons ) && ! empty( $cart_item['product_id'] ) && $product_id !== (int) $cart_item['product_id'] ) {
            $addons = self::get_product_addons( (int) $cart_item['product_id'] );
        }
        if ( empty( $addons ) ) {
            return $item_data;
        }

        $cart_key = self::get_cart_key_from_item( $cart_item );
        if ( ! $cart_key ) {
            return $item_data;
        }

        $has_selected_addons = ! empty( $cart_item['addons'] ) && is_array( $cart_item['addons'] );
        $button_label       = $has_selected_addons
            ? __( 'Edit add-ons', 'wc-side-cart-addon-editor' )
            : __( '+ Add add-ons', 'wc-side-cart-addon-editor' );
        $mode               = $has_selected_addons ? 'edit' : 'add';

        $item_data[] = array(
            'key'     => '',
            'value'   => '',
            'display' => sprintf(
                '<button type="button" class="wcscae-edit" data-cart-key="%1$s" data-mode="%2$s">%3$s</button>',
                esc_attr( $cart_key ),
                esc_attr( $mode ),
                esc_html( $button_label )
            ),
        );

        return $item_data;
    }

    private static function verify_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! class_exists( 'WooCommerce' ) || ! WC()->cart || ! self::addons_available() ) {
            wp_send_json_error( array( 'message' => 'Required WooCommerce components are unavailable.' ), 400 );
        }
    }

    private static function current_addons_by_name( $cart_item ) {
        $out = array();
        foreach ( (array) ( $cart_item['addons'] ?? array() ) as $row ) {
            $name = isset( $row['name'] ) ? wp_strip_all_tags( $row['name'] ) : '';
            if ( '' === $name ) {
                continue;
            }
            if ( ! isset( $out[ $name ] ) ) {
                $out[ $name ] = array();
            }
            $out[ $name ][] = $row;
        }
        return $out;
    }

    private static function price_suffix( $option ) {
        if ( ! isset( $option['price'] ) || '' === (string) $option['price'] || 0 == $option['price'] ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
            return '';
        }
        $price = (float) $option['price'];
        $type  = $option['price_type'] ?? 'flat_fee';
        if ( 'percentage_based' === $type ) {
            return sprintf( ' (%s%s%%)', $price > 0 ? '+' : '', wc_format_localized_decimal( $price ) );
        }
        return ' (' . ( $price > 0 ? '+' : '' ) . wp_strip_all_tags( wc_price( $price ) ) . ')';
    }

    private static function render_addon( $addon, $current_rows ) {
        $type       = $addon['type'] ?? '';
        $name       = isset( $addon['name'] ) ? wp_strip_all_tags( $addon['name'] ) : '';
        $field_name = $addon['field_name'] ?? ( $addon['field-name'] ?? '' );
        $required   = ! empty( $addon['required'] );
        $input_name = 'addon-' . sanitize_title( $field_name );
        $current_values = array();
        foreach ( $current_rows as $row ) {
            if ( isset( $row['value'] ) ) {
                $current_values[] = html_entity_decode( wp_strip_all_tags( (string) $row['value'] ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
            }
        }

        if ( 'heading' === $type ) {
            echo '<div class="wcscae-heading">' . esc_html( $name ) . '</div>';
            return;
        }

        echo '<div class="wcscae-field wcscae-type-' . esc_attr( sanitize_html_class( $type ) ) . '">';
        if ( $name ) {
            echo '<label class="wcscae-label">' . esc_html( $name ) . ( $required ? ' <span class="required">*</span>' : '' ) . '</label>';
        }

        $options = isset( $addon['options'] ) && is_array( $addon['options'] ) ? $addon['options'] : array();

        if ( in_array( $type, array( 'multiple_choice', 'select' ), true ) ) {
            $display = $addon['display'] ?? 'select';
            if ( in_array( $display, array( 'radiobutton', 'radio' ), true ) ) {
                $i = 0;
                foreach ( $options as $option ) {
                    $i++;
                    $label = (string) ( $option['label'] ?? '' );
                    $value = sanitize_title( $label ) . '-' . $i;
                    $checked = in_array( $label, $current_values, true );
                    printf(
                        '<label class="wcscae-choice"><input type="radio" name="%1$s" value="%2$s" %3$s %4$s> <span>%5$s%6$s</span></label>',
                        esc_attr( $input_name ),
                        esc_attr( $value ),
                        checked( $checked, true, false ),
                        $required ? 'required' : '',
                        esc_html( $label ),
                        esc_html( self::price_suffix( $option ) )
                    );
                }
            } else {
                printf( '<select name="%s" %s>', esc_attr( $input_name ), $required ? 'required' : '' );
                echo '<option value="">' . esc_html( $required ? __( 'Select an option…', 'wc-side-cart-addon-editor' ) : __( 'None', 'wc-side-cart-addon-editor' ) ) . '</option>';
                $i = 0;
                foreach ( $options as $option ) {
                    $i++;
                    $label = (string) ( $option['label'] ?? '' );
                    $value = sanitize_title( $label ) . '-' . $i;
                    $selected = in_array( $label, $current_values, true );
                    printf( '<option value="%s" %s>%s%s</option>', esc_attr( $value ), selected( $selected, true, false ), esc_html( $label ), esc_html( self::price_suffix( $option ) ) );
                }
                echo '</select>';
            }
        } elseif ( 'checkbox' === $type ) {
            $i = 0;
            foreach ( $options as $option ) {
                $i++;
                $label = (string) ( $option['label'] ?? '' );
                $value = sanitize_title( $label ) . '-' . $i;
                $checked = in_array( $label, $current_values, true );
                printf(
                    '<label class="wcscae-choice"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> <span>%4$s%5$s</span></label>',
                    esc_attr( $input_name ),
                    esc_attr( $value ),
                    checked( $checked, true, false ),
                    esc_html( $label ),
                    esc_html( self::price_suffix( $option ) )
                );
            }
        } elseif ( in_array( $type, array( 'custom_text', 'text' ), true ) ) {
            printf( '<input type="text" name="%s" value="%s" %s>', esc_attr( $input_name ), esc_attr( $current_values[0] ?? '' ), $required ? 'required' : '' );
        } elseif ( in_array( $type, array( 'custom_textarea', 'textarea' ), true ) ) {
            printf( '<textarea name="%s" %s>%s</textarea>', esc_attr( $input_name ), $required ? 'required' : '', esc_textarea( $current_values[0] ?? '' ) );
        } elseif ( in_array( $type, array( 'custom_price', 'price', 'input_multiplier' ), true ) ) {
            printf( '<input type="number" step="any" name="%s" value="%s" %s>', esc_attr( $input_name ), esc_attr( $current_values[0] ?? '' ), $required ? 'required' : '' );
        } else {
            echo '<div class="wcscae-unsupported">' . esc_html__( 'This add-on type can only be changed on the product page.', 'wc-side-cart-addon-editor' ) . '</div>';
        }

        if ( ! empty( $addon['description'] ) ) {
            echo '<div class="wcscae-description">' . wp_kses_post( $addon['description'] ) . '</div>';
        }
        echo '</div>';
    }

    private static function get_addons_display_handler() {
        if ( isset( $GLOBALS['Product_Addon_Display'] ) && is_object( $GLOBALS['Product_Addon_Display'] ) && is_callable( array( $GLOBALS['Product_Addon_Display'], 'display' ) ) ) {
            return $GLOBALS['Product_Addon_Display'];
        }

        if ( isset( $GLOBALS['WC_Product_Addons_Display'] ) && is_object( $GLOBALS['WC_Product_Addons_Display'] ) && is_callable( array( $GLOBALS['WC_Product_Addons_Display'], 'display' ) ) ) {
            return $GLOBALS['WC_Product_Addons_Display'];
        }

        return null;
    }

    private static function render_official_addons( $product_id ) {
        $display = self::get_addons_display_handler();
        if ( ! $display ) {
            return '';
        }

        global $product, $post;
        $old_product = $product ?? null;
        $old_post    = $post ?? null;

        $product = wc_get_product( $product_id );
        $post    = get_post( $product_id );

        if ( ! $product ) {
            $product = $old_product;
            $post    = $old_post;
            return '';
        }

        ob_start();
        try {
            // Use the Product Add-Ons extension's own templates. This is important:
            // the extension controls the exact field names and encoded option values
            // expected later by validate_add_cart_item()/add_cart_item_data().
            $display->display( $product_id );
        } catch ( Throwable $e ) {
            ob_end_clean();
            $product = $old_product;
            $post    = $old_post;
            return '';
        }
        $html = ob_get_clean();

        $product = $old_product;
        $post    = $old_post;

        return $html;
    }

    private static function current_addons_for_js( $item ) {
        $rows = array();
        foreach ( (array) ( $item['addons'] ?? array() ) as $addon ) {
            $rows[] = array(
                'name'       => isset( $addon['name'] ) ? wp_strip_all_tags( (string) $addon['name'] ) : '',
                'value'      => isset( $addon['value'] ) ? html_entity_decode( wp_strip_all_tags( (string) $addon['value'] ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) : '',
                'field_name' => isset( $addon['field_name'] ) ? sanitize_text_field( (string) $addon['field_name'] ) : '',
                'field_type' => isset( $addon['field_type'] ) ? sanitize_text_field( (string) $addon['field_type'] ) : '',
            );
        }
        return $rows;
    }

    public static function ajax_get_form() {
        self::verify_ajax();

        $cart_key = wc_clean( wp_unslash( $_POST['cart_key'] ?? '' ) );
        $cart     = WC()->cart->get_cart();
        if ( ! $cart_key || ! isset( $cart[ $cart_key ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Cart item not found.', 'wc-side-cart-addon-editor' ) ), 404 );
        }

        $item       = $cart[ $cart_key ];
        // Product Add-Ons are attached to the parent product. Variable-product add-ons
        // are inherited by variations, so always use product_id here and during update.
        $product_id = absint( $item['product_id'] );
        $addons     = self::get_product_addons( $product_id );

        if ( empty( $addons ) ) {
            wp_send_json_error( array( 'message' => __( 'No add-ons are available for this product.', 'wc-side-cart-addon-editor' ) ), 404 );
        }

        $official_html = self::render_official_addons( $product_id );

        ob_start();
        echo '<form class="wcscae-form" data-cart-key="' . esc_attr( $cart_key ) . '">';

        if ( $official_html ) {
            echo $official_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by WooCommerce Product Add-Ons.
        } else {
            // Compatibility fallback for older Product Add-Ons releases where the
            // display object is not exposed. Keep the original lightweight renderer.
            $current = self::current_addons_by_name( $item );
            foreach ( $addons as $addon ) {
                $name = isset( $addon['name'] ) ? wp_strip_all_tags( $addon['name'] ) : '';
                self::render_addon( $addon, $current[ $name ] ?? array() );
            }
        }

        $has_selected_addons = ! empty( $item['addons'] ) && is_array( $item['addons'] );
        $submit_label = $has_selected_addons
            ? __( 'Update add-ons', 'wc-side-cart-addon-editor' )
            : __( 'Add add-ons', 'wc-side-cart-addon-editor' );

        echo '<div class="wcscae-actions"><button type="button" class="wcscae-cancel">' . esc_html__( 'Cancel', 'wc-side-cart-addon-editor' ) . '</button><button type="submit" class="wcscae-save">' . esc_html( $submit_label ) . '</button></div>';
        echo '</form>';
        $html = ob_get_clean();

        wp_send_json_success( array(
            'html'    => $html,
            'current' => self::current_addons_for_js( $item ),
        ) );
    }

    private static function get_addons_cart_handler() {
        if ( isset( $GLOBALS['Product_Addon_Cart'] ) && is_object( $GLOBALS['Product_Addon_Cart'] ) && is_callable( array( $GLOBALS['Product_Addon_Cart'], 'add_cart_item_data' ) ) ) {
            return $GLOBALS['Product_Addon_Cart'];
        }
        if ( class_exists( 'WC_Product_Addons_Cart' ) ) {
            return new WC_Product_Addons_Cart();
        }
        if ( class_exists( 'Product_Addon_Cart' ) ) {
            return new Product_Addon_Cart();
        }
        return null;
    }

    public static function ajax_update_addons() {
        self::verify_ajax();

        $cart_key = wc_clean( wp_unslash( $_POST['cart_key'] ?? '' ) );
        if ( ! $cart_key || ! isset( WC()->cart->cart_contents[ $cart_key ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Cart item not found.', 'wc-side-cart-addon-editor' ) ), 404 );
        }

        $serialized = isset( $_POST['fields'] ) ? (string) wp_unslash( $_POST['fields'] ) : '';
        parse_str( $serialized, $fields );
        if ( ! is_array( $fields ) ) {
            $fields = array();
        }
        $fields = wc_clean( $fields );

        $item       = WC()->cart->cart_contents[ $cart_key ];
        $product_id = absint( $item['product_id'] );
        $quantity   = max( 1, absint( $item['quantity'] ?? 1 ) );
        $handler    = self::get_addons_cart_handler();

        if ( ! $handler ) {
            wp_send_json_error( array( 'message' => __( 'WooCommerce Product Add-Ons cart handler was not found.', 'wc-side-cart-addon-editor' ) ), 500 );
        }

        $old_post = $_POST;
        try {
            $_POST = array_merge( $fields, array(
                'quantity' => $quantity,
                'add-to-cart' => $product_id,
            ) );

            if ( is_callable( array( $handler, 'validate_add_cart_item' ) ) ) {
                // Clear stale errors so that, if Product Add-Ons rejects a value, we can
                // return its real validation message instead of a misleading generic one.
                if ( function_exists( 'wc_clear_notices' ) ) {
                    wc_clear_notices();
                }

                $valid = $handler->validate_add_cart_item( true, $product_id, $quantity, $_POST );
                if ( ! $valid ) {
                    $message = '';
                    if ( function_exists( 'wc_get_notices' ) ) {
                        $notices = wc_get_notices( 'error' );
                        if ( ! empty( $notices ) ) {
                            $first = reset( $notices );
                            if ( is_array( $first ) && isset( $first['notice'] ) ) {
                                $message = wp_strip_all_tags( $first['notice'] );
                            } elseif ( is_string( $first ) ) {
                                $message = wp_strip_all_tags( $first );
                            }
                        }
                    }
                    throw new Exception( $message ?: __( 'Please complete all required add-on fields.', 'wc-side-cart-addon-editor' ) );
                }
            }

            $processed = $handler->add_cart_item_data( array(), $product_id );
            $new_addons = isset( $processed['addons'] ) && is_array( $processed['addons'] ) ? $processed['addons'] : array();

            WC()->cart->cart_contents[ $cart_key ]['addons'] = $new_addons;

            // Reset product object to a fresh base-price instance before Product Add-Ons filters run.
            $price_product_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : $product_id;
            $fresh_product = wc_get_product( $price_product_id );
            if ( $fresh_product ) {
                WC()->cart->cart_contents[ $cart_key ]['data'] = $fresh_product;
            }

            WC()->cart->cart_contents[ $cart_key ] = apply_filters( 'woocommerce_add_cart_item', WC()->cart->cart_contents[ $cart_key ], $cart_key );
            WC()->cart->set_session();
            WC()->cart->calculate_totals();
        } catch ( Throwable $e ) {
            $_POST = $old_post;
            wp_send_json_error( array( 'message' => $e->getMessage() ?: __( 'Could not update add-ons.', 'wc-side-cart-addon-editor' ) ), 400 );
        }
        $_POST = $old_post;

        wp_send_json_success( array(
            'message' => __( 'Add-ons updated.', 'wc-side-cart-addon-editor' ),
            'cart_hash' => WC()->cart->get_cart_hash(),
        ) );
    }

    public static function ajax_get_variation_form() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'WooCommerce is required.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id && ! empty( $_POST['product_url'] ) ) {
            $url = esc_url_raw( wp_unslash( $_POST['product_url'] ) );
            $post_id = url_to_postid( $url );
            if ( $post_id ) {
                $product_id = $post_id;
            } else {
                $path = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
                $slug = basename( $path );
                $post = get_page_by_path( $slug, OBJECT, 'product' );
                if ( $post ) {
                    $product_id = $post->ID;
                }
            }
        }

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        // Check transient cache for instant 0ms payload retrieval
        $cache_key = 'wcscae_vform_' . $product_id;
        $cached_payload = get_transient( $cache_key );
        if ( ! empty( $cached_payload ) && is_array( $cached_payload ) ) {
            wp_send_json_success( $cached_payload );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            wp_send_json_error( array( 'message' => __( 'Variable product not found.', 'wc-side-cart-addon-editor' ) ), 404 );
        }

        /** @var WC_Product_Variable $product */
        $variations_data = $product->get_available_variations();
        $variations_json = wp_json_encode( $variations_data );
        $attributes      = $product->get_variation_attributes();
        $selected_attrs  = $product->get_default_attributes();
        $image_html      = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'wcscae-product-thumb' ) );
        $price_html      = $product->get_price_html();
        $has_addons      = self::addons_available() && ! empty( self::get_product_addons( $product_id ) );
        $addons_html     = '';

        if ( $has_addons ) {
            $addons_html = self::render_official_addons( $product_id );
            if ( ! $addons_html ) {
                ob_start();
                $addons = self::get_product_addons( $product_id );
                foreach ( $addons as $addon ) {
                    self::render_addon( $addon, array() );
                }
                $addons_html = ob_get_clean();
            }
        }

        ob_start();
        ?>
        <form class="wcscae-variation-form" data-product_id="<?php echo esc_attr( $product_id ); ?>" data-product_variations="<?php echo esc_attr( $variations_json ); ?>">
            <div class="wcscae-product-summary">
                <div class="wcscae-summary-image">
                    <?php echo $image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <div class="wcscae-summary-info">
                    <h4 class="wcscae-product-title"><?php echo esc_html( $product->get_name() ); ?></h4>
                    <div class="wcscae-product-price-display"><?php echo $price_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    <div class="wcscae-stock-status"></div>
                </div>
            </div>

            <div class="wcscae-attributes-wrap">
                <?php foreach ( $attributes as $attribute_name => $options ) :
                    $sanitized_name = sanitize_title( $attribute_name );
                    $selected_value = isset( $selected_attrs[ $sanitized_name ] ) ? $selected_attrs[ $sanitized_name ] : ( $selected_attrs[ $attribute_name ] ?? '' );
                    $label          = wc_attribute_label( $attribute_name, $product );
                    $field_name     = 'attribute_' . $sanitized_name;
                ?>
                    <div class="wcscae-field wcscae-attribute-field" data-attribute_name="<?php echo esc_attr( $field_name ); ?>">
                        <label class="wcscae-label"><?php echo esc_html( $label ); ?> <span class="required">*</span></label>
                        <select name="<?php echo esc_attr( $field_name ); ?>" class="wcscae-attr-select" data-attribute_name="<?php echo esc_attr( $field_name ); ?>" required>
                            <option value=""><?php echo esc_html__( 'Choose an option', 'wc-side-cart-addon-editor' ); ?>…</option>
                            <?php
                            if ( is_array( $options ) ) {
                                if ( taxonomy_exists( $attribute_name ) ) {
                                    $terms = wc_get_product_terms( $product_id, $attribute_name, array( 'fields' => 'all' ) );
                                    foreach ( $terms as $term ) {
                                        if ( in_array( $term->slug, $options, true ) ) {
                                            echo '<option value="' . esc_attr( $term->slug ) . '" ' . selected( sanitize_title( $selected_value ), $term->slug, false ) . '>' . esc_html( apply_filters( 'woocommerce_variation_option_name', $term->name, $term, $attribute_name, $product ) ) . '</option>';
                                        }
                                    }
                                } else {
                                    foreach ( $options as $option ) {
                                        echo '<option value="' . esc_attr( $option ) . '" ' . selected( $selected_value, $option, false ) . '>' . esc_html( apply_filters( 'woocommerce_variation_option_name', $option, null, $attribute_name, $product ) ) . '</option>';
                                    }
                                }
                            }
                            ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $addons_html ) ) : ?>
                <div class="wcscae-modal-addons-wrap">
                    <div class="wcscae-heading"><?php esc_html_e( 'Add-On Options', 'wc-side-cart-addon-editor' ); ?></div>
                    <?php echo $addons_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endif; ?>

            <div class="wcscae-qty-price-row">
                <div class="wcscae-qty-box">
                    <label class="wcscae-label"><?php esc_html_e( 'Quantity', 'wc-side-cart-addon-editor' ); ?></label>
                    <div class="wcscae-qty-ctrl">
                        <button type="button" class="wcscae-qty-btn wcscae-qty-minus" aria-label="Decrease quantity">-</button>
                        <input type="number" name="quantity" value="1" min="1" step="1" class="wcscae-qty-input" inputmode="numeric">
                        <button type="button" class="wcscae-qty-btn wcscae-qty-plus" aria-label="Increase quantity">+</button>
                    </div>
                </div>
            </div>

            <input type="hidden" name="variation_id" class="wcscae-variation-id" value="0">
            <input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>">

            <div class="wcscae-actions">
                <button type="button" class="wcscae-cancel"><?php esc_html_e( 'Cancel', 'wc-side-cart-addon-editor' ); ?></button>
                <button type="submit" class="wcscae-save wcscae-atc-submit" disabled><?php esc_html_e( 'Add to cart', 'wc-side-cart-addon-editor' ); ?></button>
            </div>
        </form>
        <?php
        $html = ob_get_clean();

        $payload = array(
            'html'        => $html,
            'title'       => $product->get_name(),
            'variations'  => $variations_data,
        );
        set_transient( $cache_key, $payload, HOUR_IN_SECONDS * 6 );

        wp_send_json_success( $payload );
    }

    public static function ajax_add_variation_to_cart() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! class_exists( 'WooCommerce' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => __( 'WooCommerce cart is unavailable.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        $product_id   = absint( $_POST['product_id'] ?? 0 );
        $variation_id = absint( $_POST['variation_id'] ?? 0 );
        $quantity     = max( 1, absint( $_POST['quantity'] ?? 1 ) );
        $serialized   = isset( $_POST['fields'] ) ? (string) wp_unslash( $_POST['fields'] ) : '';
        parse_str( $serialized, $form_data );
        if ( ! is_array( $form_data ) ) {
            $form_data = array();
        }
        $form_data = wc_clean( $form_data );

        if ( ! empty( $form_data['quantity'] ) ) {
            $quantity = max( 1, absint( $form_data['quantity'] ) );
        }
        if ( empty( $variation_id ) && ! empty( $form_data['variation_id'] ) ) {
            $variation_id = absint( $form_data['variation_id'] );
        }
        if ( empty( $product_id ) && ! empty( $form_data['product_id'] ) ) {
            $product_id = absint( $form_data['product_id'] );
        }

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => __( 'Please select a valid product.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid variable product.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        // Extract variation attributes (attribute_*)
        $variations = array();
        foreach ( $form_data as $key => $value ) {
            if ( 0 === strpos( $key, 'attribute_' ) ) {
                $variations[ $key ] = $value;
            }
        }

        if ( ! $variation_id ) {
            $data_store   = WC_Data_Store::load( 'product' );
            $variation_id = $data_store->find_matching_product_variation( $product, $variations );
        }

        if ( ! $variation_id ) {
            wp_send_json_error( array( 'message' => __( 'Please select all product options before adding to cart.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        $variation_product = wc_get_product( $variation_id );
        if ( ! $variation_product || ! $variation_product->is_purchasable() ) {
            wp_send_json_error( array( 'message' => __( 'This variation cannot be purchased.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        if ( ! $variation_product->is_in_stock() ) {
            wp_send_json_error( array( 'message' => __( 'This variation is out of stock.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        $cart_item_data = array();

        // Handle Product Add-Ons if present
        if ( self::addons_available() ) {
            $handler = self::get_addons_cart_handler();
            if ( $handler ) {
                $old_post = $_POST;
                try {
                    $_POST = array_merge( $form_data, array(
                        'quantity'     => $quantity,
                        'add-to-cart'  => $product_id,
                        'variation_id' => $variation_id,
                    ) );

                    if ( is_callable( array( $handler, 'validate_add_cart_item' ) ) ) {
                        if ( function_exists( 'wc_clear_notices' ) ) {
                            wc_clear_notices();
                        }
                        $valid = $handler->validate_add_cart_item( true, $product_id, $quantity, $_POST );
                        if ( ! $valid ) {
                            $message = '';
                            if ( function_exists( 'wc_get_notices' ) ) {
                                $notices = wc_get_notices( 'error' );
                                if ( ! empty( $notices ) ) {
                                    $first = reset( $notices );
                                    if ( is_array( $first ) && isset( $first['notice'] ) ) {
                                        $message = wp_strip_all_tags( $first['notice'] );
                                    } elseif ( is_string( $first ) ) {
                                        $message = wp_strip_all_tags( $first );
                                    }
                                }
                            }
                            throw new Exception( $message ?: __( 'Please complete all required add-on fields.', 'wc-side-cart-addon-editor' ) );
                        }
                    }

                    $cart_item_data = $handler->add_cart_item_data( $cart_item_data, $product_id );
                } catch ( Throwable $e ) {
                    $_POST = $old_post;
                    wp_send_json_error( array( 'message' => $e->getMessage() ?: __( 'Could not validate options.', 'wc-side-cart-addon-editor' ) ), 400 );
                }
                $_POST = $old_post;
            }
        }

        // Add to cart
        $cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations, $cart_item_data );

        if ( ! $cart_item_key ) {
            $message = '';
            if ( function_exists( 'wc_get_notices' ) ) {
                $notices = wc_get_notices( 'error' );
                if ( ! empty( $notices ) ) {
                    $first = reset( $notices );
                    if ( is_array( $first ) && isset( $first['notice'] ) ) {
                        $message = wp_strip_all_tags( $first['notice'] );
                    } elseif ( is_string( $first ) ) {
                        $message = wp_strip_all_tags( $first );
                    }
                }
            }
            wp_send_json_error( array( 'message' => $message ?: __( 'Could not add product to cart.', 'wc-side-cart-addon-editor' ) ), 400 );
        }

        // Return cart fragments and updated cart hash
        $data = array(
            'cart_hash'     => WC()->cart->get_cart_hash(),
            'cart_item_key' => $cart_item_key,
            'fragments'     => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
            'message'       => __( 'Product added to cart.', 'wc-side-cart-addon-editor' ),
        );

        if ( class_exists( 'WC_AJAX' ) && function_exists( 'woocommerce_mini_cart' ) ) {
            ob_start();
            woocommerce_mini_cart();
            $mini_cart = ob_get_clean();
            $data['fragments']['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';
        }

        wp_send_json_success( $data );
    }
}

add_action( 'plugins_loaded', array( 'WCSCAE_Plugin', 'init' ), 30 );
