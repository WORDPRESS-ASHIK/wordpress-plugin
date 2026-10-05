<?php
/**
 * Frontend Side Card Interface & Display Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PASC_Frontend {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Enqueue frontend scripts & styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Customize loop add to cart button
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'modify_loop_add_to_cart_button' ), 20, 3 );

		// Single Product Page integration
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_single_product_addon_trigger' ), 15 );

		// Append side card drawer markup shell to footer
		add_action( 'wp_footer', array( $this, 'render_sidecard_drawer_shell' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_wc_pasc_get_sidecard', array( $this, 'ajax_get_sidecard_content' ) );
		add_action( 'wp_ajax_nopriv_wc_pasc_get_sidecard', array( $this, 'ajax_get_sidecard_content' ) );

		add_action( 'wp_ajax_wc_pasc_ajax_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_wc_pasc_ajax_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
	}

	/**
	 * Enqueue frontend CSS and JS
	 */
	public function enqueue_frontend_assets() {
		wp_enqueue_style(
			'wc-pasc-frontend-style',
			WC_PASC_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WC_PASC_VERSION
		);

		wp_enqueue_script(
			'wc-pasc-frontend-script',
			WC_PASC_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			WC_PASC_VERSION,
			true
		);

		wp_localize_script(
			'wc-pasc-frontend-script',
			'wcPascData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'wc_pasc_nonce' ),
				'currencySymbol' => get_woocommerce_currency_symbol(),
				'currencyPos'    => get_option( 'woocommerce_currency_pos', 'left' ),
				'decimals'       => wc_get_price_decimals(),
				'decimalSep'     => wc_get_price_decimal_separator(),
				'thousandSep'    => wc_get_price_thousand_separator(),
				'i18n'           => array(
					'selected'       => __( 'selected', 'wc-product-addons-sidecard' ),
					'requiredNotice' => __( 'Please complete all required selections before adding to cart.', 'wc-product-addons-sidecard' ),
					'maxNotice'      => __( 'You have reached the maximum selection limit for this option.', 'wc-product-addons-sidecard' ),
					'addingToCart'   => __( 'Adding to Cart...', 'wc-product-addons-sidecard' ),
					'addedToCart'    => __( 'Added to Cart!', 'wc-product-addons-sidecard' ),
					'customize'      => __( 'Customize Options', 'wc-product-addons-sidecard' ),
				),
			)
		);
	}

	/**
	 * Modify loop button if product has add-ons or box presets enabled
	 */
	public function modify_loop_add_to_cart_link( $link, $product, $args = array() ) {
		if ( ! $product ) {
			return $link;
		}

		$product_id      = $product->get_id();
		$enabled         = get_post_meta( $product_id, '_wc_pasc_enabled', true );
		$box_qty_enabled = get_post_meta( $product_id, '_wc_pasc_box_qty_enabled', true );
		$addons          = get_post_meta( $product_id, '_wc_pasc_data', true );
		$pack_sizes      = wc_pasc_get_product_pack_sizes( $product_id );

		$has_addons = ( 'yes' === $enabled && ! empty( $addons ) );
		$has_boxes  = ( 'yes' === $box_qty_enabled && ! empty( $pack_sizes ) );

		if ( ! $has_addons && ! $has_boxes ) {
			return $link;
		}

		$custom_text = apply_filters( 'wc_pasc_loop_button_text', __( 'Customize & Add', 'wc-product-addons-sidecard' ), $product );
		$classes     = isset( $args['class'] ) ? $args['class'] : 'button';
		$classes    .= ' wc-pasc-open-sidecard-btn';

		return sprintf(
			'<a href="%s" data-product_id="%s" class="%s" data-product_sku="%s" aria-label="%s" rel="nofollow">%s</a>',
			esc_url( $product->get_permalink() ),
			esc_attr( $product_id ),
			esc_attr( $classes ),
			esc_attr( $product->get_sku() ),
			esc_attr( sprintf( __( 'Customize %s', 'wc-product-addons-sidecard' ), $product->get_name() ) ),
			'<span class="wc-pasc-btn-icon">⚡</span> ' . esc_html( $custom_text )
		);
	}

	/**
	 * Single Product Page Trigger or Inline Customizer
	 */
	public function render_single_product_addon_trigger() {
		global $product;
		if ( ! $product ) {
			return;
		}

		$product_id      = $product->get_id();
		$enabled         = get_post_meta( $product_id, '_wc_pasc_enabled', true );
		$box_qty_enabled = get_post_meta( $product_id, '_wc_pasc_box_qty_enabled', true );
		$addons          = get_post_meta( $product_id, '_wc_pasc_data', true );
		$pack_sizes      = wc_pasc_get_product_pack_sizes( $product_id );

		$has_addons = ( 'yes' === $enabled && ! empty( $addons ) );
		$has_boxes  = ( 'yes' === $box_qty_enabled && ! empty( $pack_sizes ) );

		if ( ! $has_addons && ! $has_boxes ) {
			return;
		}

		?>
		<div class="wc-pasc-single-trigger-box">
			<button type="button" class="button alt wc-pasc-open-sidecard-btn wc-pasc-single-custom-btn" data-product_id="<?php echo esc_attr( $product_id ); ?>">
				<span class="wc-pasc-magic-icon">✨</span> <?php esc_html_e( 'Customize Options & Flavours', 'wc-product-addons-sidecard' ); ?>
			</button>
			<p class="wc-pasc-single-tip">
				<?php esc_html_e( 'Click to choose your flavours, pack sizes & extras before adding to cart.', 'wc-product-addons-sidecard' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Global Side Card Drawer Shell
	 */
	public function render_sidecard_drawer_shell() {
		?>
		<div id="wc-pasc-overlay" class="wc-pasc-overlay" aria-hidden="true"></div>
		<div id="wc-pasc-sidecard" class="wc-pasc-sidecard" role="dialog" aria-modal="true" aria-labelledby="wc-pasc-product-title" aria-hidden="true">
			
			<div class="wc-pasc-header">
				<div class="wc-pasc-header-info">
					<span class="wc-pasc-badge"><?php esc_html_e( 'Customize Product', 'wc-product-addons-sidecard' ); ?></span>
					<h3 id="wc-pasc-product-title" class="wc-pasc-title"><?php esc_html_e( 'Product Customization', 'wc-product-addons-sidecard' ); ?></h3>
				</div>
				<button type="button" class="wc-pasc-close-btn" aria-label="<?php esc_attr_e( 'Close side card', 'wc-product-addons-sidecard' ); ?>">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<line x1="18" y1="6" x2="6" y2="18"></line>
						<line x1="6" y1="6" x2="18" y2="18"></line>
					</svg>
				</button>
			</div>

			<div class="wc-pasc-body">
				<div class="wc-pasc-loader">
					<div class="wc-pasc-spinner"></div>
					<p><?php esc_html_e( 'Loading options...', 'wc-product-addons-sidecard' ); ?></p>
				</div>
				<div class="wc-pasc-content-container"></div>
			</div>

		</div>
		<?php
	}

	/**
	 * AJAX: Get Side Card Content for a Product
	 */
	public function ajax_get_sidecard_content() {
		check_ajax_referer( 'wc_pasc_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'wc-product-addons-sidecard' ) ) );
		}

		$enabled         = get_post_meta( $product_id, '_wc_pasc_enabled', true );
		$box_qty_enabled = get_post_meta( $product_id, '_wc_pasc_box_qty_enabled', true );
		$addons_data     = get_post_meta( $product_id, '_wc_pasc_data', true );

		$image_url = wp_get_attachment_image_url( $product->get_image_id(), 'medium' );
		if ( ! $image_url ) {
			$image_url = wc_placeholder_img_src( 'medium' );
		}

		$base_price     = floatval( $product->get_price() );
		$formatted_base = wc_price( $base_price );

		// Retrieve dynamic pack sizes using universal helper
		$pack_sizes = ( 'yes' === $box_qty_enabled ) ? wc_pasc_get_product_pack_sizes( $product_id ) : array();

		ob_start();
		?>
		<form id="wc-pasc-form" class="wc-pasc-form" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-base-price="<?php echo esc_attr( $base_price ); ?>">
			
			<!-- Product Hero Card -->
			<div class="wc-pasc-hero">
				<div class="wc-pasc-hero-thumb">
					<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" />
				</div>
				<div class="wc-pasc-hero-details">
					<h4 class="wc-pasc-hero-name"><?php echo esc_html( $product->get_name() ); ?></h4>
					<div class="wc-pasc-hero-price">
						<span class="wc-pasc-price-label"><?php esc_html_e( 'Base Price:', 'wc-product-addons-sidecard' ); ?></span>
						<span class="wc-pasc-base-price-display"><?php echo wp_kses_post( $formatted_base ); ?></span>
					</div>
					<?php if ( $product->get_short_description() ) : ?>
						<div class="wc-pasc-hero-desc">
							<?php echo wp_kses_post( wp_trim_words( $product->get_short_description(), 18 ) ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Box / Pack Size Selector (If enabled) -->
			<?php if ( ! empty( $pack_sizes ) ) : ?>
				<div class="wc-pasc-section wc-pasc-box-section">
					<div class="wc-pasc-section-header">
						<h4 class="wc-pasc-section-title">
							<span class="wc-pasc-icon">📦</span> <?php esc_html_e( 'Select Box / Pack Size', 'wc-product-addons-sidecard' ); ?>
						</h4>
						<span class="wc-pasc-badge wc-pasc-badge-req"><?php esc_html_e( 'Required', 'wc-product-addons-sidecard' ); ?></span>
					</div>
					<div class="wc-pasc-box-grid">
						<?php 
						$has_default = false;
						foreach ( $pack_sizes as $p ) {
							if ( ! empty( $p['default'] ) ) {
								$has_default = true;
								break;
							}
						}
						foreach ( $pack_sizes as $b_idx => $box ) : 
							$is_checked = $has_default ? ! empty( $box['default'] ) : ( 0 === $b_idx );
							$pack_price = ( isset( $box['price'] ) && $box['price'] > 0 ) ? floatval( $box['price'] ) : ( $base_price * floatval( $box['multiplier'] ) );
						?>
							<label class="wc-pasc-box-card <?php echo $is_checked ? 'is-selected' : ''; ?>">
								<input type="radio" 
									name="wc_pasc_box_option" 
									value="<?php echo esc_attr( $box['raw'] ); ?>" 
									data-multiplier="<?php echo esc_attr( $box['multiplier'] ); ?>"
									data-price="<?php echo esc_attr( $pack_price ); ?>"
									<?php checked( $is_checked, true ); ?> />
								<div class="wc-pasc-box-card-inner">
									<span class="wc-pasc-box-label"><?php echo esc_html( $box['label'] ); ?></span>
									<span class="wc-pasc-box-calc"><?php echo wp_kses_post( wc_price( $pack_price ) ); ?></span>
								</div>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<!-- Add-on Groups -->
			<?php if ( ! empty( $addons_data ) && is_array( $addons_data ) ) : ?>
				<div class="wc-pasc-addons-wrapper">
					<?php foreach ( $addons_data as $g_idx => $group ) : 
						$title     = ! empty( $group['title'] ) ? $group['title'] : sprintf( __( 'Option Group %d', 'wc-product-addons-sidecard' ), $g_idx + 1 );
						$subtitle  = isset( $group['subtitle'] ) ? $group['subtitle'] : '';
						$type      = isset( $group['type'] ) ? $group['type'] : 'checkbox';
						$max_limit = isset( $group['max_limit'] ) ? intval( $group['max_limit'] ) : ( 'radio' === $type ? 1 : 99 );
						$min_limit = isset( $group['min_limit'] ) ? intval( $group['min_limit'] ) : 0;
						$required  = ! empty( $group['required'] ) || $min_limit > 0;
						$options   = isset( $group['options'] ) && is_array( $group['options'] ) ? $group['options'] : array();

						if ( empty( $options ) ) {
							continue;
						}
					?>
						<div class="wc-pasc-addon-group" 
							data-group-id="<?php echo esc_attr( $g_idx ); ?>"
							data-type="<?php echo esc_attr( $type ); ?>"
							data-max-limit="<?php echo esc_attr( $max_limit ); ?>"
							data-min-limit="<?php echo esc_attr( $min_limit ); ?>"
							data-required="<?php echo $required ? '1' : '0'; ?>">

							<div class="wc-pasc-group-top">
								<div class="wc-pasc-group-info">
									<h4 class="wc-pasc-group-heading">
										<?php echo esc_html( $title ); ?>
										<?php if ( $required ) : ?>
											<span class="wc-pasc-req-star">*</span>
										<?php endif; ?>
									</h4>
									<?php if ( ! empty( $subtitle ) ) : ?>
										<p class="wc-pasc-group-sub"><?php echo esc_html( $subtitle ); ?></p>
									<?php endif; ?>
								</div>

								<div class="wc-pasc-counter-wrap">
									<?php if ( 'radio' === $type ) : ?>
										<span class="wc-pasc-rule-tag"><?php esc_html_e( 'Pick 1', 'wc-product-addons-sidecard' ); ?></span>
									<?php else : ?>
										<div class="wc-pasc-counter-badge" data-counter-for="<?php echo esc_attr( $g_idx ); ?>">
											<span class="wc-pasc-current-count">0</span> / <span class="wc-pasc-max-count"><?php echo esc_html( $max_limit ); ?></span> <span class="wc-pasc-count-label"><?php esc_html_e( 'selected', 'wc-product-addons-sidecard' ); ?></span>
										</div>
									<?php endif; ?>
								</div>
							</div>

							<!-- Options list -->
							<div class="wc-pasc-options-container">
								<?php foreach ( $options as $opt_idx => $opt ) : 
									$opt_name  = $opt['name'];
									$opt_price = isset( $opt['price'] ) ? floatval( $opt['price'] ) : 0.00;
									$opt_desc  = isset( $opt['description'] ) ? $opt['description'] : '';
									$opt_def   = ! empty( $opt['default'] );
									$input_name = 'radio' === $type ? "wc_pasc_addons[{$g_idx}]" : "wc_pasc_addons[{$g_idx}][]";
								?>
									<label class="wc-pasc-option-item <?php echo $opt_def ? 'is-checked' : ''; ?>">
										<div class="wc-pasc-option-left">
											<input type="<?php echo esc_attr( $type ); ?>" 
												name="<?php echo esc_attr( $input_name ); ?>" 
												value="<?php echo esc_attr( $opt_idx ); ?>"
												data-price="<?php echo esc_attr( $opt_price ); ?>"
												data-name="<?php echo esc_attr( $opt_name ); ?>"
												<?php checked( $opt_def, true ); ?> />
											<span class="wc-pasc-control-indicator"></span>
											<div class="wc-pasc-option-meta">
												<span class="wc-pasc-option-title"><?php echo esc_html( $opt_name ); ?></span>
												<?php if ( ! empty( $opt_desc ) ) : ?>
													<span class="wc-pasc-option-tag"><?php echo esc_html( $opt_desc ); ?></span>
												<?php endif; ?>
											</div>
										</div>
										<div class="wc-pasc-option-right">
											<?php if ( $opt_price > 0 ) : ?>
												<span class="wc-pasc-option-price">+<?php echo wp_kses_post( wc_price( $opt_price ) ); ?></span>
											<?php else : ?>
												<span class="wc-pasc-option-price is-free"><?php esc_html_e( 'Free', 'wc-product-addons-sidecard' ); ?></span>
											<?php endif; ?>
										</div>
									</label>
								<?php endforeach; ?>
							</div>

						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Bottom Sticky Action Bar inside the Drawer -->
			<div class="wc-pasc-footer">
				<div class="wc-pasc-footer-top">
					<?php if ( ! empty( $pack_sizes ) ) : ?>
						<!-- Pack size controls quantity, hide loose quantity stepper -->
						<input type="hidden" name="quantity" class="wc-pasc-qty-input" value="1" />
					<?php else : ?>
						<!-- Normal Product Quantity Stepper (when Pack Sizes is disabled) -->
						<div class="wc-pasc-quantity-wrap">
							<span class="wc-pasc-qty-label"><?php esc_html_e( 'Quantity:', 'wc-product-addons-sidecard' ); ?></span>
							<div class="wc-pasc-qty-stepper">
								<button type="button" class="wc-pasc-qty-btn wc-pasc-qty-minus" aria-label="<?php esc_attr_e( 'Decrease quantity', 'wc-product-addons-sidecard' ); ?>">-</button>
								<input type="number" name="quantity" class="wc-pasc-qty-input" value="1" min="1" max="99" step="1" />
								<button type="button" class="wc-pasc-qty-btn wc-pasc-qty-plus" aria-label="<?php esc_attr_e( 'Increase quantity', 'wc-product-addons-sidecard' ); ?>">+</button>
							</div>
						</div>
					<?php endif; ?>

					<div class="wc-pasc-total-wrap">
						<span class="wc-pasc-total-label"><?php esc_html_e( 'Total Price', 'wc-product-addons-sidecard' ); ?></span>
						<span class="wc-pasc-total-amount" id="wc-pasc-total-display"><?php echo wp_kses_post( $formatted_base ); ?></span>
					</div>
				</div>

				<div class="wc-pasc-action-wrap">
					<button type="submit" class="button alt wc-pasc-submit-btn" id="wc-pasc-add-btn">
						<span class="wc-pasc-btn-text">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="9" cy="21" r="1"></circle>
								<circle cx="20" cy="21" r="1"></circle>
								<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
							</svg>
							<?php esc_html_e( 'Add to Order', 'wc-product-addons-sidecard' ); ?>
						</span>
						<span class="wc-pasc-btn-loader" style="display:none;"></span>
					</button>
				</div>
			</div>

		</form>
		<?php
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'          => $html,
				'product_title' => $product->get_name(),
			)
		);
	}

	/**
	 * AJAX: Add to Cart with Add-ons
	 */
	public function ajax_add_to_cart() {
		check_ajax_referer( 'wc_pasc_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, intval( $_POST['quantity'] ) ) : 1;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'wc-product-addons-sidecard' ) ) );
		}

		$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity );

		if ( ! $passed_validation ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$error_messages = array();
			if ( ! empty( $notices ) ) {
				foreach ( $notices as $notice ) {
					$error_messages[] = $notice['notice'];
				}
			}
			wp_send_json_error(
				array(
					'message' => ! empty( $error_messages ) ? implode( ' ', $error_messages ) : __( 'Validation failed. Please check your selections.', 'wc-product-addons-sidecard' ),
				)
			);
		}

		// Add to cart with custom $_POST data
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity );

		if ( $cart_item_key ) {
			do_action( 'woocommerce_ajax_added_to_cart', $product_id );

			// Get standard WooCommerce fragments
			WC_AJAX::get_refreshed_fragments();
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not add product to cart. Please try again.', 'wc-product-addons-sidecard' ) ) );
		}
	}
}
