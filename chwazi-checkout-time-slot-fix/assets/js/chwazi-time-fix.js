/**
 * Chwazi - Upcoming Time Slots Fix Frontend Script
 *
 * Feature Highlights:
 * 1. Disables time selector on checkout page load when no date is selected.
 * 2. Enables time selector when a date is selected.
 * 3. Disables time selector and resets time if date is cleared/unselected.
 * 4. For Today: hides/disables all passed time slots based on store current time.
 * 5. For Future Dates: shows all Chwazi-configured time slots normally.
 * 6. Adds placeholder text to pickup fields ("Choose Pickup Date", "Choose Pickup Time").
 * 7. Adds placeholder text to WooCommerce checkout postcode fields ("Enter post code").
 * 8. Live field validation for First Name & Last Name (min 3 chars), Email (min 10 chars + format), and Australian Phone while typing.
 */
(function ($) {
	'use strict';

	if (typeof ChwaziTimeFixData === 'undefined') {
		return;
	}

	var pageLoadPerf = performance.now();

	/**
	 * Get current store date & time in minutes since midnight based on localized server time.
	 */
	function getCurrentStoreTimeMinutes() {
		var elapsedMs = performance.now() - pageLoadPerf;
		var currentMs = parseInt(ChwaziTimeFixData.serverTimeMs, 10) + elapsedMs;
		var storeDate = new Date(currentMs);

		var hours = storeDate.getHours();
		var minutes = storeDate.getMinutes();

		return (hours * 60) + minutes;
	}

	/**
	 * Parse time string into minutes since midnight.
	 * Supports formats: "11:00 AM", "11:00 AM - 12:00 PM", "14:30", "14:30 - 15:30"
	 */
	function parseSlotTimeMinutes(timeStr) {
		if (!timeStr || typeof timeStr !== 'string') {
			return null;
		}

		var parts = timeStr.split('-');
		var startTime = $.trim(parts[0]);

		var match = startTime.match(/^(\d{1,2}):(\d{2})(?:\s*(AM|PM))?$/i);
		if (!match) {
			return null;
		}

		var hours = parseInt(match[1], 10);
		var minutes = parseInt(match[2], 10);
		var meridiem = match[3] ? match[3].toUpperCase() : null;

		if (meridiem) {
			if (meridiem === 'PM' && hours < 12) {
				hours += 12;
			} else if (meridiem === 'AM' && hours === 12) {
				hours = 0;
			}
		}

		return (hours * 60) + minutes;
	}

	/**
	 * Format date object to YYYY-MM-DD
	 */
	function formatDateYMD(dateObj) {
		if (!dateObj || isNaN(dateObj.getTime())) {
			return '';
		}
		var year = dateObj.getFullYear();
		var month = ('0' + (dateObj.getMonth() + 1)).slice(-2);
		var day = ('0' + dateObj.getDate()).slice(-2);
		return year + '-' + month + '-' + day;
	}

	/**
	 * Retrieve current selected date YYYY-MM-DD for given order type.
	 */
	function getSelectedDate(orderType) {
		var dateInput = document.querySelector('#lpac_dps_' + orderType + '_date');
		if (!dateInput) {
			return '';
		}

		// Check Flatpickr instance if attached
		if (dateInput._flatpickr) {
			if (dateInput._flatpickr.selectedDates && dateInput._flatpickr.selectedDates.length > 0) {
				return formatDateYMD(dateInput._flatpickr.selectedDates[0]);
			}
			if (dateInput._flatpickr.input && dateInput._flatpickr.input.value) {
				var val = $.trim(dateInput._flatpickr.input.value);
				if (val) {
					return val;
				}
			}
		}

		return $.trim(dateInput.value);
	}

	/**
	 * Disable or enable flatpickr instance if attached to time input.
	 */
	function setFlatpickrDisabledState(element, isDisabled) {
		if (element && element._flatpickr) {
			if (element._flatpickr.input) {
				$(element._flatpickr.input).prop('disabled', isDisabled);
				if (isDisabled) {
					$(element._flatpickr.input).addClass('disabled');
				} else {
					$(element._flatpickr.input).removeClass('disabled');
				}
			}
		}
	}

	/**
	 * Apply placeholder text for Chwazi pickup date and time fields.
	 */
	function applyPickupPlaceholders() {
		var datePlaceholder = ChwaziTimeFixData.i18n.pickupDatePlaceholder || 'Choose Pickup Date';
		var timePlaceholder = ChwaziTimeFixData.i18n.pickupTimePlaceholder || 'Choose Pickup Time';

		// 1. Pickup Date Field
		var pickupDateInput = document.querySelector('#lpac_dps_pickup_date');
		if (pickupDateInput) {
			$(pickupDateInput).attr('placeholder', datePlaceholder);
			if (pickupDateInput._flatpickr && pickupDateInput._flatpickr.altInput) {
				$(pickupDateInput._flatpickr.altInput).attr('placeholder', datePlaceholder);
			}
		}

		// 2. Pickup Time Field
		var pickupTimeElement = document.querySelector('#lpac_dps_pickup_time');
		if (pickupTimeElement) {
			$(pickupTimeElement).attr('placeholder', timePlaceholder);
			if (pickupTimeElement._flatpickr && pickupTimeElement._flatpickr.altInput) {
				$(pickupTimeElement._flatpickr.altInput).attr('placeholder', timePlaceholder);
			}
			if (pickupTimeElement.tagName === 'SELECT') {
				var $firstOpt = $(pickupTimeElement).find('option[value=""]').first();
				if ($firstOpt.length > 0) {
					var currentText = $.trim($firstOpt.text());
					if (!currentText || currentText.indexOf('--') !== -1 || currentText.indexOf('Please choose') !== -1) {
						$firstOpt.text(timePlaceholder);
					}
				}
			}
		}
	}

	/**
	 * Apply placeholder text for WooCommerce checkout postcode fields.
	 */
	function applyPostcodePlaceholder() {
		var postcodePlaceholder = ChwaziTimeFixData.i18n.postcodePlaceholder || 'Enter post code';
		$('#billing_postcode, #shipping_postcode').attr('placeholder', postcodePlaceholder);
	}

	/**
	 * Render or clear inline live warning message under input field.
	 */
	function setLiveFieldError($field, errorMsg) {
		if (!$field || $field.length === 0) {
			return;
		}

		var $wrapper = $field.closest('.form-row');
		var $errorSpan = $wrapper.find('.chwazi-live-error');

		if (errorMsg) {
			if ($errorSpan.length === 0) {
				$errorSpan = $('<span class="chwazi-live-error" role="alert"></span>');
				$field.after($errorSpan);
			}
			$errorSpan.text(errorMsg).show();
			$wrapper.removeClass('woocommerce-validated').addClass('woocommerce-invalid');
		} else {
			if ($errorSpan.length > 0) {
				$errorSpan.hide().text('');
			}
			$wrapper.removeClass('woocommerce-invalid');
			if ($.trim($field.val()).length > 0) {
				$wrapper.addClass('woocommerce-validated');
			}
		}
	}

	/**
	 * Setup live event listeners for First Name, Last Name, Email, Australian Phone, and Postcode fields.
	 */
	function setupLiveFieldValidation() {
		var firstNameMsg = ChwaziTimeFixData.i18n.firstNameLiveError || 'First name must be at least 3 characters.';
		var lastNameMsg = ChwaziTimeFixData.i18n.lastNameLiveError || 'Last name must be at least 3 characters.';
		var emailMsg = ChwaziTimeFixData.i18n.emailLiveError || 'Please enter a valid email address';
		var phoneMsg = ChwaziTimeFixData.i18n.phoneLiveError || 'Please enter a valid Australian phone number.';
		var postcodeMsg = ChwaziTimeFixData.i18n.postcodeLiveError || 'Enter a valid postcode';

		var emailPattern = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;
		var auPhonePattern = /^(?:\+?61|0)[23478]\d{8}$|^1(?:300|800)\d{6}$|^13\d{4}$/;

		var touched = {};

		$(document).on('focus input keyup propertychange', '#billing_first_name, #shipping_first_name, #billing_last_name, #shipping_last_name, #billing_email, #billing_phone, #shipping_phone, #billing_postcode, #shipping_postcode', function () {
			touched[this.id] = true;
		});

		// 1. First Name Live Validation
		$(document).on('input keyup blur change propertychange', '#billing_first_name, #shipping_first_name', function () {
			var $field = $(this);
			if (!$field.is(':visible') || !touched[this.id]) {
				return;
			}
			var val = $.trim($field.val());
			if (val.length < 3) {
				setLiveFieldError($field, firstNameMsg);
			} else {
				setLiveFieldError($field, '');
			}
		});

		// 2. Last Name Live Validation
		$(document).on('input keyup blur change propertychange', '#billing_last_name, #shipping_last_name', function () {
			var $field = $(this);
			if (!$field.is(':visible') || !touched[this.id]) {
				return;
			}
			var val = $.trim($field.val());
			if (val.length < 3) {
				setLiveFieldError($field, lastNameMsg);
			} else {
				setLiveFieldError($field, '');
			}
		});

		// 3. Email Live Validation (format + min 15 chars)
		$(document).on('input keyup blur change propertychange', '#billing_email', function () {
			var $field = $(this);
			if (!$field.is(':visible') || !touched[this.id]) {
				return;
			}
			var val = $.trim($field.val());
			if (val.length < 15 || !emailPattern.test(val)) {
				setLiveFieldError($field, emailMsg);
			} else {
				setLiveFieldError($field, '');
			}
		});

		// 4. Australian Phone Live Validation
		$(document).on('input keyup blur change propertychange', '#billing_phone, #shipping_phone', function () {
			var $field = $(this);
			if (!$field.is(':visible') || !touched[this.id]) {
				return;
			}
			var val = $.trim($field.val());
			var cleaned = val.replace(/[\s\-\(\)]/g, '');
			if (!cleaned || !auPhonePattern.test(cleaned)) {
				setLiveFieldError($field, phoneMsg);
			} else {
				setLiveFieldError($field, '');
			}
		});

		// 5. Australian Postcode Live Validation (4 digits, numeric, 0200-9999)
		$(document).on('input keyup blur change propertychange', '#billing_postcode, #shipping_postcode', function () {
			var $field = $(this);
			if (!$field.is(':visible') || !touched[this.id]) {
				return;
			}
			var val = $.trim($field.val());
			var postcodeNum = parseInt(val, 10);
			if (!/^\d{4}$/.test(val) || isNaN(postcodeNum) || postcodeNum < 200 || postcodeNum > 9999) {
				setLiveFieldError($field, postcodeMsg);
			} else {
				setLiveFieldError($field, '');
			}
		});
	}

	/**
	 * Main controller for time field state & slot filtering for given order type ('delivery' or 'pickup').
	 */
	function updateTimeFieldState(orderType) {
		var timeElement = document.querySelector('#lpac_dps_' + orderType + '_time');
		if (!timeElement) {
			return;
		}

		var selectedDate = getSelectedDate(orderType);
		var $timeElement = $(timeElement);

		// CASE 1: NO DATE SELECTED -> Keep time selector disabled & reset value
		if (!selectedDate) {
			$timeElement.val('');
			$timeElement.prop('disabled', true).addClass('disabled');
			setFlatpickrDisabledState(timeElement, true);

			if (timeElement.tagName === 'SELECT') {
				var $options = $timeElement.find('option');
				$options.each(function () {
					var $opt = $(this);
					if ($opt.data('chwazi-fix-notice')) {
						$opt.remove();
					}
				});
			}
			return;
		}

		// CASE 2: DATE IS SELECTED -> Enable time selector
		$timeElement.prop('disabled', false).removeClass('disabled');
		setFlatpickrDisabledState(timeElement, false);

		if (timeElement.tagName !== 'SELECT') {
			return;
		}

		var $options = $timeElement.find('option');
		if ($options.length === 0) {
			return;
		}

		var todayDate = ChwaziTimeFixData.todayDate;
		var isToday = (selectedDate === todayDate);

		// CASE 2A: FUTURE DATE -> Show all Chwazi-configured time slots normally
		if (!isToday) {
			$options.each(function () {
				var $opt = $(this);
				if ($opt.data('chwazi-fix-notice')) {
					$opt.remove();
					return;
				}
				$opt.prop('disabled', false).prop('hidden', false).css('display', '');
			});
			return;
		}

		// CASE 2B: TODAY -> Hide/disable all time slots that have already passed
		var currentMinutes = getCurrentStoreTimeMinutes();
		var upcomingCount = 0;

		$options.each(function () {
			var $opt = $(this);
			var val = $opt.val();
			var text = $opt.text();

			if ($opt.data('chwazi-fix-notice')) {
				return;
			}

			// Skip placeholder option
			if (!val || text.indexOf('--') !== -1 || text.indexOf('Choose Pickup Time') !== -1) {
				return;
			}

			var slotMinutes = parseSlotTimeMinutes(val || text);

			if (slotMinutes !== null) {
				if (slotMinutes <= currentMinutes) {
					// Passed slot
					$opt.prop('disabled', true).prop('hidden', true).css('display', 'none');
					if ($opt.is(':selected')) {
						$timeElement.val('');
					}
				} else {
					// Upcoming slot
					$opt.prop('disabled', false).prop('hidden', false).css('display', '');
					upcomingCount++;
				}
			} else {
				upcomingCount++;
			}
		});

		// If no upcoming slots remain for today, show notice
		if (upcomingCount === 0) {
			var noticeText = ChwaziTimeFixData.i18n.noTimeSlotsAvailable;
			if ($timeElement.find('option[data-chwazi-fix-notice="1"]').length === 0) {
				var $noticeOpt = $('<option>', {
					value: '',
					text: noticeText,
					selected: true,
					disabled: true
				}).attr('data-chwazi-fix-notice', '1');

				$timeElement.append($noticeOpt).val('');
			}
		}
	}

	/**
	 * Validate First Name & Last Name (min 3 chars), Email (min 10 chars), and AU Phone on frontend submit.
	 */
	function validateCheckoutFormFrontend() {
		var isValid = true;
		var errors = [];

		var firstNameMsg = ChwaziTimeFixData.i18n.firstNameLiveError || 'First name must be at least 3 characters.';
		var lastNameMsg = ChwaziTimeFixData.i18n.lastNameLiveError || 'Last name must be at least 3 characters.';
		var emailMsg = ChwaziTimeFixData.i18n.emailLiveError || 'Please enter a valid email address';
		var phoneMsg = ChwaziTimeFixData.i18n.phoneLiveError || 'Please enter a valid Australian phone number.';
		var postcodeMsg = ChwaziTimeFixData.i18n.postcodeLiveError || 'Enter a valid postcode';

		var emailPattern = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;
		var auPhonePattern = /^(?:\+?61|0)[23478]\d{8}$|^1(?:300|800)\d{6}$|^13\d{4}$/;

		function validateField(fieldId, errorText, validateFn) {
			var $field = $('#' + fieldId);
			if ($field.length > 0 && $field.is(':visible')) {
				var val = $.trim($field.val());
				if (!validateFn(val)) {
					isValid = false;
					errors.push(errorText);
					setLiveFieldError($field, errorText);
				} else {
					setLiveFieldError($field, '');
				}
			}
		}

		validateField('billing_first_name', 'First Name: ' + firstNameMsg, function (val) {
			return val.length >= 3;
		});

		validateField('billing_last_name', 'Last Name: ' + lastNameMsg, function (val) {
			return val.length >= 3;
		});

		if ($('#ship-to-different-address-checkbox').is(':checked')) {
			validateField('shipping_first_name', 'Shipping First Name: ' + firstNameMsg, function (val) {
				return val.length >= 3;
			});

			validateField('shipping_last_name', 'Shipping Last Name: ' + lastNameMsg, function (val) {
				return val.length >= 3;
			});
		}

		validateField('billing_email', emailMsg, function (val) {
			return val && val.length >= 15 && emailPattern.test(val);
		});

		validateField('billing_phone', phoneMsg, function (val) {
			var cleaned = val.replace(/[\s\-\(\)]/g, '');
			return cleaned && auPhonePattern.test(cleaned);
		});

		if ($('#shipping_phone').is(':visible')) {
			validateField('shipping_phone', 'Shipping Phone: ' + phoneMsg, function (val) {
				var cleaned = val.replace(/[\s\-\(\)]/g, '');
				return cleaned && auPhonePattern.test(cleaned);
			});
		}

		validateField('billing_postcode', postcodeMsg, function (val) {
			var num = parseInt(val, 10);
			return /^\d{4}$/.test(val) && !isNaN(num) && num >= 200 && num <= 9999;
		});

		if ($('#shipping_postcode').is(':visible') && $('#ship-to-different-address-checkbox').is(':checked')) {
			validateField('shipping_postcode', 'Shipping Postcode: ' + postcodeMsg, function (val) {
				var num = parseInt(val, 10);
				return /^\d{4}$/.test(val) && !isNaN(num) && num >= 200 && num <= 9999;
			});
		}

		if (!isValid) {
			showCheckoutNotice(errors);
			return false;
		}

		return true;
	}

	/**
	 * Display WooCommerce error banner at top of checkout form.
	 */
	function showCheckoutNotice(errorList) {
		var $form = $('form.checkout');
		$('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
		if (errorList.length > 0) {
			var html = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert">';
			$.each(errorList, function (i, msg) {
				html += '<li><strong>' + msg + '</strong></li>';
			});
			html += '</ul></div>';
			$form.prepend(html);
			$('html, body').animate({
				scrollTop: ($form.offset().top - 100)
			}, 400);
		}
	}

	/**
	 * Update time fields and field placeholders.
	 */
	function applyFixAll() {
		applyPickupPlaceholders();
		applyPostcodePlaceholder();
		updateTimeFieldState('delivery');
		updateTimeFieldState('pickup');
	}

	// Document Ready
	$(document).ready(function () {
		applyFixAll();
		setupLiveFieldValidation();

		// Listen to Chwazi's custom event fired when time field is updated
		document.addEventListener('dps:time_field_replaced', function () {
			applyFixAll();
		});

		// Listen to change/input/keyup on date inputs
		$(document).on('change input keyup propertychange', '#lpac_dps_delivery_date, #lpac_dps_pickup_date', function () {
			applyFixAll();
			setTimeout(applyFixAll, 100);
			setTimeout(applyFixAll, 300);
			setTimeout(applyFixAll, 600);
		});

		// Listen to change on time selectors
		$(document).on('change', '#lpac_dps_delivery_time, #lpac_dps_pickup_time', function () {
			applyFixAll();
		});

		// Observe DOM changes in time field wrappers (e.g. when Chwazi replaces input with select)
		$('.dps-time-field, #lpac_dps_delivery_time_field, #lpac_dps_pickup_time_field').each(function () {
			var observer = new MutationObserver(function () {
				applyFixAll();
			});
			observer.observe(this, { childList: true, subtree: true });
		});

		// WooCommerce checkout update trigger
		$(document.body).on('updated_checkout checkout_error', function () {
			applyFixAll();
		});

		// WooCommerce checkout submit validation handler
		$(document.body).on('checkout_place_order', function () {
			return validateCheckoutFormFrontend();
		});
	});

	// Window load fallback
	$(window).on('load', function () {
		applyFixAll();
	});

})(jQuery);
