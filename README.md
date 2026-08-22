# Free WooCommerce Incomplete Orders Tracker

<p align="center">
  <img src="incomplete-orders-tracker/assets/joynal-abdin-author.jpg" alt="Joynal Abdin" width="180" />
</p>

<h2 align="center">Free WooCommerce Incomplete Checkout Recovery</h2>

<p align="center">
  Capture incomplete checkout details, understand what customers intended to buy, and follow up from one clean WordPress dashboard.
</p>

<p align="center">
  <a href="https://devjoynal.com/incomplete-orders-tracker.html">Plugin website</a> ·
  <a href="https://github.com/joynalabddin/incomplete-orders-tracker/releases">Download free plugin</a> ·
  <a href="https://github.com/joynalabddin/incomplete-orders-tracker/issues">Support & Issues</a>
</p>

## About the plugin

**Incomplete Orders Tracker** is a free WordPress and WooCommerce plugin by **Joynal Abdin** at [devjoynal.com](https://devjoynal.com). It works as a WooCommerce incomplete checkout tracker and abandoned order recovery tool: when a customer starts entering checkout information but leaves before completing an order, the store administrator can review the lead, see product context, and follow up through WhatsApp or email.

The plugin is designed for WooCommerce stores that want a simple, self-hosted recovery workflow without a paid service, license key, activation key, account connection, or external dashboard. Data is stored in the site's own WordPress database.

## WooCommerce abandoned checkout recovery features

| Capability | Details |
|---|---|
| Incomplete checkout capture | Saves customer name, email, phone, address and product context while checkout is in progress. |
| Checkout compatibility | Supports WooCommerce Classic Checkout and WooCommerce Block Checkout. |
| Session-first matching | Uses a checkout session and order metadata to connect a completed order to the correct incomplete record. |
| Safe fallback matching | If session data is unavailable, the newest eligible match can be found using email or phone within the configured time window. |
| Product context | Captures product names and links, with a WooCommerce cart fallback when checkout selectors are unavailable. |
| WhatsApp recovery | Opens a pre-filled WhatsApp message using the administrator's editable template and country code. |
| Email recovery | Opens a pre-filled email with editable subject and message placeholders. |
| Admin actions | Mark a record completed, delete a record, open product links, call a phone number, or open an address in Google Maps. |
| CSV export | Exports records for administrator review with permission, nonce and spreadsheet-formula protection. |
| Privacy controls | Includes input limits, request throttling and configurable data retention cleanup. |
| Update notifications | Checks public GitHub Releases and shows WordPress update notifications for administrator approval. |

## How the free WooCommerce checkout tracker works

### 1. A customer visits checkout

The frontend script loads only on WooCommerce checkout pages. It creates a random checkout session identifier and keeps it in browser storage and a cookie. The identifier does not contain the customer's name, email or phone number.

### 2. Checkout information changes

After a short configurable delay, the plugin reads available billing fields and product information from the checkout. It sanitizes the values, removes unsafe product links and ignores placeholder labels such as `Subtotal`, `Shipping` and `Checkout`.

The information is sent to the site's own WordPress AJAX endpoint. Requests require a WordPress nonce, are limited in size and are throttled per IP address. The plugin does not send checkout information to Joynal Abdin, GitHub, WhatsApp or any other external service during capture.

### 3. A record is updated instead of duplicated

If the same checkout session already has an incomplete record, that row is updated. Otherwise, a new record is inserted into the site's `{prefix}iot_incomplete_orders` table with status `incomplete`.

### 4. An order is created or paid

The plugin attaches the checkout session identifier to the WooCommerce order. When the order is processed, paid, moved to processing, completed, or created through Block Checkout, the plugin marks the newest matching incomplete record as `complete` and stores the WooCommerce order ID.

### 5. The administrator follows up

The dashboard shows incomplete and manually completed records. The administrator can use the WhatsApp and email buttons, open the product, call the customer, view the address on Google Maps, manually mark the record completed, delete the record, or export a CSV.

### 6. Old records are cleaned up

A daily WordPress maintenance event removes records older than the configured retention period. The default retention is 90 days and can be changed from **Incomplete Orders → Settings** between 30 and 3,650 days.

## Installation: free WordPress plugin for WooCommerce

1. Download the latest `incomplete-orders-tracker.zip` file from the [Releases page](https://github.com/joynalabddin/incomplete-orders-tracker/releases).
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP file and click **Install Now**.
4. Activate **Incomplete Orders Tracker**.
5. Confirm that WooCommerce is active, then open **Incomplete Orders** from the WordPress admin menu.
6. Review the settings, message templates, country code and retention period.

No license key or paid activation is needed. WordPress's normal plugin activation step is only used to create or repair the local database table and schedule maintenance.

## Update process

Updates are distributed through versioned GitHub Releases. When a new release is published, an installed plugin checks the public repository during WordPress's normal plugin update check. If a newer version exists, WordPress displays an update notification on the Plugins or Updates screen. The administrator reviews and approves the update.

This is intentionally a **notification-and-approval** workflow. The plugin does not silently replace itself. WordPress may cache update checks, so a new release may not appear at the exact second it is published; an administrator can use the normal WordPress update check to refresh it.

For a release to be recognized, the release must include the asset named exactly:

```text
incomplete-orders-tracker.zip
```

The release tag should use a semantic version such as `v1.0.0`, `v1.1.0` or `v1.0.1`. The ZIP must contain one top-level folder named `incomplete-orders-tracker` and the main plugin file at:

```text
incomplete-orders-tracker/incomplete-orders-tracker.php
```

## Settings

| Setting | Purpose |
|---|---|
| Site Name | The store name used in WhatsApp and email templates. |
| Site URL | The store URL used in message templates. |
| WhatsApp Country Code | Default country prefix, such as `880` for Bangladesh. |
| Capture Delay | How long the plugin waits after a checkout field change before saving. |
| Completion Match Window | How many recent days are eligible for email/phone fallback matching. |
| Data Retention | How long incomplete and manually completed records remain before cleanup. |
| WhatsApp Template | Editable recovery message with placeholders. |
| Email Subject and Body | Editable email recovery content with placeholders. |

Available placeholders include `{{customer_name}}`, `{{product_name}}`, `{{site_name}}`, `{{site_url}}` and `{{order_date}}`.

## Privacy and data handling

The plugin stores checkout contact information, address data, product context, timestamps and the visitor IP address in the site's WordPress database. The data is collected so the store administrator can recover an incomplete order. Administrators should update their privacy policy, define a lawful retention period and inform customers about the collection of checkout data.

Recovery buttons can open WhatsApp, the administrator's email client or Google Maps in the administrator's browser. These external services are opened only after the administrator chooses the relevant action.

The plugin has no remote license service, no paid activation, no customer account connection and no hidden license check. GitHub is used only for public version update metadata and release downloads.

## Requirements

- WordPress 6.2 or newer.
- PHP 7.4 or newer.
- WooCommerce active.
- HTTPS is strongly recommended for the store checkout.

## Development and release

The plugin source is in the [`incomplete-orders-tracker/`](incomplete-orders-tracker/) directory. Before creating a release, run PHP syntax checks, JavaScript syntax checks and a staging-site test covering Classic Checkout, Block Checkout, order completion, admin actions, CSV export and migration from an older installation.

To publish a release from a local clone:

```bash
git add .
git commit -m "Release 1.0.0"
git tag v1.0.0
git push origin main --tags
```

Then create a GitHub Release for the tag and upload `incomplete-orders-tracker.zip` as the release asset. The repository is public so installed sites can retrieve release metadata without distributing a GitHub token.

## WordPress support, development and website

For WordPress development, security recovery, migration, performance and related services, visit **[devjoynal.com](https://devjoynal.com)**. Read the dedicated [WooCommerce incomplete orders tracker page](https://devjoynal.com/incomplete-orders-tracker.html) for a user-friendly overview and download path. Bugs and feature suggestions can be submitted through the repository's [Issues](https://github.com/joynalabddin/incomplete-orders-tracker/issues) page.

## License

This project is released under the GPLv2 or later. It is free software and does not require a license key or activation key to run.

## Author

**Joynal Abdin**  
[devjoynal.com](https://devjoynal.com)
