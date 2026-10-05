=== Chwazi - Upcoming Time Slots Fix ===
Contributors: antigravity
Tags: woocommerce, chwazi, checkout, delivery, pickup, time slots
Requires at least: 5.0
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later

A lightweight standalone WooCommerce add-on for Chwazi – Delivery & Pickup Scheduling for WooCommerce.

== Description ==

This add-on fixes the checkout time selector issue in Chwazi:
- If the customer selects **Today**, all time slots that have already passed are automatically hidden/disabled so customers can only select upcoming time slots based on the store's current time.
- If a **future date** is selected, all Chwazi-configured time slots are displayed normally.
- Includes server-side AJAX filtering, client-side dynamic DOM/Flatpickr synchronization, and server-side checkout validation.

== Installation ==

1. Upload the `chwazi-checkout-time-slot-fix` directory to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
