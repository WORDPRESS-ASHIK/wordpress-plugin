/**
 * Admin JavaScript for WooCommerce Product Add-ons & Side Card
 */

(function($) {
	'use strict';

	var WCPascAdmin = {
		init: function() {
			this.bindEvents();
			this.initSortable();
			this.initColorPicker();
			this.updateEmptyState();
			this.updatePackEmptyState();
		},

		initColorPicker: function() {
			if ($.fn.wpColorPicker) {
				$('.wc-pasc-color-picker').wpColorPicker({
					change: function(event, ui) {
						// Optional live feedback hooks if needed
					}
				});
			}
		},

		bindEvents: function() {
			var self = this;

			// Toggle enabled master checkbox
			$('#_wc_pasc_enabled').on('change', function() {
				if ($(this).is(':checked')) {
					$('.wc-pasc-groups-wrapper').slideDown(200);
				} else {
					$('.wc-pasc-groups-wrapper').slideUp(200);
				}
			});

			// Toggle box/pack qty enabled
			$('#_wc_pasc_box_qty_enabled').on('change', function() {
				if ($(this).is(':checked')) {
					$('.wc-pasc-pack-sizes-wrapper').slideDown(200);
				} else {
					$('.wc-pasc-pack-sizes-wrapper').slideUp(200);
				}
			});

			// =========================================================================
			// DYNAMIC PACK SIZES REPEATER
			// =========================================================================

			// Add Pack Size Row
			$(document).on('click', '.wc-pasc-add-pack-btn', function(e) {
				e.preventDefault();
				self.addPackRow();
			});

			// Remove Pack Size Row
			$(document).on('click', '.wc-pasc-remove-pack-btn', function(e) {
				e.preventDefault();
				var $row = $(this).closest('.wc-pasc-pack-row');
				$row.fadeOut(150, function() {
					$(this).remove();
					self.reindexPackSizes();
					self.updatePackEmptyState();
				});
			});

			// Single Default Checkbox for Pack Sizes
			$(document).on('change', '.wc-pasc-pack-default-checkbox', function() {
				if ($(this).is(':checked')) {
					$('.wc-pasc-pack-default-checkbox').not(this).prop('checked', false);
				}
			});

			// =========================================================================
			// FLAVOUR / ADD-ON GROUPS
			// =========================================================================

			// Add New Group
			$(document).on('click', '.wc-pasc-add-group-btn', function(e) {
				e.preventDefault();
				self.addGroup();
			});

			// Remove Group
			$(document).on('click', '.wc-pasc-remove-group-btn', function(e) {
				e.preventDefault();
				if (confirm(wcPascAdmin.i18n.removeGroupConfirm)) {
					var $card = $(this).closest('.wc-pasc-group-card');
					$card.fadeOut(200, function() {
						$(this).remove();
						self.updateEmptyState();
						self.reindexAll();
					});
				}
			});

			// Collapse / Expand Group
			$(document).on('click', '.wc-pasc-toggle-group-btn', function(e) {
				e.preventDefault();
				var $card = $(this).closest('.wc-pasc-group-card');
				$card.find('.wc-pasc-group-body').slideToggle(200);
				$(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
			});

			// Update Group Title Preview on type
			$(document).on('input', '.wc-pasc-input-group-title', function() {
				var val = $(this).val();
				var $card = $(this).closest('.wc-pasc-group-card');
				$card.find('.wc-pasc-group-title-preview').text(val ? val : 'Untitled Group');
			});

			// Update Limit Badge on Max limit or Type change
			$(document).on('input change', '.wc-pasc-input-max-limit, .wc-pasc-select-type', function() {
				var $card = $(this).closest('.wc-pasc-group-card');
				var type = $card.find('.wc-pasc-select-type').val();
				var max = parseInt($card.find('.wc-pasc-input-max-limit').val(), 10) || 1;

				if (type === 'radio') {
					$card.find('.wc-pasc-max-limit-field').hide();
					$card.find('.wc-pasc-group-limit-badge').text('Single Select (1)');
				} else {
					$card.find('.wc-pasc-max-limit-field').show();
					$card.find('.wc-pasc-group-limit-badge').text('Max ' + max + ' selections');
				}
			});

			// Add Option inside Group
			$(document).on('click', '.wc-pasc-add-option-btn', function(e) {
				e.preventDefault();
				var $card = $(this).closest('.wc-pasc-group-card');
				self.addOption($card);
			});

			// Remove Option inside Group
			$(document).on('click', '.wc-pasc-remove-option-btn', function(e) {
				e.preventDefault();
				var $row = $(this).closest('.wc-pasc-option-row');
				var $card = $(this).closest('.wc-pasc-group-card');
				$row.fadeOut(150, function() {
					$(this).remove();
					self.reindexOptions($card);
				});
			});
		},

		initSortable: function() {
			var self = this;

			// Pack Sizes Sortable
			$('.wc-pasc-pack-sizes-list').sortable({
				handle: '.col-sort',
				items: '.wc-pasc-pack-row',
				opacity: 0.7,
				stop: function() {
					self.reindexPackSizes();
				}
			});

			// Addon Groups Sortable
			$('#wc-pasc-groups-list').sortable({
				handle: '.wc-pasc-group-handle',
				items: '.wc-pasc-group-card',
				opacity: 0.7,
				stop: function() {
					self.reindexAll();
				}
			});

			// Group Options Sortable
			$('.wc-pasc-options-list').sortable({
				handle: '.col-sort',
				items: '.wc-pasc-option-row',
				opacity: 0.7,
				stop: function(e, ui) {
					var $card = ui.item.closest('.wc-pasc-group-card');
					self.reindexOptions($card);
				}
			});
		},

		addPackRow: function(defaultName, defaultPieces, defaultPrice) {
			var tmpl = $('#tmpl-wc-pasc-pack-row').html();
			var packIndex = new Date().getTime();
			var rowHtml = tmpl.replace(/\{\{pack_index\}\}/g, packIndex);
			var $newRow = $(rowHtml);

			if (defaultName) {
				$newRow.find('.col-pack-name input').val(defaultName);
			}
			if (defaultPieces) {
				$newRow.find('.col-pack-pieces input').val(defaultPieces);
			}
			if (defaultPrice) {
				$newRow.find('.col-pack-price input').val(defaultPrice);
			}

			// If it's the first pack row, check it as default
			if ($('.wc-pasc-pack-sizes-list .wc-pasc-pack-row').length === 0) {
				$newRow.find('.wc-pasc-pack-default-checkbox').prop('checked', true);
			}

			$('.wc-pasc-pack-sizes-list').append($newRow);
			this.updatePackEmptyState();
			this.reindexPackSizes();
			$newRow.find('.col-pack-pieces input').focus();
		},

		reindexPackSizes: function() {
			$('.wc-pasc-pack-sizes-list .wc-pasc-pack-row').each(function(idx) {
				var $row = $(this);
				$row.attr('data-pack-index', idx);
				$row.find('input').each(function() {
					var name = $(this).attr('name');
					if (name) {
						name = name.replace(/wc_pasc_pack_sizes\[[^\]]+\]/, 'wc_pasc_pack_sizes[' + idx + ']');
						$(this).attr('name', name);
					}
				});
			});
		},

		updatePackEmptyState: function() {
			var count = $('.wc-pasc-pack-sizes-list .wc-pasc-pack-row').length;
			if (count === 0) {
				$('.wc-pasc-pack-empty-notice').show();
			} else {
				$('.wc-pasc-pack-empty-notice').hide();
			}
		},

		addGroup: function() {
			var tmpl = $('#tmpl-wc-pasc-group').html();
			var groupIndex = new Date().getTime();
			var groupHtml = tmpl.replace(/\{\{group_index\}\}/g, groupIndex);
			var $newGroup = $(groupHtml);

			$('#wc-pasc-groups-list').append($newGroup);
			this.updateEmptyState();
			this.reindexAll();
			this.initSortable();

			// Add 2 default options to make it quick for admin
			this.addOption($newGroup, 'Option 1');
			this.addOption($newGroup, 'Option 2');

			$newGroup.find('.wc-pasc-input-group-title').focus();
		},

		addOption: function($card, defaultName) {
			var tmpl = $('#tmpl-wc-pasc-option').html();
			var groupIndex = $card.data('group-index');
			var optionIndex = new Date().getTime() + Math.floor(Math.random() * 100);

			var optHtml = tmpl
				.replace(/\{\{group_index\}\}/g, groupIndex)
				.replace(/\{\{option_index\}\}/g, optionIndex);

			var $newRow = $(optHtml);
			if (defaultName) {
				$newRow.find('input[type="text"]').first().val(defaultName);
			}

			$card.find('.wc-pasc-options-list').append($newRow);
			this.reindexOptions($card);
		},

		reindexAll: function() {
			var self = this;
			$('#wc-pasc-groups-list .wc-pasc-group-card').each(function(gIdx) {
				var $group = $(this);
				$group.attr('data-group-index', gIdx);

				$group.find('input, select, textarea').each(function() {
					var name = $(this).attr('name');
					if (name) {
						name = name.replace(/wc_pasc_data\[[^\]]+\]/, 'wc_pasc_data[' + gIdx + ']');
						$(this).attr('name', name);
					}
				});

				self.reindexOptions($group);
			});
		},

		reindexOptions: function($card) {
			var gIdx = $card.attr('data-group-index');
			$card.find('.wc-pasc-options-list .wc-pasc-option-row').each(function(optIdx) {
				$(this).attr('data-option-index', optIdx);
				$(this).find('input, select').each(function() {
					var name = $(this).attr('name');
					if (name) {
						name = name.replace(/\[options\]\[[^\]]+\]/, '[options][' + optIdx + ']');
						$(this).attr('name', name);
					}
				});
			});
		},

		updateEmptyState: function() {
			var count = $('#wc-pasc-groups-list .wc-pasc-group-card').length;
			if (count === 0) {
				$('.wc-pasc-empty-notice').show();
			} else {
				$('.wc-pasc-empty-notice').hide();
			}
		}
	};

	$(document).ready(function() {
		WCPascAdmin.init();
	});

})(jQuery);

