=== Incomplete Orders Tracker ===
Contributors: joynalabdin
Tags: woocommerce, incomplete checkout, abandoned checkout, order recovery
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
Author: Joynal Abdin
Author URI: https://devjoynal.com

Free, activation-free tool to capture and recover incomplete WooCommerce checkouts with product context, responsive admin tools, WhatsApp follow-up, email recovery and CSV export.

== Description ==

Incomplete Orders Tracker records customer contact, address and cart context while a WooCommerce checkout is in progress. It supports Classic Checkout and WooCommerce Block Checkout, records when an order is created, and marks the matching record converted when payment or an accepted processing/completed status is reached.

Features:

* Session-first incomplete checkout tracking.
* Classic Checkout and Block Checkout support.
* Automatic completion using the checkout session, then recent email or phone matching.
* WhatsApp and email recovery actions with editable templates.
* CSV export protected with WordPress permissions, nonce validation and formula-injection protection.
* Responsive admin dashboard for incomplete and manually completed records.
* Public save endpoint with input limits and per-session throttling.
* Configurable data retention with daily cleanup of old records.
* Completely free to use; no license key, activation key or paid activation is required.
* Optional update notification from the public Joynal Abdin GitHub repository; administrators approve updates from WordPress.
* Order-created and paid/converted states are tracked separately to reduce false completion records.
* Includes WooCommerce HPOS compatibility declaration, privacy exporter/eraser callbacks, same-site product URL validation and dashboard search/filter/pagination.

== Author ==

Developed by Joynal Abdin. Visit https://devjoynal.com for more information. The WordPress admin dashboard includes the supplied Joynal Abdin author profile image at `assets/joynal-abdin-author.jpg`.

== Privacy ==

The plugin stores checkout contact information, address data, product context and the visitor IP address in the site's WordPress database so the site administrator can recover incomplete orders. Administrators should publish an appropriate privacy notice and configure retention according to local requirements. Recovery links may open WhatsApp, email or Google Maps in the administrator's browser.

== Changelog ==

= 1.1.0 =

* Separated order creation from paid/converted order status.
* Added WooCommerce HPOS compatibility declaration and privacy exporter/eraser callbacks.
* Added same-site product URL validation, dashboard search, status filters and pagination.
* Improved the free GitHub release workflow with a mandatory newer-version guard.

= 1.0.0 =

* Initial public release by Joynal Abdin at https://devjoynal.com.
* Added GitHub Releases update notifications with administrator approval.
* Converted the plugin to completely free, activation-free operation.
* Added public endpoint throttling and configurable daily data retention.
* Limited automatic order matching to the newest eligible incomplete record.
* Hardened manual completion and safe cookie parsing.
* Fixed admin counters after complete/delete actions.
* Fixed the product-cell CSS selector and cleaned release metadata.

= Pre-1.0 development history =

* Added reliable checkout session persistence and order meta matching.
* Improved Classic and Block Checkout field and cart detection.
* Added CSV export protection and formula-injection handling.

