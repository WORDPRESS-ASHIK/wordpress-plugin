/**
 * Frontend JavaScript for WooCommerce Product Add-ons & Customization Popup
 * Robust Add-to-Cart Interception, Modal Customizer & Responsive Interactions
 */

(function($) {
	'use strict';

	var WCPascFrontend = {
		init: function() {
			this.$overlay   = $('#wc-pasc-overlay');
			this.$modal     = $('#wc-pasc-sidecard');
			this.$container = this.$modal.find('.wc-pasc-content-container');
			this.$loader    = this.$modal.find('.wc-pasc-loader');
			this.currentProductId = null;

			this.bindCaptureInterceptor();
			this.bindEvents();
			this.bindFallbackAddToCartHandler();
		},

		/**
		 * Helper: Check if a product ID has Pack Size and/or Flavours customization configured
		 */
		isCustomizedProduct: function(productId) {
			if (!productId) return false;
			var id = parseInt(productId, 10);
			if (isNaN(id) || id <= 0) return false;

			if (window.wcPascData && Array.isArray(window.wcPascData.customizedProductIds)) {
				for (var i = 0; i < window.wcPascData.customizedProductIds.length; i++) {
					if (parseInt(window.wcPascData.customizedProductIds[i], 10) === id) {
						return true;
					}
				}
			}
			return false;
		},

		/**
		 * Helper: Extract Product ID from any button, link, or container element
		 */
		resolveProductId: function(element) {
			if (!element) return null;
			var $el = $(element);

			// 1. Direct data attributes on the button
			var pId = $el.data('product_id') || $el.data('product-id') || $el.attr('data-product_id') || $el.attr('data-product-id');
			if (pId && parseInt(pId, 10) > 0) {
				return parseInt(pId, 10);
			}

			// 1b. Check nearest ancestors for product IDs (Elementor/custom cards)
			var $ancestor = $el.closest('[data-product-id], [data-product_id], [data-productid], .product, li.product');
			if ($ancestor.length) {
				var ancestorId = $ancestor.attr('data-product-id') || $ancestor.attr('data-product_id') || $ancestor.attr('data-productid') || $ancestor.data('product-id') || $ancestor.data('product_id');
				if (ancestorId && parseInt(ancestorId, 10) > 0) {
					return parseInt(ancestorId, 10);
				}
			}

			// 2. Check form.cart if clicked inside a product form
			var $form = $el.closest('form.cart');
			if (!$form.length && $('form.cart').length === 1) {
				$form = $('form.cart');
			}
			if ($form.length) {
				var varId = $form.find('input[name="variation_id"]').val();
				if (varId && parseInt(varId, 10) > 0) {
					return parseInt(varId, 10);
				}
				var flagId = $form.find('.wc-pasc-has-customization-flag').data('product_id') || $form.find('.wc-pasc-has-customization-flag').attr('data-product_id');
				if (flagId && parseInt(flagId, 10) > 0) {
					return parseInt(flagId, 10);
				}
				var addVal = $form.find('button[name="add-to-cart"]').val() || $form.find('input[name="add-to-cart"]').val() || $form.find('[name="add-to-cart"]').val();
				if (addVal && parseInt(addVal, 10) > 0) {
					return parseInt(addVal, 10);
				}
			}

			// 3. Check button value attribute directly (standard WooCommerce single add to cart button)
			if ($el.is('[name="add-to-cart"]') && $el.val() && parseInt($el.val(), 10) > 0) {
				return parseInt($el.val(), 10);
			}

			// 4. Check URL query parameters (e.g., ?add-to-cart=123)
			var href = $el.attr('href');
			if (href) {
				var match = href.match(/[?&]add-to-cart=(\d+)/i);
				if (match && match[1]) {
					return parseInt(match[1], 10);
				}
			}

			// 5. Check parent product item container
			var $parent = $el.closest('.product, .wc-block-grid__product, .elementor-product, [data-product-id]');
			if ($parent.length) {
				var contId = $parent.data('product-id') || $parent.data('product_id') || $parent.attr('data-product-id') || $parent.attr('data-product_id') || $parent.data('id');
				if (contId && parseInt(contId, 10) > 0) {
					return parseInt(contId, 10);
				}
				// Class name fallback e.g. post-123 or product-123
				var classList = $parent.attr('class') || '';
				var cMatch = classList.match(/\b(?:post|product)-(\d+)\b/);
				if (cMatch && cMatch[1]) {
					return parseInt(cMatch[1], 10);
				}
			}

			return null;
		},

		/**
		 * Native Capture-phase Click Interceptor:
		 * Executes before ANY bubbling or WooCommerce add-to-cart.js handler can add the product
		 */
		bindCaptureInterceptor: function() {
			var self = this;

			document.addEventListener('click', function(e) {
				// Don't intercept clicks inside our own customization modal
				if (e.target && (e.target.closest('#wc-pasc-sidecard') || e.target.closest('.wc-pasc-modal') || e.target.closest('#wc-pasc-form'))) {
					return;
				}

				// Find closest clickable add to cart button or link
				var targetBtn = e.target ? e.target.closest(
					'.wc-pasc-open-popup-btn, .wc-pasc-open-sidecard-btn, ' +
					'.single_add_to_cart_button, .add_to_cart_button, ' +
					'button[name="add-to-cart"], input[name="add-to-cart"], ' +
					'a[href*="add-to-cart"], .elementor-add-to-cart-button, ' +
					'form.cart button[type="submit"]'
				) : null;

				if (!targetBtn) {
					return;
				}

				var $btn = $(targetBtn);
				var productId = self.resolveProductId(targetBtn);

				// Determine if this product requires customization
				var isCustom = false;

				if ($btn.data('has-customization') == '1' || $btn.hasClass('wc-pasc-open-popup-btn') || $btn.hasClass('wc-pasc-open-sidecard-btn')) {
					isCustom = true;
				} else if ($btn.closest('form.cart').find('.wc-pasc-has-customization-flag, input[name="wc_pasc_has_customization"]').length > 0) {
					isCustom = true;
				} else if (productId && self.isCustomizedProduct(productId)) {
					isCustom = true;
				}

				if (isCustom && productId) {
					// Stop the original add to cart event immediately!
					e.preventDefault();
					e.stopPropagation();
					e.stopImmediatePropagation();

					// Remove WooCommerce loading spinner if attached
					$btn.removeClass('loading');

					// Open customization popup modal
					self.openModal(productId);
				}
			}, true); // Use capture phase!
		},

		bindEvents: function() {
			var self = this;

			// Close Modal Button & Overlay Click
			$(document).on('click', '.wc-pasc-close-btn, #wc-pasc-overlay', function(e) {
				e.preventDefault();
				self.closeModal();
			});

			// ESC key close
			$(document).on('keydown', function(e) {
				if (e.key === 'Escape' && self.$modal.hasClass('is-open')) {
					self.closeModal();
				}
			});

			// Addon Checkbox / Radio Selection inside Modal
			$(document).on('change', '.wc-pasc-addon-group input', function() {
				var $group = $(this).closest('.wc-pasc-addon-group');
				self.handleOptionChange($group, $(this));
				self.updateTotalPrice();
			});

			// Box / Pack Size Selection inside Modal (Strict Single Selection)
			$(document).on('click', '.wc-pasc-box-card', function(e) {
				var $input = $(this).find('input[name="wc_pasc_box_option"]');
				if ($input.length && !$input.prop('checked')) {
					$input.prop('checked', true).trigger('change');
				}
			});

			$(document).on('change', '.wc-pasc-box-section input[name="wc_pasc_box_option"]', function() {
				$('.wc-pasc-box-card').removeClass('is-selected');
				$(this).closest('.wc-pasc-box-card').addClass('is-selected');
				self.updateTotalPrice();
			});

			// Quantity Stepper inside Modal (when Pack Sizes not enabled)
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

			// Modal Final "Add to Cart" Form Submit
			$(document).on('submit', '#wc-pasc-form', function(e) {
				e.preventDefault();
				self.submitAddToCart($(this));
			});
		},

		openModal: function(productId) {
			var self = this;
			this.currentProductId = productId;

			if (!$('#wc-pasc-sidecard').length) {
				$('body').append(
					'<div id=\"wc-pasc-overlay\" class=\"wc-pasc-overlay\" aria-hidden=\"true\"></div>' +
					'<div id=\"wc-pasc-sidecard\" class=\"wc-pasc-sidecard wc-pasc-modal\" role=\"dialog\" aria-modal=\"true\" aria-hidden=\"true\">' +
					'<div class=\"wc-pasc-header\"><div class=\"wc-pasc-header-info\"><span class=\"wc-pasc-badge\">Customize Your Order</span><h3 class=\"wc-pasc-title\">Product Options</h3></div><button type=\"button\" class=\"wc-pasc-close-btn\" aria-label=\"Close\">×</button></div>' +
					'<div class=\"wc-pasc-body\"><div class=\"wc-pasc-loader\"><div class=\"wc-pasc-spinner\"></div><p>Loading options...</p></div><div class=\"wc-pasc-content-container\"></div></div>' +
					'</div>'
				);
			}
			this.$overlay = $('#wc-pasc-overlay');
			this.$modal = $('#wc-pasc-sidecard');
			this.$container = this.$modal.find('.wc-pasc-content-container');
			this.$loader = this.$modal.find('.wc-pasc-loader');

			// Ensure shell exists
			if (!this.$overlay.length) {
				this.$overlay = $('#wc-pasc-overlay');
			}
			if (!this.$modal.length) {
				this.$modal = $('#wc-pasc-sidecard');
				this.$container = this.$modal.find('.wc-pasc-content-container');
				this.$loader = this.$modal.find('.wc-pasc-loader');
			}

			// Show Modal & Backdrop Overlay
			this.$overlay.addClass('is-active').attr('aria-hidden', 'false');
			this.$modal.addClass('is-open').attr('aria-hidden', 'false');
			$('body').css('overflow', 'hidden'); // Prevent background page scroll

			// Show loading spinner
			this.$container.empty();
			this.$loader.show();

			// AJAX Fetch Modal content
			$.ajax({
				url: window.wcPascData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'wc_pasc_get_sidecard',
					product_id: productId,
					nonce: window.wcPascData.nonce
				},
				success: function(response) {
					self.$loader.hide();
					if (response.success && response.data && response.data.html) {
						self.$container.html(response.data.html);
						self.initLoadedModal();
					} else {
						var msg = (response.data && response.data.message) ? response.data.message : 'Error loading product options.';
						self.$container.html('<div class="wc-pasc-error-msg" style="padding:24px;text-align:center;color:#ef4444;font-weight:600;">' + msg + '</div>');
					}
				},
				error: function() {
					self.$loader.hide();
					self.$container.html('<div class="wc-pasc-error-msg" style="padding:24px;text-align:center;color:#ef4444;font-weight:600;">Network error. Please check your connection and try again.</div>');
				}
			});
		},

		closeModal: function() {
			this.$overlay.removeClass('is-active').attr('aria-hidden', 'true');
			this.$modal.removeClass('is-open').attr('aria-hidden', 'true');
			$('body').css('overflow', '');
		},

		initLoadedModal: function() {
			var self = this;

			// Initialize counters and limit states for all addon groups
			$('.wc-pasc-addon-group').each(function() {
				self.updateGroupState($(this));
			});

			// Calculate initial total price
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
			var decimals = window.wcPascData.decimals || 2;
			var decSep = window.wcPascData.decimalSep || '.';
			var thouSep = window.wcPascData.thousandSep || ',';
			var symbol = window.wcPascData.currencySymbol || '$';
			var pos = window.wcPascData.currencyPos || 'left';

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
					var $thisGroup = $(this);
					$thisGroup.css('animation', 'none');
					setTimeout(function() {
						$thisGroup.css('animation', 'wcPascShake 0.4s ease');
					}, 10);
				}
			});

			if (validationFailed && firstErrorGroup) {
				var groupTitle = firstErrorGroup.find('.wc-pasc-group-heading').text().trim();
				alert((window.wcPascData.i18n.requiredNotice || 'Please complete all required selections') + ' (' + groupTitle + ')');
				return;
			}

			// Prevent duplicate submissions
			if ($btn.prop('disabled')) {
				return;
			}

			// Loading state
			$btn.prop('disabled', true);
			$btn.find('.wc-pasc-btn-text').hide();
			$btn.find('.wc-pasc-btn-loader').show();

			var formData = $form.serializeArray();
			var hasAction = false, hasNonce = false, hasProduct = false;
			for (var i = 0; i < formData.length; i++) {
				if (formData[i].name === 'action') hasAction = true;
				if (formData[i].name === 'nonce') hasNonce = true;
				if (formData[i].name === 'product_id') hasProduct = true;
			}

			if (!hasAction) {
				formData.push({ name: 'action', value: 'wc_pasc_ajax_add_to_cart' });
			}
			if (!hasNonce) {
				formData.push({ name: 'nonce', value: window.wcPascData.nonce });
			}
			if (!hasProduct && self.currentProductId) {
				formData.push({ name: 'product_id', value: self.currentProductId });
			}

			$.ajax({
				url: window.wcPascData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: formData,
				success: function(response) {
					$btn.prop('disabled', false);
					$btn.find('.wc-pasc-btn-loader').hide();
					$btn.find('.wc-pasc-btn-text').show();

					if (response && response.success) {
						var resData   = response.data || {};
						var fragments = resData.fragments || response.fragments;
						var cart_hash = resData.cart_hash || response.cart_hash;

						// Close customization popup modal
						self.closeModal();

						// Update WooCommerce HTML fragments in page
						if (fragments) {
							$.each(fragments, function(key, value) {
								$(key).replaceWith(value);
							});
						}

						// Trigger WooCommerce & Side Cart refresh events
						$(document.body).trigger('added_to_cart', [fragments, cart_hash, $btn]);
						$(document.body).trigger('wc_fragments_refreshed');
						$(document.body).trigger('wc_fragment_refresh');
						$(document.body).trigger('xoo_wsc_cart_updated');
					} else {
						var errorMsg = (response && response.data && response.data.message)
							? response.data.message
							: ((response && response.message) ? response.message : 'Could not add product to cart. Please check your selections and try again.');
						alert(errorMsg);
					}
				},
				error: function(xhr, status, error) {
					$btn.prop('disabled', false);
					$btn.find('.wc-pasc-btn-loader').hide();
					$btn.find('.wc-pasc-btn-text').show();
					alert('Network error adding product to cart. Please try again.');
				}
			});
		}
	};

	$(document).ready(function() {
		WCPascFrontend.init();
	});

})(jQuery);

