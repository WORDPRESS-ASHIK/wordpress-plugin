<?php
/**
 * Admin handler for WooCommerce Product Add-ons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PASC_Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Add custom product data tab
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
		// Output panel content
		add_action( 'woocommerce_product_data_panels', array( $this, 'output_product_data_panel' ) );
		// Save product meta
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
		// Register Admin Menu
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		// Register Settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		// Enqueue admin assets
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register Settings Submenu
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Product Add-ons Style & Colors', 'wc-product-addons-sidecard' ),
			__( 'Add-on Styles', 'wc-product-addons-sidecard' ),
			'manage_woocommerce',
			'wc-pasc-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register Plugin Settings
	 */
	public function register_settings() {
		register_setting(
			'wc_pasc_settings_group',
			'wc_pasc_styles',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_styles' ),
				'default'           => wc_pasc_get_default_styles(),
			)
		);
	}

	/**
	 * Sanitize Style Settings Array
	 */
	public function sanitize_styles( $input ) {
		$defaults = wc_pasc_get_default_styles();
		$output   = array();

		// Handle Reset to Defaults
		if ( isset( $_POST['wc_pasc_reset_styles'] ) ) {
			add_settings_error( 'wc_pasc_styles', 'styles_reset', __( 'Color settings have been reset to defaults.', 'wc-product-addons-sidecard' ), 'updated' );
			return $defaults;
		}

		if ( is_array( $input ) ) {
			foreach ( $defaults as $key => $default_val ) {
				if ( isset( $input[ $key ] ) ) {
					$output[ $key ] = wc_pasc_sanitize_color( $input[ $key ] );
				} else {
					$output[ $key ] = $default_val;
				}
			}
		}

		return $output;
	}

	/**
	 * Register Product Data Tab
	 */
	public function add_product_data_tab( $tabs ) {
		$tabs['wc_pasc_addons'] = array(
			'label'    => __( 'Product Add-ons & Side Card', 'wc-product-addons-sidecard' ),
			'target'   => 'wc_pasc_addons_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * Enqueue Admin JS & CSS
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post, $pagenow;

		$is_product_screen  = in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) && $post && 'product' === $post->post_type;
		$is_settings_screen = ( 'woocommerce_page_wc-pasc-settings' === $hook );

		if ( ! $is_product_screen && ! $is_settings_screen ) {
			return;
		}

		// Enqueue WordPress color picker
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_style(
			'wc-pasc-admin-style',
			WC_PASC_PLUGIN_URL . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			WC_PASC_VERSION
		);

		wp_enqueue_script(
			'wc-pasc-admin-script',
			WC_PASC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ),
			WC_PASC_VERSION,
			true
		);

		wp_localize_script(
			'wc-pasc-admin-script',
			'wcPascAdmin',
			array(
				'i18n' => array(
					'removeGroupConfirm'  => __( 'Are you sure you want to remove this add-on group?', 'wc-product-addons-sidecard' ),
					'removeOptionConfirm' => __( 'Are you sure you want to remove this option?', 'wc-product-addons-sidecard' ),
					'resetConfirm'        => __( 'Are you sure you want to reset all color settings to their default values?', 'wc-product-addons-sidecard' ),
				),
			)
		);
	}

	/**
	 * Render Global Style & Color Settings Page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$styles   = wc_pasc_get_styles();
		$defaults = wc_pasc_get_default_styles();

		// Color configurations grouped by section
		$sections = array(
			'general' => array(
				'title'       => __( '1. General & Popup Colors', 'wc-product-addons-sidecard' ),
				'description' => __( 'Configure the primary accent, modal card background, overlay, and CTA button colors.', 'wc-product-addons-sidecard' ),
				'fields'      => array(
					'primary_color'       => array(
						'label' => __( 'Primary / Accent Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Used for base prices, calculate badges, active indicators, and highlight accents.', 'wc-product-addons-sidecard' ),
					),
					'btn_bg_color'        => array(
						'label' => __( 'Button Background Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Background color of the modal "Add to Cart" submit button.', 'wc-product-addons-sidecard' ),
					),
					'btn_text_color'      => array(
						'label' => __( 'Button Text Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Text and icon color of the "Add to Cart" button.', 'wc-product-addons-sidecard' ),
					),
					'popup_bg_color'      => array(
						'label' => __( 'Popup Background Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Background color of the modal header, content sections, and footer card.', 'wc-product-addons-sidecard' ),
					),
					'popup_overlay_color' => array(
						'label' => __( 'Popup Overlay Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Dark backdrop tint behind the customization popup (e.g. #0f172a or rgba).', 'wc-product-addons-sidecard' ),
					),
					'close_btn_color'     => array(
						'label' => __( 'Popup Close / X Button Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Icon color of the popup top-right close button.', 'wc-product-addons-sidecard' ),
					),
				),
			),
			'flavours' => array(
				'title'       => __( '2. Flavours, Add-ons & Checkbox Colors', 'wc-product-addons-sidecard' ),
				'description' => __( 'Customize the look of flavour selection rows, checkboxes, and radio buttons.', 'wc-product-addons-sidecard' ),
				'fields'      => array(
					'checkbox_border_color'    => array(
						'label' => __( 'Checkbox Border Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Border color of unchecked option checkboxes and radio buttons.', 'wc-product-addons-sidecard' ),
					),
					'checkbox_checked_color'   => array(
						'label' => __( 'Checkbox Selected/Checked Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Fill and border color of checked checkboxes and radio circles.', 'wc-product-addons-sidecard' ),
					),
					'checkbox_checkmark_color' => array(
						'label' => __( 'Checkbox Checkmark Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Color of the checkmark icon or radio dot inside selected options.', 'wc-product-addons-sidecard' ),
					),
					'flavour_selected_bg'      => array(
						'label' => __( 'Flavour Selected Background Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Background highlight color of selected flavour / add-on item rows.', 'wc-product-addons-sidecard' ),
					),
					'flavour_selected_text'    => array(
						'label' => __( 'Flavour Selected Text Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Text title color of selected flavour / add-on item rows.', 'wc-product-addons-sidecard' ),
					),
				),
			),
			'pack_sizes' => array(
				'title'       => __( '3. Pack Size Selector Colors', 'wc-product-addons-sidecard' ),
				'description' => __( 'Customize the appearance of pack size cards (Box of 9 pc, Box of 12 pc, etc.).', 'wc-product-addons-sidecard' ),
				'fields'      => array(
					'pack_selected_border' => array(
						'label' => __( 'Pack Size Selected Border Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Border color and outline glow of the actively selected pack size card.', 'wc-product-addons-sidecard' ),
					),
					'pack_selected_bg'     => array(
						'label' => __( 'Pack Size Selected Background Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Background tint of the actively selected pack size card.', 'wc-product-addons-sidecard' ),
					),
					'pack_selected_text'   => array(
						'label' => __( 'Pack Size Selected Text Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Title and label text color of the actively selected pack size card.', 'wc-product-addons-sidecard' ),
					),
					'pack_normal_border'   => array(
						'label' => __( 'Pack Size Normal Border Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Border color of unselected / default pack size cards.', 'wc-product-addons-sidecard' ),
					),
				),
			),
			'quantity_validation' => array(
				'title'       => __( '4. Quantity Controls & Validation Colors', 'wc-product-addons-sidecard' ),
				'description' => __( 'Fine-tune the quantity +/- stepper buttons and error validation styling.', 'wc-product-addons-sidecard' ),
				'fields'      => array(
					'qty_btn_bg'     => array(
						'label' => __( 'Quantity Minus/Plus Button Background Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Background color of the [-] and [+] quantity stepper buttons.', 'wc-product-addons-sidecard' ),
					),
					'qty_btn_text'   => array(
						'label' => __( 'Quantity Minus/Plus Button Text Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Color of the minus and plus glyphs on the stepper buttons.', 'wc-product-addons-sidecard' ),
					),
					'qty_input_text' => array(
						'label' => __( 'Quantity Input / Text Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Text color of the quantity count number between the buttons.', 'wc-product-addons-sidecard' ),
					),
					'error_color'    => array(
						'label' => __( 'Error / Validation Color', 'wc-product-addons-sidecard' ),
						'desc'  => __( 'Color for required asterisk markers, validation error alerts, and limit notices.', 'wc-product-addons-sidecard' ),
					),
				),
			),
		);
		?>
		<div class="wrap wc-pasc-settings-wrap">
			<div class="wc-pasc-settings-header">
				<div class="wc-pasc-settings-header-content">
					<span class="wc-pasc-header-badge"><?php esc_html_e( 'Visual Customizer', 'wc-product-addons-sidecard' ); ?></span>
					<h1 class="wc-pasc-settings-title"><?php esc_html_e( 'Product Add-ons Style & Color Settings', 'wc-product-addons-sidecard' ); ?></h1>
					<p class="wc-pasc-settings-subtitle">
						<?php esc_html_e( 'Customize every aspect of your Product Customization Popup, Pack Size cards, Flavour checkboxes, and Quantity controls. All colors are dynamically applied to your storefront without hardcoding.', 'wc-product-addons-sidecard' ); ?>
					</p>
				</div>
			</div>

			<?php settings_errors( 'wc_pasc_styles' ); ?>

			<form method="post" action="options.php" class="wc-pasc-settings-form">
				<?php
				settings_fields( 'wc_pasc_settings_group' );
				?>

				<div class="wc-pasc-settings-grid">
					<?php foreach ( $sections as $s_key => $section ) : ?>
						<div class="wc-pasc-settings-card">
							<div class="wc-pasc-card-header">
								<h2 class="wc-pasc-card-title"><?php echo esc_html( $section['title'] ); ?></h2>
								<p class="wc-pasc-card-desc"><?php echo esc_html( $section['description'] ); ?></p>
							</div>

							<div class="wc-pasc-card-body">
								<?php foreach ( $section['fields'] as $f_key => $f_info ) : 
									$val = isset( $styles[ $f_key ] ) ? $styles[ $f_key ] : ( isset( $defaults[ $f_key ] ) ? $defaults[ $f_key ] : '#000000' );
									$def = isset( $defaults[ $f_key ] ) ? $defaults[ $f_key ] : '';
								?>
									<div class="wc-pasc-color-row">
										<div class="wc-pasc-color-info">
											<label for="wc_pasc_style_<?php echo esc_attr( $f_key ); ?>" class="wc-pasc-color-label">
												<?php echo esc_html( $f_info['label'] ); ?>
											</label>
											<p class="wc-pasc-color-desc"><?php echo esc_html( $f_info['desc'] ); ?></p>
											<?php if ( $def ) : ?>
												<span class="wc-pasc-default-tag">
													<?php esc_html_e( 'Default:', 'wc-product-addons-sidecard' ); ?> <code><?php echo esc_html( $def ); ?></code>
												</span>
											<?php endif; ?>
										</div>

										<div class="wc-pasc-color-picker-wrap">
											<input type="text" 
												id="wc_pasc_style_<?php echo esc_attr( $f_key ); ?>" 
												name="wc_pasc_styles[<?php echo esc_attr( $f_key ); ?>]" 
												value="<?php echo esc_attr( $val ); ?>" 
												data-default-color="<?php echo esc_attr( $def ); ?>" 
												class="wc-pasc-color-picker" />
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="wc-pasc-settings-footer-actions">
					<button type="submit" name="submit" id="submit" class="button button-primary button-hero wc-pasc-save-btn">
						<span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save Color Settings', 'wc-product-addons-sidecard' ); ?>
					</button>

					<button type="submit" name="wc_pasc_reset_styles" value="1" class="button button-secondary button-hero wc-pasc-reset-btn" onclick="return confirm(wcPascAdmin.i18n.resetConfirm);">
						<span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Reset All Colors to Defaults', 'wc-product-addons-sidecard' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Product Data Panel
	 */
	public function output_product_data_panel() {
		global $post;
		$product_id = $post->ID;

		$enabled         = get_post_meta( $product_id, '_wc_pasc_enabled', true );
		$box_qty_enabled = get_post_meta( $product_id, '_wc_pasc_box_qty_enabled', true );
		$pack_sizes      = get_post_meta( $product_id, '_wc_pasc_pack_sizes', true );
		$addons_data     = get_post_meta( $product_id, '_wc_pasc_data', true );

		if ( ! is_array( $addons_data ) ) {
			$addons_data = array();
		}

		if ( ! is_array( $pack_sizes ) ) {
			$pack_sizes = array();
			// Check if legacy presets exist to pre-fill
			$legacy_options = get_post_meta( $product_id, '_wc_pasc_box_options', true );
			if ( ! empty( $legacy_options ) ) {
				$legacy_parsed = wc_pasc_parse_box_presets( $legacy_options );
				$base_price    = floatval( get_post_meta( $product_id, '_price', true ) );
				foreach ( $legacy_parsed as $l_idx => $lp ) {
					$pack_sizes[] = array(
						'name'    => $lp['label'],
						'pieces'  => $lp['multiplier'],
						'price'   => $base_price * $lp['multiplier'],
						'default' => ( 0 === $l_idx ) ? 1 : 0,
					);
				}
			}
		}
		?>
		<div id="wc_pasc_addons_data" class="panel woocommerce_options_panel hidden">
			<div class="wc-pasc-admin-container">
				
				<div class="wc-pasc-admin-header">
					<div class="wc-pasc-title-wrap">
						<h2><?php esc_html_e( 'Product Add-ons & Side Card Configuration', 'wc-product-addons-sidecard' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Configure dynamic Pack Sizes (with custom individual pricing) and customizable Add-ons (Flavours, Toppings) with live selection counters.', 'wc-product-addons-sidecard' ); ?>
						</p>
					</div>
				</div>

				<div class="options_group wc-pasc-main-toggles">
					<?php
					woocommerce_wp_checkbox(
						array(
							'id'          => '_wc_pasc_box_qty_enabled',
							'label'       => __( 'Enable Box / Pack Sizes', 'wc-product-addons-sidecard' ),
							'description' => __( 'Allow customers to choose from multiple configured pack sizes with custom pricing.', 'wc-product-addons-sidecard' ),
							'value'       => $box_qty_enabled ? 'yes' : 'no',
						)
					);

					woocommerce_wp_checkbox(
						array(
							'id'          => '_wc_pasc_enabled',
							'label'       => __( 'Enable Flavours / Add-ons', 'wc-product-addons-sidecard' ),
							'description' => __( 'Enable side-card flavour and add-on group customization for this product.', 'wc-product-addons-sidecard' ),
							'value'       => $enabled ? 'yes' : 'no',
						)
					);
					?>
				</div>

				<!-- Dynamic Pack Sizes Repeater Section -->
				<div class="wc-pasc-pack-sizes-wrapper" style="<?php echo ( 'yes' === $box_qty_enabled ) ? '' : 'display:none;'; ?>">
					<div class="wc-pasc-section-box">
						<div class="wc-pasc-options-header">
							<div class="wc-pasc-options-heading-wrap">
								<span class="dashicons dashicons-products"></span>
								<div>
									<h3 class="wc-pasc-section-heading"><?php esc_html_e( 'Configured Pack Sizes & Pricing', 'wc-product-addons-sidecard' ); ?></h3>
									<p class="description" style="margin:2px 0 0 0;font-size:12px;color:#64748b;">
										<?php esc_html_e( 'Add unlimited pack sizes (e.g. Box of 9 pc, Box of 12 pc, Box of 15 pc) with their individual prices.', 'wc-product-addons-sidecard' ); ?>
									</p>
								</div>
							</div>
							<button type="button" class="button button-primary wc-pasc-add-pack-btn">
								<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Pack Size', 'wc-product-addons-sidecard' ); ?>
							</button>
						</div>

						<div class="wc-pasc-table-responsive">
							<table class="wc-pasc-options-table wc-pasc-pack-sizes-table">
								<thead>
									<tr>
										<th class="col-sort" width="36" title="<?php esc_attr_e( 'Drag to reorder', 'wc-product-addons-sidecard' ); ?>"></th>
										<th class="col-pack-name"><?php esc_html_e( 'Pack Name (e.g. Box of, Pack of)', 'wc-product-addons-sidecard' ); ?></th>
										<th class="col-pack-pieces" width="130"><?php esc_html_e( 'Pieces / Qty', 'wc-product-addons-sidecard' ); ?></th>
										<th class="col-pack-price" width="150"><?php esc_html_e( 'Pack Price ($)', 'wc-product-addons-sidecard' ); ?></th>
										<th class="col-default" width="70"><?php esc_html_e( 'Default', 'wc-product-addons-sidecard' ); ?></th>
										<th class="col-action" width="40"></th>
									</tr>
								</thead>
								<tbody class="wc-pasc-pack-sizes-list">
									<?php
									if ( ! empty( $pack_sizes ) ) {
										foreach ( $pack_sizes as $p_idx => $pack ) {
											$this->render_pack_row_html( $p_idx, $pack );
										}
									}
									?>
								</tbody>
							</table>
						</div>

						<div class="wc-pasc-pack-empty-notice" style="<?php echo empty( $pack_sizes ) ? 'display:block;' : 'display:none;'; ?>">
							<div class="wc-pasc-empty-state" style="padding:20px 10px;">
								<span class="dashicons dashicons-archive" style="font-size:28px;width:28px;height:28px;margin-bottom:6px;"></span>
								<p style="font-size:13px;"><?php esc_html_e( 'No pack sizes configured yet. Click "Add Pack Size" to create pack options (e.g. Box of 9 pc → $30, Box of 12 pc → $38).', 'wc-product-addons-sidecard' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Add-on Groups List -->
				<div class="wc-pasc-groups-wrapper" style="<?php echo ( 'yes' === $enabled ) ? '' : 'display:none;'; ?>">
					<div class="wc-pasc-groups-toolbar">
						<div>
							<h3><?php esc_html_e( 'Flavour / Add-on Groups & Rules', 'wc-product-addons-sidecard' ); ?></h3>
							<p class="description" style="margin:2px 0 0 0;font-size:12px;color:#64748b;">
								<?php esc_html_e( 'Configure options like flavours, toppings, or extras with selection limits (e.g. 2/3 selected).', 'wc-product-addons-sidecard' ); ?>
							</p>
						</div>
						<button type="button" class="button button-primary wc-pasc-add-group-btn">
							<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add New Add-on Group', 'wc-product-addons-sidecard' ); ?>
						</button>
					</div>

					<div id="wc-pasc-groups-list" class="wc-pasc-groups-list">
						<?php
						if ( ! empty( $addons_data ) ) {
							foreach ( $addons_data as $group_idx => $group ) {
								$this->render_group_html( $group_idx, $group );
							}
						}
						?>
					</div>

					<div class="wc-pasc-empty-notice" style="<?php echo empty( $addons_data ) ? 'display:block;' : 'display:none;'; ?>">
						<div class="wc-pasc-empty-state">
							<span class="dashicons dashicons-forms"></span>
							<p><?php esc_html_e( 'No add-on groups added yet. Click "Add New Add-on Group" to create options like Flavours, Toppings, or Extras.', 'wc-product-addons-sidecard' ); ?></p>
						</div>
					</div>
				</div>

			</div>
		</div>

		<!-- Template for New Pack Size Row (Hidden) -->
		<script type="text/template" id="tmpl-wc-pasc-pack-row">
			<?php $this->render_pack_row_html( '{{pack_index}}', array() ); ?>
		</script>

		<!-- Template for New Group (Hidden) -->
		<script type="text/template" id="tmpl-wc-pasc-group">
			<?php $this->render_group_html( '{{group_index}}', array() ); ?>
		</script>

		<!-- Template for New Option (Hidden) -->
		<script type="text/template" id="tmpl-wc-pasc-option">
			<?php $this->render_option_row_html( '{{group_index}}', '{{option_index}}', array() ); ?>
		</script>
		<?php
	}

	/**
	 * Render single Pack Size Row HTML
	 */
	public function render_pack_row_html( $p_idx, $pack = array() ) {
		$name       = isset( $pack['name'] ) ? $pack['name'] : 'Box of';
		$pieces     = isset( $pack['pieces'] ) && '' !== $pack['pieces'] ? $pack['pieces'] : '';
		$price      = isset( $pack['price'] ) && '' !== $pack['price'] ? $pack['price'] : '';
		$is_default = ! empty( $pack['default'] ) ? true : false;
		?>
		<tr class="wc-pasc-pack-row" data-pack-index="<?php echo esc_attr( $p_idx ); ?>">
			<td class="col-sort">
				<span class="dashicons dashicons-menu"></span>
			</td>
			<td class="col-pack-name">
				<input type="text" name="wc_pasc_pack_sizes[<?php echo esc_attr( $p_idx ); ?>][name]" class="wc-pasc-table-input" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Box of, Pack of, Tin of', 'wc-product-addons-sidecard' ); ?>" required />
			</td>
			<td class="col-pack-pieces">
				<input type="number" min="1" step="1" name="wc_pasc_pack_sizes[<?php echo esc_attr( $p_idx ); ?>][pieces]" class="wc-pasc-table-input" value="<?php echo esc_attr( $pieces ); ?>" placeholder="<?php esc_attr_e( 'e.g. 9, 12, 15', 'wc-product-addons-sidecard' ); ?>" required />
			</td>
			<td class="col-pack-price">
				<div class="wc-pasc-price-input-wrapper">
					<span class="wc-pasc-currency-prefix"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
					<input type="number" step="0.01" min="0" name="wc_pasc_pack_sizes[<?php echo esc_attr( $p_idx ); ?>][price]" class="wc-pasc-table-input wc-pasc-price-field" value="<?php echo esc_attr( $price ); ?>" placeholder="0.00" required />
				</div>
			</td>
			<td class="col-default">
				<label class="wc-pasc-table-checkbox-wrap" title="<?php esc_attr_e( 'Set as default selected pack', 'wc-product-addons-sidecard' ); ?>">
					<input type="checkbox" name="wc_pasc_pack_sizes[<?php echo esc_attr( $p_idx ); ?>][default]" class="wc-pasc-checkbox wc-pasc-pack-default-checkbox" value="1" <?php checked( $is_default, true ); ?> />
				</label>
			</td>
			<td class="col-action">
				<button type="button" class="button-link wc-pasc-remove-pack-btn" title="<?php esc_attr_e( 'Delete Pack Size', 'wc-product-addons-sidecard' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a single group HTML
	 */
	public function render_group_html( $group_idx, $group = array() ) {
		$title       = isset( $group['title'] ) ? $group['title'] : '';
		$subtitle    = isset( $group['subtitle'] ) ? $group['subtitle'] : '';
		$type        = isset( $group['type'] ) ? $group['type'] : 'checkbox';
		$min_limit   = isset( $group['min_limit'] ) ? intval( $group['min_limit'] ) : 0;
		$max_limit   = isset( $group['max_limit'] ) ? intval( $group['max_limit'] ) : 3;
		$required    = ! empty( $group['required'] ) ? true : false;
		$options     = isset( $group['options'] ) && is_array( $group['options'] ) ? $group['options'] : array();
		?>
		<div class="wc-pasc-group-card" data-group-index="<?php echo esc_attr( $group_idx ); ?>">
			<div class="wc-pasc-group-header">
				<div class="wc-pasc-group-handle">
					<span class="dashicons dashicons-menu"></span>
					<span class="wc-pasc-group-title-preview">
						<?php echo ! empty( $title ) ? esc_html( $title ) : esc_html__( 'Untitled Group (e.g. Choose Flavours)', 'wc-product-addons-sidecard' ); ?>
					</span>
					<span class="wc-pasc-group-limit-badge">
						<?php
						if ( 'radio' === $type ) {
							esc_html_e( 'Single Select (1)', 'wc-product-addons-sidecard' );
						} elseif ( $max_limit > 0 ) {
							/* translators: %d: maximum limit */
							printf( esc_html__( 'Max %d selections', 'wc-product-addons-sidecard' ), esc_html( $max_limit ) );
						} else {
							esc_html_e( 'Unlimited selections', 'wc-product-addons-sidecard' );
						}
						?>
					</span>
				</div>
				<div class="wc-pasc-group-actions">
					<button type="button" class="button wc-pasc-toggle-group-btn" title="<?php esc_attr_e( 'Toggle Expand/Collapse', 'wc-product-addons-sidecard' ); ?>">
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<button type="button" class="button wc-pasc-remove-group-btn" title="<?php esc_attr_e( 'Remove Group', 'wc-product-addons-sidecard' ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
			</div>

			<div class="wc-pasc-group-body">
				<!-- Group Form Grid -->
				<div class="wc-pasc-form-grid">
					
					<div class="wc-pasc-form-col wc-pasc-col-6">
						<label class="wc-pasc-label" for="wc_pasc_title_<?php echo esc_attr( $group_idx ); ?>">
							<?php esc_html_e( 'Group Title / Heading', 'wc-product-addons-sidecard' ); ?> <span class="wc-pasc-required-indicator">*</span>
						</label>
						<input type="text" id="wc_pasc_title_<?php echo esc_attr( $group_idx ); ?>" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][title]" class="wc-pasc-input wc-pasc-input-group-title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'e.g. Choose Your Flavours', 'wc-product-addons-sidecard' ); ?>" required />
					</div>

					<div class="wc-pasc-form-col wc-pasc-col-6">
						<label class="wc-pasc-label" for="wc_pasc_subtitle_<?php echo esc_attr( $group_idx ); ?>">
							<?php esc_html_e( 'Subtitle / Helper Text', 'wc-product-addons-sidecard' ); ?>
						</label>
						<input type="text" id="wc_pasc_subtitle_<?php echo esc_attr( $group_idx ); ?>" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][subtitle]" class="wc-pasc-input" value="<?php echo esc_attr( $subtitle ); ?>" placeholder="<?php esc_attr_e( 'e.g. Select up to 3 flavours for your box', 'wc-product-addons-sidecard' ); ?>" />
					</div>

					<div class="wc-pasc-form-col wc-pasc-col-4">
						<label class="wc-pasc-label" for="wc_pasc_type_<?php echo esc_attr( $group_idx ); ?>">
							<?php esc_html_e( 'Selection Type', 'wc-product-addons-sidecard' ); ?>
						</label>
						<select id="wc_pasc_type_<?php echo esc_attr( $group_idx ); ?>" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][type]" class="wc-pasc-select wc-pasc-select-type">
							<option value="checkbox" <?php selected( $type, 'checkbox' ); ?>><?php esc_html_e( 'Multiple Choice (Checkboxes)', 'wc-product-addons-sidecard' ); ?></option>
							<option value="radio" <?php selected( $type, 'radio' ); ?>><?php esc_html_e( 'Single Choice (Radio Buttons)', 'wc-product-addons-sidecard' ); ?></option>
						</select>
					</div>

					<div class="wc-pasc-form-col wc-pasc-col-4 wc-pasc-max-limit-field" style="<?php echo ( 'radio' === $type ) ? 'display:none;' : ''; ?>">
						<label class="wc-pasc-label" for="wc_pasc_max_<?php echo esc_attr( $group_idx ); ?>">
							<?php esc_html_e( 'Maximum Selection Limit', 'wc-product-addons-sidecard' ); ?>
						</label>
						<input type="number" id="wc_pasc_max_<?php echo esc_attr( $group_idx ); ?>" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][max_limit]" class="wc-pasc-input wc-pasc-input-max-limit" value="<?php echo esc_attr( $max_limit ); ?>" min="1" max="99" step="1" />
						<span class="wc-pasc-help-text"><?php esc_html_e( 'e.g. 3 for "2/3 selected" rule.', 'wc-product-addons-sidecard' ); ?></span>
					</div>

					<div class="wc-pasc-form-col wc-pasc-col-4">
						<label class="wc-pasc-label" for="wc_pasc_min_<?php echo esc_attr( $group_idx ); ?>">
							<?php esc_html_e( 'Minimum Required', 'wc-product-addons-sidecard' ); ?>
						</label>
						<input type="number" id="wc_pasc_min_<?php echo esc_attr( $group_idx ); ?>" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][min_limit]" class="wc-pasc-input" value="<?php echo esc_attr( $min_limit ); ?>" min="0" max="99" step="1" />
						<span class="wc-pasc-help-text"><?php esc_html_e( '0 = Optional, 1+ = Required.', 'wc-product-addons-sidecard' ); ?></span>
					</div>

					<div class="wc-pasc-form-col wc-pasc-col-12 wc-pasc-checkbox-col">
						<label class="wc-pasc-checkbox-label" for="wc_pasc_req_<?php echo esc_attr( $group_idx ); ?>">
							<input type="checkbox" id="wc_pasc_req_<?php echo esc_attr( $group_idx ); ?>" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][required]" class="wc-pasc-checkbox" value="1" <?php checked( $required, true ); ?> />
							<span class="wc-pasc-checkbox-text"><?php esc_html_e( 'Mark as Required Group (Customer must select at least one option)', 'wc-product-addons-sidecard' ); ?></span>
						</label>
					</div>

				</div>

				<!-- Options Table Section -->
				<div class="wc-pasc-options-section">
					<div class="wc-pasc-options-header">
						<div class="wc-pasc-options-heading-wrap">
							<span class="dashicons dashicons-list-view"></span>
							<h4 class="wc-pasc-options-title"><?php esc_html_e( 'Group Options & Flavours', 'wc-product-addons-sidecard' ); ?></h4>
						</div>
						<button type="button" class="button button-secondary wc-pasc-add-option-btn">
							<span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Add Option', 'wc-product-addons-sidecard' ); ?>
						</button>
					</div>

					<div class="wc-pasc-table-responsive">
						<table class="wc-pasc-options-table">
							<thead>
								<tr>
									<th class="col-sort" width="36" title="<?php esc_attr_e( 'Drag to reorder', 'wc-product-addons-sidecard' ); ?>"></th>
									<th class="col-name"><?php esc_html_e( 'Option Name *', 'wc-product-addons-sidecard' ); ?></th>
									<th class="col-price" width="140"><?php esc_html_e( 'Price (+)', 'wc-product-addons-sidecard' ); ?></th>
									<th class="col-desc"><?php esc_html_e( 'Badge / Description', 'wc-product-addons-sidecard' ); ?></th>
									<th class="col-default" width="70"><?php esc_html_e( 'Default', 'wc-product-addons-sidecard' ); ?></th>
									<th class="col-action" width="40"></th>
								</tr>
							</thead>
							<tbody class="wc-pasc-options-list">
								<?php
								if ( ! empty( $options ) ) {
									foreach ( $options as $opt_idx => $option ) {
										$this->render_option_row_html( $group_idx, $opt_idx, $option );
									}
								}
								?>
							</tbody>
						</table>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render single option row HTML
	 */
	public function render_option_row_html( $group_idx, $opt_idx, $option = array() ) {
		$name        = isset( $option['name'] ) ? $option['name'] : '';
		$price       = isset( $option['price'] ) ? $option['price'] : '';
		$description = isset( $option['description'] ) ? $option['description'] : '';
		$is_default  = ! empty( $option['default'] ) ? true : false;
		?>
		<tr class="wc-pasc-option-row" data-option-index="<?php echo esc_attr( $opt_idx ); ?>">
			<td class="col-sort">
				<span class="dashicons dashicons-menu"></span>
			</td>
			<td class="col-name">
				<input type="text" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][options][<?php echo esc_attr( $opt_idx ); ?>][name]" class="wc-pasc-table-input" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Belgian Chocolate, Salted Caramel', 'wc-product-addons-sidecard' ); ?>" required />
			</td>
			<td class="col-price">
				<div class="wc-pasc-price-input-wrapper">
					<span class="wc-pasc-currency-prefix"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
					<input type="number" step="0.01" min="0" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][options][<?php echo esc_attr( $opt_idx ); ?>][price]" class="wc-pasc-table-input wc-pasc-price-field" value="<?php echo esc_attr( $price ); ?>" placeholder="0.00" />
				</div>
			</td>
			<td class="col-desc">
				<input type="text" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][options][<?php echo esc_attr( $opt_idx ); ?>][description]" class="wc-pasc-table-input" value="<?php echo esc_attr( $description ); ?>" placeholder="<?php esc_attr_e( 'e.g. Gluten-free, Chef Special', 'wc-product-addons-sidecard' ); ?>" />
			</td>
			<td class="col-default">
				<label class="wc-pasc-table-checkbox-wrap" title="<?php esc_attr_e( 'Selected by default', 'wc-product-addons-sidecard' ); ?>">
					<input type="checkbox" name="wc_pasc_data[<?php echo esc_attr( $group_idx ); ?>][options][<?php echo esc_attr( $opt_idx ); ?>][default]" class="wc-pasc-checkbox" value="1" <?php checked( $is_default, true ); ?> />
				</label>
			</td>
			<td class="col-action">
				<button type="button" class="button-link wc-pasc-remove-option-btn" title="<?php esc_attr_e( 'Delete Option', 'wc-product-addons-sidecard' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save Product Meta
	 */
	public function save_product_meta( $product_id ) {
		// Enabled checkbox
		$enabled = isset( $_POST['_wc_pasc_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_wc_pasc_enabled', $enabled );

		// Box / Pack Quantity Enabled
		$box_qty_enabled = isset( $_POST['_wc_pasc_box_qty_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_wc_pasc_box_qty_enabled', $box_qty_enabled );

		// Dynamic Pack Sizes saving
		if ( isset( $_POST['wc_pasc_pack_sizes'] ) && is_array( $_POST['wc_pasc_pack_sizes'] ) ) {
			$clean_packs = array();

			foreach ( $_POST['wc_pasc_pack_sizes'] as $raw_pack ) {
				$p_name   = isset( $raw_pack['name'] ) ? sanitize_text_field( wp_unslash( $raw_pack['name'] ) ) : '';
				$p_pieces = isset( $raw_pack['pieces'] ) && '' !== $raw_pack['pieces'] ? max( 1, intval( $raw_pack['pieces'] ) ) : 1;
				$p_price  = isset( $raw_pack['price'] ) && '' !== $raw_pack['price'] ? floatval( $raw_pack['price'] ) : 0.00;
				$p_def    = ! empty( $raw_pack['default'] ) ? 1 : 0;

				if ( empty( $p_name ) && $p_pieces <= 0 ) {
					continue;
				}

				$clean_packs[] = array(
					'name'    => $p_name,
					'pieces'  => $p_pieces,
					'price'   => $p_price,
					'default' => $p_def,
				);
			}

			update_post_meta( $product_id, '_wc_pasc_pack_sizes', $clean_packs );
		} else {
			delete_post_meta( $product_id, '_wc_pasc_pack_sizes' );
		}

		// Legacy Box options backup
		if ( isset( $_POST['_wc_pasc_box_options'] ) ) {
			update_post_meta( $product_id, '_wc_pasc_box_options', sanitize_textarea_field( wp_unslash( $_POST['_wc_pasc_box_options'] ) ) );
		}

		// Add-ons data sanitization
		if ( isset( $_POST['wc_pasc_data'] ) && is_array( $_POST['wc_pasc_data'] ) ) {
			$clean_groups = array();

			foreach ( $_POST['wc_pasc_data'] as $raw_group ) {
				$title = isset( $raw_group['title'] ) ? sanitize_text_field( wp_unslash( $raw_group['title'] ) ) : '';
				if ( empty( $title ) ) {
					continue;
				}

				$subtitle  = isset( $raw_group['subtitle'] ) ? sanitize_text_field( wp_unslash( $raw_group['subtitle'] ) ) : '';
				$type      = isset( $raw_group['type'] ) && in_array( $raw_group['type'], array( 'checkbox', 'radio' ), true ) ? $raw_group['type'] : 'checkbox';
				$min_limit = isset( $raw_group['min_limit'] ) ? max( 0, intval( $raw_group['min_limit'] ) ) : 0;
				$max_limit = isset( $raw_group['max_limit'] ) ? max( 1, intval( $raw_group['max_limit'] ) ) : ( 'radio' === $type ? 1 : 3 );
				$required  = ! empty( $raw_group['required'] ) ? 1 : 0;

				$clean_options = array();
				if ( isset( $raw_group['options'] ) && is_array( $raw_group['options'] ) ) {
					foreach ( $raw_group['options'] as $raw_opt ) {
						$opt_name = isset( $raw_opt['name'] ) ? sanitize_text_field( wp_unslash( $raw_opt['name'] ) ) : '';
						if ( empty( $opt_name ) ) {
							continue;
						}
						$opt_price = isset( $raw_opt['price'] ) && '' !== $raw_opt['price'] ? floatval( $raw_opt['price'] ) : 0.00;
						$opt_desc  = isset( $raw_opt['description'] ) ? sanitize_text_field( wp_unslash( $raw_opt['description'] ) ) : '';
						$opt_def   = ! empty( $raw_opt['default'] ) ? 1 : 0;

						$clean_options[] = array(
							'name'        => $opt_name,
							'price'       => $opt_price,
							'description' => $opt_desc,
							'default'     => $opt_def,
						);
					}
				}

				$clean_groups[] = array(
					'title'     => $title,
					'subtitle'  => $subtitle,
					'type'      => $type,
					'min_limit' => $min_limit,
					'max_limit' => $max_limit,
					'required'  => $required,
					'options'   => $clean_options,
				);
			}

			update_post_meta( $product_id, '_wc_pasc_data', $clean_groups );
		} else {
			delete_post_meta( $product_id, '_wc_pasc_data' );
		}
	}
}
