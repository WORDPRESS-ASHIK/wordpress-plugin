(function ($) {
    'use strict';

    var formCache = {};
    var prefetchPromises = {};

    function getEndpointUrl(action) {
        if (WCSCAE.wcAjaxUrl) {
            return WCSCAE.wcAjaxUrl.replace('%%endpoint%%', action);
        }
        return WCSCAE.ajaxUrl;
    }

    function ensureModal() {
        if ($('#wcscae-modal').length) return;
        $('body').append(
            '<div id="wcscae-modal" class="wcscae-modal" aria-hidden="true">' +
                '<div class="wcscae-backdrop"></div>' +
                '<div class="wcscae-dialog" role="dialog" aria-modal="true" aria-label="' + WCSCAE.i18n.title + '">' +
                    '<button type="button" class="wcscae-x" aria-label="' + WCSCAE.i18n.close + '">×</button>' +
                    '<h3>' + WCSCAE.i18n.title + '</h3>' +
                    '<div class="wcscae-content"></div>' +
                '</div>' +
            '</div>'
        );
    }

    function openModal(title) {
        ensureModal();
        var modalTitle = title || WCSCAE.i18n.title;
        $('#wcscae-modal .wcscae-dialog > h3').text(modalTitle);
        $('#wcscae-modal .wcscae-dialog').attr('aria-label', modalTitle);
        $('#wcscae-modal').attr('aria-hidden', 'false').addClass('is-open');
        $('body').addClass('wcscae-modal-open');
    }

    function closeModal() {
        $('#wcscae-modal').attr('aria-hidden', 'true').removeClass('is-open');
        $('body').removeClass('wcscae-modal-open');
    }

    function showError(message) {
        $('.wcscae-content').html('<div class="wcscae-error">' + (message || WCSCAE.i18n.error) + '</div>');
    }

    function applyCurrentSelections(rows) {
        if (!Array.isArray(rows) || !rows.length) return;

        var $form = $('.wcscae-form');

        rows.forEach(function (row) {
            var value = row && row.value != null ? String(row.value) : '';
            var fieldName = row && row.field_name ? String(row.field_name) : '';

            $form.find('input.wc-pao-addon-field[data-label], option[data-label]').each(function () {
                var $el = $(this);
                var label = $el.attr('data-label');
                if (label === value || $('<div>').html(label || '').text() === value) {
                    if ($el.is('option')) {
                        $el.prop('selected', true).parent('select').trigger('change');
                    } else if ($el.is(':checkbox,:radio')) {
                        $el.prop('checked', true).trigger('change');
                    }
                }
            });

            if (fieldName) {
                var names = ['addon-' + fieldName, fieldName];
                names.forEach(function (name) {
                    $form.find(':input').filter(function () {
                        return this.name === name;
                    }).not(':checkbox,:radio,select').val(value);
                });
            }
        });
    }

    /* -------------------------------------------------------------
     * 1. Existing Cart Item Add-On Editing (Side Cart)
     * ------------------------------------------------------------- */
    $(document).on('click', '.xoo-wsc-container .wcscae-edit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var cartKey = $(this).data('cart-key');
        var mode = $(this).data('mode') === 'add' ? 'add' : 'edit';
        var title = mode === 'add' ? WCSCAE.i18n.addTitle : WCSCAE.i18n.title;

        openModal(title);
        $('.wcscae-content').html('<div class="wcscae-loading"><div class="wcscae-spinner"></div>' + WCSCAE.i18n.loading + '</div>');

        $.post(WCSCAE.ajaxUrl, {
            action: 'wcscae_get_form',
            nonce: WCSCAE.nonce,
            cart_key: cartKey
        }).done(function (res) {
            if (res && res.success && res.data && res.data.html) {
                $('.wcscae-content').html(res.data.html);
                applyCurrentSelections(res.data.current || []);
            } else {
                showError(res && res.data ? res.data.message : null);
            }
        }).fail(function (xhr) {
            var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : null;
            showError(msg);
        });
    });

    $(document).on('submit', '.wcscae-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.wcscae-save');
        var original = $btn.text();
        $btn.prop('disabled', true).text(WCSCAE.i18n.saving);

        $.post(WCSCAE.ajaxUrl, {
            action: 'wcscae_update_addons',
            nonce: WCSCAE.nonce,
            cart_key: $form.data('cart-key'),
            fields: $form.serialize()
        }).done(function (res) {
            if (!res || !res.success) {
                var msg = res && res.data ? res.data.message : WCSCAE.i18n.error;
                $form.prepend('<div class="wcscae-error">' + msg + '</div>');
                $btn.prop('disabled', false).text(original);
                return;
            }

            closeModal();
            $(document.body).trigger('wc_fragment_refresh');
            $(document.body).trigger('updated_cart_totals');
            $(document.body).trigger('wcscae_addons_updated', [res.data]);
        }).fail(function (xhr) {
            var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : WCSCAE.i18n.error;
            $form.find('.wcscae-error').remove();
            $form.prepend('<div class="wcscae-error">' + msg + '</div>');
            $btn.prop('disabled', false).text(original);
        });
    });

    /* -------------------------------------------------------------
     * 2. Variable Product "Select Option" Quick Modal (High Speed)
     * ------------------------------------------------------------- */
    function findMatchingVariation(variations, currentAttributes) {
        for (var i = 0; i < variations.length; i++) {
            var variation = variations[i];
            var match = true;

            for (var attrName in currentAttributes) {
                if (!currentAttributes.hasOwnProperty(attrName)) continue;
                var val = currentAttributes[attrName];
                var varVal = variation.attributes[attrName];

                if (varVal !== undefined && varVal !== '' && varVal !== val) {
                    match = false;
                    break;
                }
            }

            if (match) {
                return variation;
            }
        }
        return null;
    }

    function checkVariations($form) {
        var variationsData = $form.data('product_variations');
        if (!variationsData || !variationsData.length) return;

        var currentAttributes = {};
        var allSelected = true;

        $form.find('.wcscae-attr-select').each(function () {
            var attrName = $(this).data('attribute_name') || $(this).attr('name');
            var val = $(this).val();
            if (!val) {
                allSelected = false;
            }
            currentAttributes[attrName] = val;
        });

        var $submitBtn = $form.find('.wcscae-atc-submit');
        var $priceDisplay = $form.find('.wcscae-product-price-display');
        var $stockDisplay = $form.find('.wcscae-stock-status');
        var $varIdInput = $form.find('.wcscae-variation-id');
        var $thumb = $form.find('.wcscae-product-thumb');

        if (!allSelected) {
            $varIdInput.val('0');
            $submitBtn.prop('disabled', true);
            $stockDisplay.html('');
            return;
        }

        var matchingVariation = findMatchingVariation(variationsData, currentAttributes);

        if (matchingVariation) {
            $varIdInput.val(matchingVariation.variation_id);

            // Update price
            if (matchingVariation.price_html) {
                $priceDisplay.html(matchingVariation.price_html);
            }

            // Update image
            if (matchingVariation.image && (matchingVariation.image.thumb_src || matchingVariation.image.src)) {
                $thumb.attr('src', matchingVariation.image.thumb_src || matchingVariation.image.src);
                if (matchingVariation.image.srcset) {
                    $thumb.attr('srcset', matchingVariation.image.srcset);
                }
            }

            // Update stock & button
            if (!matchingVariation.is_in_stock || !matchingVariation.is_purchasable) {
                $stockDisplay.html('<p class="stock out-of-stock">' + WCSCAE.i18n.outOfStock + '</p>');
                $submitBtn.prop('disabled', true);
            } else {
                if (matchingVariation.availability_html) {
                    $stockDisplay.html(matchingVariation.availability_html);
                } else {
                    $stockDisplay.html('');
                }
                $submitBtn.prop('disabled', false);
            }
        } else {
            $varIdInput.val('0');
            $submitBtn.prop('disabled', true);
            $stockDisplay.html('<p class="stock out-of-stock">' + WCSCAE.i18n.outOfStock + '</p>');
        }
    }

    function renderVariationModalContent(data, fallbackTitle) {
        if (data.title) {
            $('#wcscae-modal .wcscae-dialog > h3').text(data.title);
        } else if (fallbackTitle) {
            $('#wcscae-modal .wcscae-dialog > h3').text(fallbackTitle);
        }
        $('.wcscae-content').html(data.html);
        var $form = $('.wcscae-variation-form');
        if (data.variations) {
            $form.data('product_variations', data.variations);
        }
        checkVariations($form);
    }

    function fetchVariationData(productId, productUrl) {
        var cacheKey = productId ? String(productId) : (productUrl || '');
        if (!cacheKey) return $.Deferred().reject().promise();

        if (formCache[cacheKey]) {
            return $.Deferred().resolve(formCache[cacheKey]).promise();
        }

        if (prefetchPromises[cacheKey]) {
            return prefetchPromises[cacheKey];
        }

        var endpoint = getEndpointUrl('wcscae_get_variation_form');
        var promise = $.ajax({
            url: endpoint,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wcscae_get_variation_form',
                nonce: WCSCAE.nonce,
                product_id: productId || '',
                product_url: productUrl || ''
            }
        }).then(function (res) {
            if (res && res.success && res.data && res.data.html) {
                formCache[cacheKey] = res.data;
                if (productId && !formCache[String(productId)]) {
                    formCache[String(productId)] = res.data;
                }
                return res.data;
            } else {
                return $.Deferred().reject(res && res.data ? res.data.message : null).promise();
            }
        });

        prefetchPromises[cacheKey] = promise;
        return promise;
    }

    function openVariableProductModal(productId, productUrl, modalTitle) {
        // If side cart is open, close it
        $('.xoo-wsc-cart-close, .xoo-wsc-opac, .xoo-wsch-close').first().trigger('click');

        var cacheKey = productId ? String(productId) : (productUrl || '');

        // INSTANT RENDER if cached!
        if (cacheKey && formCache[cacheKey]) {
            openModal(modalTitle || formCache[cacheKey].title || WCSCAE.i18n.selectOption);
            renderVariationModalContent(formCache[cacheKey], modalTitle);
            return;
        }

        // Otherwise open modal with clean loader while fetching in background
        openModal(modalTitle || WCSCAE.i18n.selectOption);
        $('.wcscae-content').html(
            '<div class="wcscae-loading">' +
                '<div class="wcscae-spinner"></div>' +
                '<span>' + WCSCAE.i18n.loading + '</span>' +
            '</div>'
        );

        fetchVariationData(productId, productUrl)
            .done(function (data) {
                renderVariationModalContent(data, modalTitle);
            })
            .fail(function (errMsg) {
                showError(errMsg || WCSCAE.i18n.atcError);
            });
    }

    function extractProductInfo(element) {
        var $btn = $(element);
        var productId = $btn.data('product_id') || $btn.attr('data-product_id');
        var productUrl = $btn.attr('href') || '';

        if (!productId && productUrl) {
            var match = productUrl.match(/product_id=(\d+)/) || productUrl.match(/add-to-cart=(\d+)/);
            if (match) {
                productId = match[1];
            }
        }

        if (!productId) {
            var $cont = $btn.closest('.product, .wc-block-grid__product, .elementor-widget-container, li.product');
            if ($cont.length) {
                productId = $cont.data('product-id') || $cont.attr('data-product-id') || $cont.find('[data-product_id]').data('product_id');
            }
        }

        return {
            id: productId || '',
            url: productUrl || ''
        };
    }

    // High-speed prefetch on hover or touch before click
    document.addEventListener('pointerover', function (e) {
        var el = e.target;
        var btn = el.closest('.wcscae-select-options, .product_type_variable, [data-product_type="variable"]');
        if (!btn) {
            var candidate = el.closest('a, button');
            if (candidate && $(candidate).text().trim().toLowerCase().indexOf('select option') !== -1) {
                btn = candidate;
            }
        }
        if (btn) {
            var info = extractProductInfo(btn);
            if (info.id || info.url) {
                fetchVariationData(info.id, info.url);
            }
        }
    }, true);

    document.addEventListener('touchstart', function (e) {
        var el = e.target;
        var btn = el.closest('.wcscae-select-options, .product_type_variable, [data-product_type="variable"]');
        if (!btn) {
            var candidate = el.closest('a, button');
            if (candidate && $(candidate).text().trim().toLowerCase().indexOf('select option') !== -1) {
                btn = candidate;
            }
        }
        if (btn) {
            var info = extractProductInfo(btn);
            if (info.id || info.url) {
                fetchVariationData(info.id, info.url);
            }
        }
    }, { capture: true, passive: true });

    // Capture phase click listener on native document to intercept BEFORE WooCommerce or Xootix
    document.addEventListener('click', function (e) {
        var el = e.target;
        var btn = el.closest('.wcscae-select-options, .product_type_variable, [data-product_type="variable"]');

        if (!btn) {
            // Check for buttons with text "Select Option"
            var candidate = el.closest('a, button');
            if (candidate && $(candidate).text().trim().toLowerCase().indexOf('select option') !== -1) {
                btn = candidate;
            }
        }

        if (!btn) return;

        var info = extractProductInfo(btn);

        if (info.id || (info.url && info.url !== '#' && info.url.indexOf('javascript') === -1)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            openVariableProductModal(info.id, info.url, WCSCAE.i18n.selectOption);
        }
    }, true); // true = CAPTURE PHASE!

    $(document).on('change', '.wcscae-variation-form .wcscae-attr-select', function () {
        var $form = $(this).closest('.wcscae-variation-form');
        checkVariations($form);
    });

    // Quantity buttons
    $(document).on('click', '.wcscae-qty-minus', function (e) {
        e.preventDefault();
        var $input = $(this).siblings('.wcscae-qty-input');
        var val = parseInt($input.val(), 10) || 1;
        if (val > 1) {
            $input.val(val - 1).trigger('change');
        }
    });

    $(document).on('click', '.wcscae-qty-plus', function (e) {
        e.preventDefault();
        var $input = $(this).siblings('.wcscae-qty-input');
        var val = parseInt($input.val(), 10) || 1;
        $input.val(val + 1).trigger('change');
    });

    // Submit variable product add to cart
    $(document).on('submit', '.wcscae-variation-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.wcscae-atc-submit');
        var originalText = $btn.text();
        var variationId = parseInt($form.find('.wcscae-variation-id').val(), 10);
        var productId = $form.data('product_id');

        $form.find('.wcscae-error').remove();

        if (!variationId || variationId <= 0) {
            $form.prepend('<div class="wcscae-error">' + WCSCAE.i18n.selectVariation + '</div>');
            return;
        }

        $btn.prop('disabled', true).text(WCSCAE.i18n.adding);

        var endpoint = getEndpointUrl('wcscae_add_variation_to_cart');

        $.post(endpoint, {
            action: 'wcscae_add_variation_to_cart',
            nonce: WCSCAE.nonce,
            product_id: productId,
            variation_id: variationId,
            quantity: $form.find('.wcscae-qty-input').val() || 1,
            fields: $form.serialize()
        }).done(function (res) {
            if (!res || !res.success) {
                var msg = res && res.data ? res.data.message : WCSCAE.i18n.atcError;
                $form.prepend('<div class="wcscae-error">' + msg + '</div>');
                $btn.prop('disabled', false).text(originalText);
                return;
            }

            closeModal();

            // Refresh cart fragments
            $(document.body).trigger('wc_fragment_refresh');
            $(document.body).trigger('added_to_cart', [res.data.fragments, res.data.cart_hash, $btn]);
            $(document.body).trigger('updated_cart_totals');

            // Open side cart automatically
            setTimeout(function () {
                $('.xoo-wsc-basket, .xoo-wsc-cart-trigger, .xoo-wsc-sc-bki, .xoo-wsc-modal').first().trigger('click');
                $(document.body).trigger('xoo_wsc_open_cart');
            }, 100);
        }).fail(function (xhr) {
            var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : WCSCAE.i18n.atcError;
            $form.find('.wcscae-error').remove();
            $form.prepend('<div class="wcscae-error">' + msg + '</div>');
            $btn.prop('disabled', false).text(originalText);
        });
    });

    /* -------------------------------------------------------------
     * 3. Modal Close Triggers
     * ------------------------------------------------------------- */
    $(document).on('click', '.wcscae-x, .wcscae-backdrop, .wcscae-cancel', function (e) {
        e.preventDefault();
        closeModal();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#wcscae-modal').hasClass('is-open')) closeModal();
    });
})(jQuery);
