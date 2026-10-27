=== Sendcloud Dynamic Checkout for WooCommerce ===
Contributors: obadaaltarazi
Tags: woocommerce, shipping, sendcloud, checkout, delivery
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show real-time Sendcloud shipping options at WooCommerce checkout with simple carrier labels, pickup support, and dynamic store profiles.

== Description ==

Sendcloud Dynamic Checkout for WooCommerce adds real-time shipping options from Sendcloud to your WooCommerce checkout using the Sendcloud Shipping Options API v3.

Key features:

* Real-time Sendcloud rates at checkout
* Dynamic carrier filtering and simple checkout mode
* Checkout-only mode (hide Sendcloud rates on cart)
* Free-shipping threshold support
* Netherlands store pickup option
* Multiple store profiles with one active profile
* WooCommerce base store fallback for origin and pickup details
* Webhook endpoint and test tools in admin

This plugin is designed for merchants who want flexible shipping controls while keeping checkout simple for customers.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WordPress admin.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Go to `WooCommerce > Sendcloud Dynamic Checkout`.
4. Enter Sendcloud API credentials and configure shipping behavior.
5. Save settings and test checkout rates.

== Frequently Asked Questions ==

= Do I need Sendcloud API keys? =

Yes. To fetch real-time Sendcloud shipping rates, you need a valid Sendcloud public key and secret key.

= Can I show Sendcloud rates only on checkout? =

Yes. Enable `Show Shipping In Checkout Only` in plugin settings.

= Can I keep WooCommerce shipping methods too? =

Yes. Disable `Hide Other Shipping Methods` to keep WooCommerce methods visible.

= What are store profiles? =

Store profiles let you define multiple origin/pickup locations. You can select one active profile, and the plugin uses it for sender and pickup defaults.

== Changelog ==

= 3.0.2 =
* Added estimated delivery date range labels (e.g., Arrives 23-25 Apr) per shipping option.
* Preserved delivery date labels in grouped dropdown when selecting carriers and after checkout AJAX refreshes.
* Added lightweight checkout fallback notice for temporary live-rate API issues.
* Added admin API health status chip with last issue summary.

= 2.4.0 =
* Added per-option ETA formatting from Sendcloud lead time.
* Added dynamic store profiles with active-profile selection.
* Added WooCommerce base store fallback for origin/pickup data.
* Added uninstall cleanup and WordPress.org readme metadata.

= 2.3.1 =
* Improved NL store pickup reliability when destination data is incomplete during checkout updates.

= 2.3.0 =
* Packaging and compatibility updates.

= 2.2.9 =
* Improved Netherlands destination detection for store pickup logic.

= 2.2.8 =
* Tightened checkout-only context detection for cart behavior.

== Upgrade Notice ==

= 3.0.2 =
Includes checkout delivery date ranges and fallback/debug visibility improvements.

= 2.4.0 =
Includes dynamic store profiles and WordPress.org readiness hardening. Review active profile settings after upgrade.
