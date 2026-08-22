# Incomplete Order Tracker Plugin for WooCommerce

**Incomplete Orders Tracker** is a free WordPress plugin for WooCommerce stores that want to capture incomplete checkout context and follow up with potential customers. It is developed by **Joynal Abdin** and published at [devjoynal.com](https://devjoynal.com/).

[Download the free plugin](https://github.com/joynalabddin/incomplete-orders-tracker/releases/latest) · [View the source code](https://github.com/joynalabddin/incomplete-orders-tracker) · [Visit DevJoynal](https://devjoynal.com/)

## What problem does it solve?

A shopper may enter part of a WooCommerce checkout and leave before an order is created. Without a recovery workflow, the store owner may not know what the shopper intended to buy or how to follow up. Incomplete Orders Tracker keeps the available checkout context in the store’s own WordPress database and presents it in an administrator dashboard.

The store owner can review the customer details and product context, then choose a WhatsApp or email action. The plugin does not send automatic marketing messages and does not require a third-party dashboard.

## Main features

| Feature | Description |
| --- | --- |
| Incomplete checkout capture | Saves available checkout name, email, phone, address and product context after a short configurable delay. |
| WooCommerce support | Works with Classic Checkout and includes Block Checkout hooks. |
| Order matching | Uses a checkout session first, then a limited recent email/phone fallback when session data is unavailable. |
| Accurate lifecycle | Separates `incomplete`, `order_created`, `converted` and manually completed records. |
| Recovery actions | Opens a pre-filled WhatsApp message or email for administrator review and sending. |
| Admin workflow | Search records, filter by status, paginate results, view safe product links, call a phone number, open a map or delete a record. |
| CSV export | Export records with administrator permission, nonce validation and spreadsheet-formula protection. |
| Privacy tools | Includes retention cleanup and WordPress personal-data export/erase callbacks. |
| HPOS readiness | Declares WooCommerce High-Performance Order Storage compatibility. |
| Free updates | Shows a normal WordPress update notification when a newer public GitHub Release is available. |

## How it works

1. A visitor starts checkout and the browser receives a random session identifier that contains no personal information.
2. After checkout fields change, the plugin sends available values to the site’s own WordPress AJAX endpoint.
3. The plugin sanitizes and limits the values before saving them in the site’s local database.
4. If WooCommerce creates an order, the matching record becomes `order_created`.
5. If payment completes or the order reaches an accepted processing/completed status, the record becomes `converted`.
6. The administrator can review remaining incomplete records and choose a recovery action.

The plugin does not send captured checkout data to Joynal Abdin, DevJoynal or GitHub during capture. External WhatsApp, email and Google Maps links open only after an administrator chooses an action.

## Installation

1. Download `incomplete-orders-tracker.zip` from the [latest release](https://github.com/joynalabddin/incomplete-orders-tracker/releases/latest).
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and select **Install Now**.
4. Activate the plugin through the normal WordPress activation button.
5. Confirm WooCommerce is active and open **Incomplete Orders** in the admin menu.
6. Review the store identity, WhatsApp country code, message templates and retention period.

The plugin is free for everyone. It does not ask for a license key, activation key, paid subscription, customer account or remote activation.

## Privacy and ownership

Checkout contact fields, address information, product context, timestamps and the visitor IP address are stored in the site owner’s WordPress database. The site owner controls retention and should publish a privacy notice that explains the purpose of the collection and the recovery process.

WordPress administrators can use the personal-data export and erase tools for matching checkout records. The site owner should test these tools and choose a retention period appropriate for the store’s location and policy.

## Requirements

- WordPress 6.2 or newer.
- PHP 7.4 or newer.
- Active WooCommerce.
- HTTPS strongly recommended for checkout.

## Updates

A new release is published through the public GitHub repository. WordPress checks the release metadata during its normal plugin update process and displays an update notification when a newer version is available. The administrator reviews and approves the update. The plugin does not silently update itself.

## Support

For bugs or feature requests, open an [Issue](https://github.com/joynalabddin/incomplete-orders-tracker/issues). For WordPress development, WooCommerce recovery workflows, security hardening and performance work, visit [devjoynal.com](https://devjoynal.com/).

## Author

**Joynal Abdin**  
[devjoynal.com](https://devjoynal.com)
