=== WooCommerce BOM & Stock Manager ===
Contributors: poorvida
Tags: woocommerce, bill of materials, inventory, manufacturing, stock management
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
WC requires at least: 8.5
WC tested up to: 10.9
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bill-of-materials and component-level stock management for WooCommerce: made-to-order consumption, manufacture orders, and a full stock ledger.

== Description ==

WooCommerce BOM & Stock Manager adds recipe-based inventory to any WooCommerce store where finished products are built from shared raw materials — blank goods that are both sellable products and consumable components, customizable products that consume different materials depending on the options a customer picks, and in-house manufactured designs assembled ahead of time in batches.

**Components are WooCommerce products.** Nothing lives in a parallel system: a raw material is just a product flagged as a component, hidden from the storefront if it isn't meant to be sold on its own.

**Bills of Materials (BOMs)** attach a recipe of components to a product. A line can be unconditional ("always consume this"), tied to a variation attribute ("only when Straw = Upgraded"), or tied to an add-on field from a product-options plugin ("only when Add Stickers = Yes") — so a new material (a new color, a new upgrade) is a new BOM line, never new code.

**Made-to-order products** consume their BOM automatically when an order is placed, and restore it automatically on cancellation or a restocking refund — always from a per-order snapshot taken at sale time, never by re-resolving the current BOM, so editing a recipe later can never corrupt an old order's numbers. The storefront shows a live "buildable" stock count computed from on-hand component stock, and blocks add-to-cart for any specific option combination that's short a component.

**Manufacture Orders** batch-convert components into a finished-good listing — for example, 12 blank tumblers and a batch of glitter become "Pink Glitter Tumbler" with a stock of 12 — and are fully reversible, restoring components from a build-time snapshot (with an optional per-component "scrap" flag for materials that can't be recovered, like cured epoxy).

**Every stock change is ledgered.** Receiving, cycle counts, manual adjustments, order consumption, refund restoration, and manufacture builds/reversals all pass through one row-locked stock service and write an audit-trail row — nothing moves stock silently.

**Also included:**

* A dedicated "BOM & Stock" admin menu: Component Inventory (receive / cycle count / adjust, all in one screen), Manufacturing (create, complete, and reverse manufacture orders), Reports (buildable stock, low stock, margin, component usage, full ledger), an Endpoints reference page, and Settings.
* CSV import/export for BOMs, keyed by SKU.
* A REST API and WP-CLI commands (`wp wcbom seed`, `wp wcbom audit`, `wp wcbom recompute`, `wp wcbom import`) for scripting and recovery.
* A low-stock digest email, sent on a schedule you control.
* Optional BOM-derived shipping weight and per-option price surcharges for customizations, stacking automatically as components are added.
* Full HPOS (High-Performance Order Storage) compatibility, and works with both the block-based and classic shortcode checkout.

= Recommended companion plugins =

Not required, but this plugin is designed to work well with:

* **Variation Swatches for WooCommerce** — visual swatches for color/style variation attributes.
* **Extra Product Options for WooCommerce (ThemeHigh)** — text personalization, upgrades, and file uploads, wired into add-on-conditional BOM lines.

A dismissible notice offers one-click install/activation for either if they aren't already active.

== Installation ==

1. WooCommerce must be installed and active.
2. Upload the plugin files to `/wp-content/plugins/wc-bom-stock`, or install the zip through the Plugins screen.
3. Activate the plugin.
4. Go to **BOM & Stock** in the admin menu. If you'd like to explore with sample data first, run `wp wcbom seed` (WP-CLI) or use the "Install sample products" prompt on the Component Inventory screen when no components exist yet.
5. Flag a product as a component (and set its unit) on its own edit screen, then attach a Bill of Materials to any product you want to build from components, on that product's "Bill of Materials" tab.

== Frequently Asked Questions ==

= Does this replace WooCommerce's own inventory management? =

No. Simple, standalone products you stock and sell directly still use WooCommerce's normal inventory tab exactly as before. This plugin adds a layer on top for products whose stock is *derived* — consumed from shared components, or built in batches — rather than tracked as an independent number.

= Can I add a new material (a new color, a new upgrade) without a developer? =

Yes. Every material is a BOM line keyed on a variation attribute or an add-on field value. Adding a new option to an existing product and a matching conditional BOM line is a merchant-level task, not a code change.

= Can choosing an option change the price? =

Yes, in two ways: price the option as a variation (native WooCommerce pricing, works out of the box), or attach a surcharge to a conditional BOM line so choosing that option adds to the price and consumes the component in one line. A BOM line should only ever use one of these, not both.

= What happens if a component runs out mid-checkout? =

Add-to-cart is blocked ahead of time for any option combination that's short a component. If an order is placed anyway (for example, a race between two simultaneous checkouts), consumption still proceeds — stock can go negative — and the order is flagged loudly for the merchant to reconcile, since refusing to fulfil an already-paid order is worse than a stock discrepancy.

= Is this compatible with High-Performance Order Storage (HPOS)? =

Yes, and with both the block-based and the classic shortcode checkout.

== Changelog ==

= 0.1.0 =
* Initial release: BOM editor, order consumption/restoration, buildable stock, Component Inventory screen, Manufacture Orders, reports, CSV import/export, REST API, WP-CLI commands, low-stock digest, BOM-derived weight/surcharges, HPOS compatibility.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
