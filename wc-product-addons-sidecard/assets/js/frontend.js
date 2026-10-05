/**
 * Frontend JavaScript for WooCommerce Product Add-ons & Side Card
 * Handles Drawer transitions, Live Counters, Limit Enforcement, and Direct Side Cart Sync
 */

(function($) {
	'use strict';

	var WCPascFrontend = {
		init: function() {
			this.$overlay   = $('#wc-pasc-overlay');
			this.$sidecard  = $('#wc-pasc-sidecard');
			this.$container = this.$sidecard.find('.wc-pasc-content-container');
			this.$loader    = this.$sidecard.find('.wc-pasc-loader');
			this.currentProductId = null;

			this.bindEvents();
		},

		bindEvents: function() {
			var self = this;

			// Intercept Customize / Add button
			$(document).on('click', '.wc-pasc-open-sidecard-btn', function(e) {
				e.preventDefault();
				e.stopPropagation();

				var productId = $(this).data('product_id') || $(this).data('product-id') || $(this).attr('data-product_id');
				if (productId) {
					self.openDrawer(productId);
				}
			});

			// Close Drawer Button & Overlay
			$(document).on('click', '.wc-pasc-close-btn, #wc-pasc-overlay', function(e) {
				e.preventDefault();
				self.closeDrawer();
			});

			// ESC key close
			$(document).on('keydown', function(e) {
				if (e.key === 'Escape' && self.$sidecard.hasClass('is-open')) {
					self.closeDrawer();
				}
			});

			// Addon checkbox/radio toggle in product modal drawer
			$(document).on('change', '.wc-pasc-addon-group input', function() {
				var $group = $(this).closest('.wc-pasc-addon-group');
				self.handleOptionChange($group, $(this));
				self.updateTotalPrice();
			});

			// Box Preset Selection in product modal drawer
			$(document).on('change', '.wc-pasc-box-section input[name="wc_pasc_box_option"]', function() {
				$('.wc-pasc-box-card').removeClass('is-selected');
				$(this).closest('.wc-pasc-box-card').addClass('is-selected');
				self.updateTotalPrice();
			});

			// Quantity Stepper in product modal drawer
			$(document).on('click', '.wc-pasc-qty-minus', function(e) {
				e.preventDefault();
				var $input = $(this).siblings('.wc-pasc-qty-input');
				var val = parseInt($input.val(), 10) || 1;
				if (val > 1) {
					$input.val(val - 1).trigger('change');
				}
			});

			$(document).on('click', '.wc-pasc-qty-plus', function(e) {
				e.preventDefault();
				var $input = $(this).siblings('.wc-pasc-qty-input');
				var val = parseInt($input.val(), 10) || 1;
				if (val < 99) {
					$input.val(val + 1).trigger('change');
				}
			});

			$(document).on('change input', '.wc-pasc-qty-input', function() {
				var val = parseInt($(this).val(), 10) || 1;
				if (val < 1) val = 1;
				if (val > 99) val = 99;
				$(this).val(val);
				self.updateTotalPrice();
			});

			// Submit Form (AJAX Add to Cart from product drawer)
			$(document).on('submit', '#wc-pasc-form', function(e) {
				e.preventDefault();
				self.submitAddToCart($(this));
			});

			// =========================================================================
			// SIDE CART WOOCOMMERCE DIRECT SELECTION & DYNAMIC SYNC
			// =========================================================================

			// 1. Box / Pack preset change inside Side Cart (Strict Single Selection)
			$(document).on('click', '.wc-pasc-sidecart-widget .wc-pasc-sc-box-pill', function(e) {
				if ($(e.target).is('input')) {
					return;
				}
				e.preventDefault();

				var $pill   = $(this);
				var $input  = $pill.find('input.wc-pasc-sc-box-input');
				var $widget = $pill.closest('.wc-pasc-sidecart-widget');

				if ($pill.hasClass('is-selected') && $input.prop('checked')) {
					return;
				}

				// Deselect all other pack pills in this cart item
				$widget.find('.wc-pasc-sc-box-pill').removeClass('is-selected');
				$widget.find('input.wc-pasc-sc-box-input').prop('checked', false);

				// Select only this pill
				$pill.addClass('is-selected');
				$input.prop('checked', true);

				self.syncSideCartItem($widget);
			});

			$(document).on('change', '.wc-pasc-sidecart-widget input.wc-pasc-sc-box-input', function(e) {
				var $input  = $(this);
				var $pill   = $input.closest('.wc-pasc-sc-box-pill');
				var $widget = $input.closest('.wc-pasc-sidecart-widget');

				$widget.find('.wc-pasc-sc-box-pill').removeClass('is-selected');
				$widget.find('input.wc-pasc-sc-box-input').not($input).prop('checked', false);
				$pill.addClass('is-selected');
				$input.prop('checked', true);

				self.syncSideCartItem($widget);
			});

			// 2. Addon / Flavour option change inside Side Cart
			$(document).on('change', '.wc-pasc-sidecart-widget input.wc-pasc-sc-input', function(e) {
				var $input    = $(this);
				var $widget   = $input.closest('.wc-pasc-sidecart-widget');
				var $group    = $input.closest('.wc-pasc-sc-group');
				var type      = $group.data('type');
				var maxLimit  = parseInt($group.data('max-limit'), 10) || 99;

				// Checked count for this group
				var checkedInputs = $group.find('input.wc-pasc-sc-input:checked');
				var count         = checkedInputs.length;

				// Toggle checked classes and enforce selection limits
				if (type === 'radio') {
					$group.find('.wc-pasc-sc-option-item').removeClass('is-checked');
					if ($input.is(':checked')) {
						$input.closest('.wc-pasc-sc-option-item').addClass('is-checked');
					}
				} else {
					if ($input.is(':checked')) {
						$input.closest('.wc-pasc-sc-option-item').addClass('is-checked');
					} else {
						$input.closest('.wc-pasc-sc-option-item').removeClass('is-checked');
					}

					var $counter = $group.find('.wc-pasc-sc-counter');
					if ($counter.length) {
						$counter.find('.wc-pasc-sc-cur-count').text(count);
						if (count >= maxLimit) {
							$counter.addClass('is-full');
							$group.find('input.wc-pasc-sc-input:not(:checked)')
								.prop('disabled', true)
								.closest('.wc-pasc-sc-option-item').addClass('is-disabled');
						} else {
							$counter.removeClass('is-full');
							$group.find('input.wc-pasc-sc-input')
								.prop('disabled', false)
								.closest('.wc-pasc-sc-option-item').removeClass('is-disabled');
						}
					}
				}

				self.syncSideCartItem($widget);
			});

			// Hide quantity steppers on side cart items whenever fragments refresh
			$(document).on('wc_fragments_refreshed wc_fragment_refresh added_to_cart xoo_wsc_cart_updated', function() {
				self.hideSideCartQtyBoxes();
			});
			$(window).on('load', function() {
				self.hideSideCartQtyBoxes();
			});
		},

		hideSideCartQtyBoxes: function() {
			$('.wc-pasc-sidecart-widget.wc-pasc-has-pack-sizes, .wc-pasc-sidecart-widget:has(.wc-pasc-sc-box-section)').each(function() {
				var $parent = $(this).closest('.xoo-wsc-product, .xoo-wsc-product-card, .xoo-wsc-p-cont, .xoo-wsc-item, .woocommerce-mini-cart-item, tr.cart_item');
				if ($parent.length) {
					$parent.find('.xoo-wsc-qty-box, .xoo-wsc-qty-box-cont, .xoo-wsc-qty-price, .quantity').hide();
				}
			});
		},

		syncSideCartItem: function($widget) {
			var self = this;
			var cartItemKey = $widget.data('cart-key');

			// Collect selected box preset (strictly single selected)
			var $checkedBox = $widget.find('input.wc-pasc-sc-box-input:checked');
			var selectedBox = $checkedBox.length ? $checkedBox.val() : '';

			// Collect all selections for this cart item
			var selectedAddons = {};
			$widget.find('.wc-pasc-sc-group').each(function() {
				var gId      = $(this).data('group-id');
				var gType    = $(this).data('type');
				var gChecked = $(this).find('input.wc-pasc-sc-input:checked');

				if (gChecked.length) {
					if (gType === 'radio') {
						selectedAddons[gId] = gChecked.first().val();
					} else {
						selectedAddons[gId] = [];
						gChecked.each(function() {
							selectedAddons[gId].push($(this).val());
						});
					}
				}
			});

			// Show subtle loading overlay
			$widget.find('.wc-pasc-sc-loader-overlay').fadeIn(100);

			// Send AJAX update to cart session
			$.ajax({
				url: wcPascData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'wc_pasc_update_cart_item_addons',
					cart_item_key: cartItemKey,
					wc_pasc_box_option: selectedBox,
					wc_pasc_addons: selectedAddons,
					nonce: wcPascData.nonce
				},
				success: function(response) {
					$widget.find('.wc-pasc-sc-loader-overlay').fadeOut(100);

					if (response && (response.fragments || response.cart_hash)) {
						// Trigger Side Cart refresh
						$(document.body).trigger('wc_fragments_refreshed');
						$(document.body).trigger('wc_fragment_refresh');
						$(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash]);
						self.hideSideCartQtyBoxes();
					}
				},
				error: function() {
					$widget.find('.wc-pasc-sc-loader-overlay').fadeOut(100);
				}
			});
		},

		openDrawer: function(productId) {
			var self = this;
			this.currentProductId = productId;

			// Show Drawer & Overlay
			this.$overlay.addClass('is-active').attr('aria-hidden', 'false');
			this.$sidecard.addClass('is-open').attr('aria-hidden', 'false');
			$('body').css('overflow', 'hidden'); // Lock background scroll

			// Show loader
			this.$container.empty();
			this.$loader.show();

			// AJAX Fetch
			$.ajax({
				url: wcPascData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'wc_pasc_get_sidecard',
					product_id: productId,
					nonce: wcPascData.nonce
				},
				success: function(response) {
					self.$loader.hide();
					if (response.success && response.data.html) {
						self.$container.html(response.data.html);
						self.initLoadedDrawer();
					} else {
						self.$container.html('<div class="wc-pasc-error-msg" style="padding:20px;text-align:center;color:#ef4444;">' + (response.data.message || 'Error loading product details.') + '</div>');
					}
				},
				error: function() {
					self.$loader.hide();
					self.$container.html('<div class="wc-pasc-error-msg" style="padding:20px;text-align:center;color:#ef4444;">Network error. Please try again.</div>');
				}
			});
		},

		closeDrawer: function() {
			this.$overlay.removeClass('is-active').attr('aria-hidden', 'true');
			this.$sidecard.removeClass('is-open').attr('aria-hidden', 'true');
			$('body').css('overflow', '');
		},

		initLoadedDrawer: function() {
			var self = this;

			// Initialize counters and disabled states for all addon groups
			$('.wc-pasc-addon-group').each(function() {
				self.updateGroupState($(this));
			});

			// Initial price calculation
			this.updateTotalPrice();
		},

		handleOptionChange: function($group, $changedInput) {
			var type = $group.data('type');

			if (type === 'radio') {
				$group.find('.wc-pasc-option-item').removeClass('is-checked');
				if ($changedInput.is(':checked')) {
					$changedInput.closest('.wc-pasc-option-item').addClass('is-checked');
				}
			} else {
				if ($changedInput.is(':checked')) {
					$changedInput.closest('.wc-pasc-option-item').addClass('is-checked');
				} else {
					$changedInput.closest('.wc-pasc-option-item').removeClass('is-checked');
				}
			}

			this.updateGroupState($group);
		},

		updateGroupState: function($group) {
			var type = $group.data('type');
			var maxLimit = parseInt($group.data('max-limit'), 10) || 99;
			var checkedInputs = $group.find('.wc-pasc-options-container input:checked');
			var count = checkedInputs.length;

			// Update live counter badge (e.g. 2 / 3 selected)
			var $badge = $group.find('.wc-pasc-counter-badge');
			if ($badge.length) {
				$badge.find('.wc-pasc-current-count').text(count);
				$badge.find('.wc-pasc-max-count').text(maxLimit);

				if (count >= maxLimit) {
					$badge.addClass('is-full');
				} else {
					$badge.removeClass('is-full');
				}
			}

			// Enforce Maximum Selection Limit
			if (type === 'checkbox') {
				var $unchecked = $group.find('.wc-pasc-options-container input:not(:checked)');

				if (count >= maxLimit) {
					// Disable unchecked options
					$unchecked.prop('disabled', true);
					$unchecked.closest('.wc-pasc-option-item').addClass('is-disabled');
				} else {
					// Enable all options
					$unchecked.prop('disabled', false);
					$unchecked.closest('.wc-pasc-option-item').removeClass('is-disabled');
				}
			}
		},

		updateTotalPrice: function() {
			var $form = $('#wc-pasc-form');
			if (!$form.length) return;

			var basePrice = parseFloat($form.data('base-price')) || 0;
			var quantity = parseInt($form.find('.wc-pasc-qty-input').val(), 10) || 1;

			// Pack / Box base price
			var itemBasePrice = basePrice;
			var $selectedBox = $form.find('input[name="wc_pasc_box_option"]:checked');
			if ($selectedBox.length) {
				var customPrice = parseFloat($selectedBox.data('price'));
				if (!isNaN(customPrice) && customPrice > 0) {
					itemBasePrice = customPrice;
				} else {
					var boxMultiplier = parseInt($selectedBox.data('multiplier'), 10) || 1;
					itemBasePrice = basePrice * boxMultiplier;
				}
			}

			// Add-on extras
			var addonsExtra = 0;
			$form.find('.wc-pasc-options-container input:checked').each(function() {
				var price = parseFloat($(this).data('price')) || 0;
				addonsExtra += price;
			});

			var total = (itemBasePrice + addonsExtra) * quantity;
			var formattedTotal = this.formatPrice(total);

			$('#wc-pasc-total-display').html(formattedTotal);
		},

		formatPrice: function(amount) {
			var decimals = wcPascData.decimals || 2;
			var decSep = wcPascData.decimalSep || '.';
			var thouSep = wcPascData.thousandSep || ',';
			var symbol = wcPascData.currencySymbol || '$';
			var pos = wcPascData.currencyPos || 'left';

			var n = amount.toFixed(decimals);
			var parts = n.split('.');
			parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSep);
			var formattedNum = parts.join(decSep);

			switch (pos) {
				case 'left_space':
					return symbol + ' ' + formattedNum;
				case 'right':
					return formattedNum + symbol;
				case 'right_space':
					return formattedNum + ' ' + symbol;
				case 'left':
				default:
					return symbol + formattedNum;
			}
		},

		submitAddToCart: function($form) {
			var self = this;
			var $btn = $('#wc-pasc-add-btn');

			// Client-side minimum selection validation
			var validationFailed = false;
			var firstErrorGroup = null;

			$('.wc-pasc-addon-group').each(function() {
				var isRequired = $(this).data('required') == '1';
				var minLimit = parseInt($(this).data('min-limit'), 10) || 0;
				var checkedCount = $(this).find('.wc-pasc-options-container input:checked').length;
				var requiredMin = Math.max(isRequired ? 1 : 0, minLimit);

				if (requiredMin > 0 && checkedCount < requiredMin) {
					validationFailed = true;
					if (!firstErrorGroup) {
						firstErrorGroup = $(this);
					}
					$(this).css('animation', 'none');
					setTimeout(function() {
						firstErrorGroup.css('animation', 'wcPascShake 0.4s ease');
					}, 10);
				}
			});

			if (validationFailed && firstErrorGroup) {
				var groupTitle = firstErrorGroup.find('.wc-pasc-group-heading').text().trim();
				alert(wcPascData.i18n.requiredNotice + ' (' + groupTitle + ')');
				return;
			}

			// Loading state
			$btn.prop('disabled', true);
			$btn.find('.wc-pasc-btn-text').hide();
			$btn.find('.wc-pasc-btn-loader').show();

			var formData = $form.serializeArray();
			formData.push({ name: 'action', value: 'wc_pasc_ajax_add_to_cart' });
			formData.push({ name: 'nonce', value: wcPascData.nonce });

			$.ajax({
				url: wcPascData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: formData,
				success: function(response) {
					$btn.prop('disabled', false);
					$btn.find('.wc-pasc-btn-loader').hide();
					$btn.find('.wc-pasc-btn-text').show();

					if (response.fragments || response.cart_hash) {
						// Close drawer
						self.closeDrawer();

						// Trigger WooCommerce & Side Cart events
						$(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
						$(document.body).trigger('wc_fragments_refreshed');
						$(document.body).trigger('wc_fragment_refresh');
					} else if (response.error || response.message) {
						alert(response.message || 'Could not add to cart.');
					} else {
						// Fallback refresh
						self.closeDrawer();
						$(document.body).trigger('added_to_cart', [{}, '', $btn]);
					}
				},
				error: function(xhr) {
					$btn.prop('disabled', false);
					$btn.find('.wc-pasc-btn-loader').hide();
					$btn.find('.wc-pasc-btn-text').show();
					alert('Network error. Please try again.');
				}
			});
		}
	};

	$(document).ready(function() {
		WCPascFrontend.init();
	});

})(jQuery);
