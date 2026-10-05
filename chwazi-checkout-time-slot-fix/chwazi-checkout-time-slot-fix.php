<?php
/**
 * Plugin Name: Chwazi - Upcoming Time Slots Fix
 * Plugin URI: https://wordpress.org/plugins/
 * Description: Fixes WooCommerce checkout time selector for Chwazi. Hides/disables passed time slots when Today is selected, and displays all time slots normally for future dates.
 * Version: 1.0.0
 * Author: Antigravity
 * Text Domain: chwazi-checkout-time-slot-fix
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chwazi_Checkout_Time_Slot_Fix {

	/**
	 * Single instance of the class.
	 *
	 * @var Chwazi_Checkout_Time_Slot_Fix
	 */
	private static $instance = null;

	/**
	 * Main instance getter.
	 *
	 * @return Chwazi_Checkout_Time_Slot_Fix
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Filter time slots returned via AJAX by Chwazi
		add_filter( 'chwazi_times_for_day_after', array( $this, 'filter_times_for_day' ), 100, 4 );

		// Add placeholders for pickup date/time and postcode fields
		add_filter( 'woocommerce_form_field_args', array( $this, 'add_pickup_field_placeholders' ), 20, 2 );
		add_filter( 'woocommerce_default_address_fields', array( $this, 'filter_postcode_placeholder_default_address' ), 20 );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'filter_postcode_placeholder_checkout_fields' ), 20 );

		// Enqueue frontend script on checkout page
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ), 20 );

		// Server-side validation during checkout submission
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_time_slot' ), 20, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_name_and_email_fields' ), 20, 2 );
	}

	/**
	 * Add placeholder text to Chwazi pickup date/time and postcode fields, and minlength attribute.
	 *
	 * @param array  $args Field configuration.
	 * @param string $key Field ID key.
	 * @return array
	 */
	public function add_pickup_field_placeholders( $args, $key ) {
		if ( 'lpac_dps_pickup_date' === $key ) {
			$args['placeholder'] = __( 'Choose Pickup Date', 'chwazi-checkout-time-slot-fix' );
		} elseif ( 'lpac_dps_pickup_time' === $key ) {
			$args['placeholder'] = __( 'Choose Pickup Time', 'chwazi-checkout-time-slot-fix' );
		} elseif ( 'billing_postcode' === $key || 'shipping_postcode' === $key ) {
			$args['placeholder'] = __( 'Enter post code', 'chwazi-checkout-time-slot-fix' );
		}

		if ( in_array( $key, array( 'billing_first_name', 'billing_last_name', 'shipping_first_name', 'shipping_last_name' ), true ) ) {
			$args['custom_attributes']              = $args['custom_attributes'] ?? array();
			$args['custom_attributes']['minlength'] = '3';
		}

		if ( in_array( $key, array( 'billing_postcode', 'shipping_postcode' ), true ) ) {
			$args['custom_attributes']              = $args['custom_attributes'] ?? array();
			$args['custom_attributes']['maxlength'] = '4';
		}

		return $args;
	}

	/**
	 * Filter default address fields for postcode placeholder.
	 *
	 * @param array $fields Address fields.
	 * @return array
	 */
	public function filter_postcode_placeholder_default_address( $fields ) {
		if ( isset( $fields['postcode'] ) ) {
			$fields['postcode']['placeholder'] = __( 'Enter post code', 'chwazi-checkout-time-slot-fix' );
		}
		return $fields;
	}

	/**
	 * Filter checkout fields array for postcode placeholders.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function filter_postcode_placeholder_checkout_fields( $fields ) {
		if ( isset( $fields['billing']['billing_postcode'] ) ) {
			$fields['billing']['billing_postcode']['placeholder'] = __( 'Enter post code', 'chwazi-checkout-time-slot-fix' );
		}
		if ( isset( $fields['shipping']['shipping_postcode'] ) ) {
			$fields['shipping']['shipping_postcode']['placeholder'] = __( 'Enter post code', 'chwazi-checkout-time-slot-fix' );
		}
		return $fields;
	}

	/**
	 * Get current site DateTime object in store timezone.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_current_store_datetime() {
		return current_datetime();
	}

	/**
	 * Extract start time string from slot data or string.
	 *
	 * @param string $time_str e.g. "11:00 AM - 12:00 PM" or "11:00 AM" or "14:00"
	 * @return string
	 */
	private function extract_start_time( $time_str ) {
		$parts = explode( '-', $time_str );
		return trim( $parts[0] );
	}

	/**
	 * Parse time string into DateTimeImmutable object for a given date in store timezone.
	 *
	 * @param string $date_str Format "Y-m-d"
	 * @param string $time_str e.g. "11:00 AM" or "14:30"
	 * @return DateTimeImmutable|null
	 */
	private function parse_time_to_datetime( $date_str, $time_str ) {
		if ( empty( $time_str ) ) {
			return null;
		}

		$tz               = wp_timezone();
		$clean_time       = strtoupper( trim( $time_str ) );
		$datetime_combine = $date_str . ' ' . $clean_time;

		// Format attempts
		$formats = array(
			'Y-m-d g:i A',
			'Y-m-d h:i A',
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d G:i',
		);

		foreach ( $formats as $fmt ) {
			$dt = DateTimeImmutable::createFromFormat( $fmt, $datetime_combine, $tz );
			if ( false !== $dt ) {
				return $dt;
			}
		}

		// Generic fallback
		try {
			return new DateTimeImmutable( $datetime_combine, $tz );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Filter time slots returned by Chwazi AJAX for a selected date.
	 *
	 * @param array  $times_for_day Array of time slot objects/arrays from Chwazi.
	 * @param string $date Selected date formatted Y-m-d.
	 * @param string $day_of_the_week Day of week name (e.g. "monday").
	 * @param string $order_type "delivery" or "pickup".
	 * @return array
	 */
	public function filter_times_for_day( $times_for_day, $date, $day_of_the_week, $order_type ) {
		if ( empty( $times_for_day ) || ! is_array( $times_for_day ) ) {
			return $times_for_day;
		}

		$now        = $this->get_current_store_datetime();
		$today_date = $now->format( 'Y-m-d' );

		// If a future date is selected, return all Chwazi-configured time slots normally
		if ( $date !== $today_date ) {
			return $times_for_day;
		}

		// If Today is selected, hide/disable all time slots that have already passed
		$filtered = array();

		foreach ( $times_for_day as $index => $slot_data ) {
			$from_time = $slot_data['time_range']['from'] ?? '';
			if ( empty( $from_time ) ) {
				continue;
			}

			$slot_datetime = $this->parse_time_to_datetime( $today_date, $from_time );

			if ( null !== $slot_datetime ) {
				// Slot is upcoming if start datetime is after current store time
				if ( $slot_datetime > $now ) {
					$filtered[ $index ] = $slot_data;
				}
			} else {
				// If parsing failed, keep slot to prevent accidental blocking
				$filtered[ $index ] = $slot_data;
			}
		}

		return $filtered;
	}

	/**
	 * Enqueue frontend JavaScript for dynamic checkout handling.
	 */
	public function enqueue_checkout_scripts() {
		if ( ! is_checkout() && ! is_page( 'checkout' ) ) {
			return;
		}

		$now        = $this->get_current_store_datetime();
		$today_date = $now->format( 'Y-m-d' );
		$timestamp  = $now->getTimestamp() * 1000; // ms for JS

		wp_enqueue_script(
			'chwazi-checkout-time-slot-fix',
			plugin_dir_url( __FILE__ ) . 'assets/js/chwazi-time-fix.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_localize_script(
			'chwazi-checkout-time-slot-fix',
			'ChwaziTimeFixData',
			array(
				'todayDate'    => $today_date,
				'serverTimeMs' => $timestamp,
				'timezone'     => wp_timezone_string(),
				'i18n'         => array(
					'noTimeSlotsAvailable'  => __( 'No available time slots for today', 'chwazi-checkout-time-slot-fix' ),
					'pickupDatePlaceholder' => __( 'Choose Pickup Date', 'chwazi-checkout-time-slot-fix' ),
					'pickupTimePlaceholder' => __( 'Choose Pickup Time', 'chwazi-checkout-time-slot-fix' ),
					'postcodePlaceholder'   => __( 'Enter post code', 'chwazi-checkout-time-slot-fix' ),
					'firstNameLiveError'    => __( 'First name must be at least 3 characters.', 'chwazi-checkout-time-slot-fix' ),
					'lastNameLiveError'     => __( 'Last name must be at least 3 characters.', 'chwazi-checkout-time-slot-fix' ),
					'emailLiveError'        => __( 'Please enter a valid email address', 'chwazi-checkout-time-slot-fix' ),
					'phoneLiveError'        => __( 'Please enter a valid Australian phone number.', 'chwazi-checkout-time-slot-fix' ),
					'postcodeLiveError'     => __( 'Enter a valid postcode', 'chwazi-checkout-time-slot-fix' ),
				),
			)
		);

		$css = '
			.dps-time-field input:disabled,
			.dps-time-field select:disabled,
			#lpac_dps_delivery_time:disabled,
			#lpac_dps_pickup_time:disabled {
				opacity: 0.6 !important;
				cursor: not-allowed !important;
				background-color: #f5f5f5 !important;
				pointer-events: none !important;
			}
			.chwazi-live-error {
				color: #e2401c;
				font-size: 0.85em;
				margin-top: 4px;
				display: block;
				font-weight: 500;
				line-height: 1.3;
			}
		';
		wp_register_style( 'chwazi-checkout-time-slot-fix-css', false );
		wp_enqueue_style( 'chwazi-checkout-time-slot-fix-css' );
		wp_add_inline_style( 'chwazi-checkout-time-slot-fix-css', $css );
	}

	/**
	 * Validate time slot on checkout submission to prevent ordering past slots.
	 *
	 * @param array    $data Post data.
	 * @param WP_Error $errors Errors object.
	 */
	public function validate_checkout_time_slot( $data, $errors ) {
		$order_type = sanitize_text_field( wp_unslash( $_POST['lpac_dps_order_type'] ?? '' ) );
		if ( empty( $order_type ) ) {
			return;
		}

		$date_set = sanitize_text_field( wp_unslash( $_POST[ "lpac_dps_{$order_type}_date" ] ?? '' ) );
		$time_set = sanitize_text_field( wp_unslash( $_POST[ "lpac_dps_{$order_type}_time" ] ?? '' ) );

		if ( empty( $date_set ) || empty( $time_set ) ) {
			return;
		}

		$now        = $this->get_current_store_datetime();
		$today_date = $now->format( 'Y-m-d' );

		// Only enforce past check if date is Today
		if ( $date_set === $today_date ) {
			$start_time = $this->extract_start_time( $time_set );
			$slot_dt    = $this->parse_time_to_datetime( $today_date, $start_time );

			if ( null !== $slot_dt && $slot_dt <= $now ) {
				$errors->add(
					'validation',
					'<strong>' . esc_html__( 'The selected time slot has already passed. Please select an upcoming time slot.', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
				);
			}
		}
	}

	/**
	 * Server-side validation for First Name, Last Name (min 3 chars), Email format (min 15 chars), AU Phone, and AU Postcode.
	 *
	 * @param array    $data Sanitized checkout post data.
	 * @param WP_Error $errors Errors object.
	 */
	public function validate_name_and_email_fields( $data, $errors ) {
		// Billing First Name
		$billing_first_name = trim( $data['billing_first_name'] ?? '' );
		if ( empty( $billing_first_name ) || mb_strlen( $billing_first_name ) < 3 ) {
			$errors->add(
				'billing_first_name_validation',
				'<strong>' . esc_html__( 'First Name must be at least 3 characters long.', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
			);
		}

		// Billing Last Name
		$billing_last_name = trim( $data['billing_last_name'] ?? '' );
		if ( empty( $billing_last_name ) || mb_strlen( $billing_last_name ) < 3 ) {
			$errors->add(
				'billing_last_name_validation',
				'<strong>' . esc_html__( 'Last Name must be at least 3 characters long.', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
			);
		}

		// Shipping First Name & Last Name (if ship to different address is checked)
		$ship_to_different = ! empty( $_POST['ship_to_different_address'] );
		if ( $ship_to_different ) {
			$shipping_first_name = trim( $data['shipping_first_name'] ?? '' );
			if ( empty( $shipping_first_name ) || mb_strlen( $shipping_first_name ) < 3 ) {
				$errors->add(
					'shipping_first_name_validation',
					'<strong>' . esc_html__( 'Shipping First Name must be at least 3 characters long.', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
				);
			}

			$shipping_last_name = trim( $data['shipping_last_name'] ?? '' );
			if ( empty( $shipping_last_name ) || mb_strlen( $shipping_last_name ) < 3 ) {
				$errors->add(
					'shipping_last_name_validation',
					'<strong>' . esc_html__( 'Shipping Last Name must be at least 3 characters long.', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
				);
			}
		}

		// Email validation (format + min 15 characters)
		$billing_email = trim( $data['billing_email'] ?? '' );
		if ( empty( $billing_email ) || mb_strlen( $billing_email ) < 15 || ! is_email( $billing_email ) ) {
			$errors->add(
				'billing_email_validation',
				'<strong>' . esc_html__( 'Please enter a valid email address', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
			);
		}

		// Australian Phone Validation
		$billing_phone  = trim( $data['billing_phone'] ?? '' );
		$cleaned_phone  = preg_replace( '/[\s\-\(\)]/', '', $billing_phone );
		$au_phone_regex = '/^(?:\+?61|0)[23478]\d{8}$|^1(?:300|800)\d{6}$|^13\d{4}$/';

		if ( empty( $billing_phone ) || ! preg_match( $au_phone_regex, $cleaned_phone ) ) {
			$errors->add(
				'billing_phone_validation',
				'<strong>' . esc_html__( 'Please enter a valid Australian phone number.', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
			);
		}

		// Australian Postcode Validation (4 digits, numeric, between 0200 and 9999)
		$billing_postcode = trim( $data['billing_postcode'] ?? '' );
		$postcode_num     = intval( $billing_postcode );

		if ( empty( $billing_postcode ) || ! preg_match( '/^\d{4}$/', $billing_postcode ) || $postcode_num < 200 || $postcode_num > 9999 ) {
			$errors->add(
				'billing_postcode_validation',
				'<strong>' . esc_html__( 'Enter a valid postcode', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
			);
		}

		if ( $ship_to_different ) {
			$shipping_postcode = trim( $data['shipping_postcode'] ?? '' );
			$ship_postcode_num = intval( $shipping_postcode );
			if ( empty( $shipping_postcode ) || ! preg_match( '/^\d{4}$/', $shipping_postcode ) || $ship_postcode_num < 200 || $ship_postcode_num > 9999 ) {
				$errors->add(
					'shipping_postcode_validation',
					'<strong>' . esc_html__( 'Enter a valid postcode', 'chwazi-checkout-time-slot-fix' ) . '</strong>'
				);
			}
		}
	}
}

// Initialize plugin
add_action( 'plugins_loaded', array( 'Chwazi_Checkout_Time_Slot_Fix', 'get_instance' ) );
