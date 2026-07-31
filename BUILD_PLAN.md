# WooCommerce BOM & Stock Management Plugin — Build Plan

**Working name:** `wc-bom-stock` (WooCommerce BOM & Stock Manager)
**Author:** Poor Vida
**Status:** Scoped 2026-07-29, build to run on home machine.

---

## 1. What this plugin does

A WordPress plugin that adds **Bill of Materials (BOM)** and **component-level stock management** to WooCommerce, for stores that sell:

1. **Premade finished goods** — normal WooCommerce products with their own stock (e.g., "Pink Glitter 24oz Tumbler", stock: 12).
2. **Made-to-order customizable products** — the customer picks colors/text/design options; each sale consumes **component** stock (e.g., 1 blank tumbler + vinyl + glitter) instead of finished-good stock.
3. **In-house manufactured designs** — the shop batch-builds finished goods from components ("manufacture 12 pink glitter tumblers"), which consumes components and creates/increments a finished-good product listing. **Reversible** (disassemble back into components).

### Worked example (the tumbler)

- Component products: `24oz Blank Tumbler` (stock 100), `Glitter - Pink` (stock 500 g), `Vinyl Sheet` (stock 40), `Standard Straw`, `Upgraded Metal Straw`, `Standard Cap`, `Upgraded Cap`, `Sticker Pack`, `Epoxy` …
- The **blank tumbler is itself sellable** on the site *and* is a raw material. One shared stock pool covers both.
- Customer orders a customized tumbler → blank tumbler stock −1, plus whatever options they picked (upgraded straw → metal straw −1).
- Shop runs a **Manufacture Order**: "Build 12 × Pink Glitter Tumbler" → blank −12, pink glitter −(12 × 15 g), epoxy −(12 × 1) → product `Pink Glitter 24oz Tumbler` created (or found) with stock +12.
- Overbuilt? **Reverse** the manufacture order (fully or partially): finished stock −N, components +N×recipe.

---

## 2. Core architecture decisions

### 2.1 Components are WooCommerce products (not a separate entity)

**Decision: model every component as a WC product** (simple product, or variation), with a per-product flag `_wcbom_is_component`.

Why:
- The blank tumbler is *already* a sellable product — a separate component entity would force double-entry stock.
- Free reuse of WC stock fields, SKUs, suppliers/meta, low-stock emails, import/export, and every inventory plugin that already understands products.
- Components that should never be sold directly (glitter, epoxy) are set to catalog visibility **hidden** / status **private**. The plugin offers a one-click "create hidden component" flow so this is painless.

### 2.2 BOM = custom table, not post meta

Recipes and stock movements are relational data queried in aggregate (e.g., "compute buildable stock for 200 products", "which products use component X?"). Post meta makes those queries miserable. Use **custom tables** via `dbDelta` (schema §4).

### 2.3 Three product behaviors (per-product setting)

| Mode | Stock behavior on sale |
|---|---|
| **Standard** (default) | WC native — untouched by plugin |
| **Made-to-order (BOM-driven)** | Own stock not managed; sale consumes BOM components; displayed stock = *computed buildable quantity* (phantom stock, §5.3) |
| **Manufactured finished good** | Has real own stock (set by manufacture orders); BOM stored for costing + reversal, **not** consumed at sale time |

### 2.4 Option-conditional BOM lines (this is the key extensibility feature)

A BOM line can be **always** consumed, or **conditional** on:
- a **variation attribute** value (e.g., `pa_straw = upgraded` → consume 1 Metal Straw), or
- a **product add-on field/value** (integration with Product Add-Ons–style plugins, §7.2).

This is how you add stickers, glitter, paint, upgraded straws, upgraded caps later **without code changes** — just add a new component product and a conditional BOM line.

### 2.5 Every stock change goes through a ledger

All plugin-driven stock movements are written to an append-only **stock ledger** (who, what, why, how much, reference to order/manufacture-order). This is the audit trail, the reversal mechanism's source of truth, and the debugging lifeline. Never adjust stock without a ledger row.

---

## 3. Plugin structure

```
wc-bom-stock/
├── wc-bom-stock.php              # Bootstrap: constants, autoload, activation hook
├── composer.json                 # PSR-4 autoload (WCBOM\ → src/), dev deps
├── uninstall.php                 # Optional table cleanup (behind a setting)
├── src/
│   ├── Plugin.php                # Wiring/service container, hook registration
│   ├── Install/Schema.php        # dbDelta table definitions + versioned migrations
│   ├── Bom/
│   │   ├── Bom.php               # BOM aggregate (lines, conditions)
│   │   ├── BomRepository.php
│   │   └── ConditionMatcher.php  # Resolves conditional lines against cart-item data
│   ├── Stock/
│   │   ├── Ledger.php            # Append + query stock ledger
│   │   ├── StockService.php      # Atomic multi-product stock ops (row-locked)
│   │   └── PhantomStock.php      # Buildable-qty computation + caching
│   ├── Orders/
│   │   ├── OrderSync.php         # WC order lifecycle hooks (reduce/restore)
│   │   └── RefundHandler.php
│   ├── Manufacture/
│   │   ├── ManufactureOrder.php  # States: draft → completed → reversed (or partial)
│   │   ├── ManufactureService.php# build / reverse, product create-or-attach
│   │   └── ProductFactory.php    # Create finished-good listing from template
│   ├── Admin/
│   │   ├── ProductBomMetabox.php # BOM editor tab on product edit screen
│   │   ├── ManufacturePage.php   # Manufacture Orders admin screen
│   │   ├── LedgerPage.php        # Stock movement log screen
│   │   ├── ReportsPage.php       # Buildable stock, component usage, low stock
│   │   └── Settings.php          # WC Settings tab
│   ├── Rest/Api.php              # /wp-json/wcbom/v1/* endpoints
│   ├── Integrations/
│   │   ├── ThemeHighEpo.php      # Map ThemeHigh EPO field values → conditional BOM lines
│   │   └── Hpos.php              # HPOS compatibility declaration
│   └── Cli/Commands.php          # WP-CLI: audit, rebuild phantom cache, import
├── assets/                       # Admin JS/CSS (built with @wordpress/scripts)
│   └── src/bom-editor/           # React BOM editor + manufacture UI
├── templates/                    # Front-end template overrides (stock display)
└── tests/                        # PHPUnit + wp-env integration tests
```

**Stack:** PHP 8.1+, WordPress 6.5+, WooCommerce 8.5+ (declare HPOS + cart/checkout-blocks compatibility). Admin UI in React via `@wordpress/scripts`; everything else plain PHP. No framework dependencies.

---

## 4. Database schema

```sql
-- BOM header (one active BOM per product/variation; versioned for history)
CREATE TABLE {prefix}wcbom_boms (
  bom_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    BIGINT UNSIGNED NOT NULL,   -- product OR variation ID
  version       INT UNSIGNED NOT NULL DEFAULT 1,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL,
  created_by    BIGINT UNSIGNED NOT NULL,
  KEY product_active (product_id, is_active)
);

-- BOM lines
CREATE TABLE {prefix}wcbom_bom_items (
  item_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bom_id        BIGINT UNSIGNED NOT NULL,
  component_id  BIGINT UNSIGNED NOT NULL,   -- WC product/variation ID
  qty           DECIMAL(12,4) NOT NULL,     -- fractional for grams/cm (§5.6)
  condition_type ENUM('always','attribute','addon') NOT NULL DEFAULT 'always',
  condition_key  VARCHAR(191) NULL,          -- e.g. 'pa_straw' or addon field name
  condition_value VARCHAR(191) NULL,         -- e.g. 'upgraded'
  surcharge     DECIMAL(12,4) NULL,           -- optional customer-facing price add-on when this line matches (§5.10)
  sort_order    INT NOT NULL DEFAULT 0,
  KEY bom (bom_id), KEY component (component_id)
);

-- Manufacture orders
CREATE TABLE {prefix}wcbom_manufacture_orders (
  mo_id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    BIGINT UNSIGNED NOT NULL,   -- finished good produced
  bom_id        BIGINT UNSIGNED NOT NULL,   -- recipe snapshot reference
  qty_built     INT NOT NULL,
  qty_reversed  INT NOT NULL DEFAULT 0,     -- supports partial reversal
  status        ENUM('draft','completed','partially_reversed','reversed') NOT NULL,
  notes         TEXT NULL,
  created_by    BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  completed_at  DATETIME NULL,
  KEY product (product_id), KEY status (status)
);

-- Snapshot of exactly what each MO consumed (so reversal is exact even if BOM changes later)
CREATE TABLE {prefix}wcbom_manufacture_order_items (
  moi_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mo_id         BIGINT UNSIGNED NOT NULL,
  component_id  BIGINT UNSIGNED NOT NULL,
  qty_per_unit  DECIMAL(12,4) NOT NULL,
  qty_total     DECIMAL(12,4) NOT NULL,
  unit_cost     DECIMAL(12,4) NULL,         -- cost snapshot for COGS
  KEY mo (mo_id)
);

-- Append-only stock ledger
CREATE TABLE {prefix}wcbom_stock_ledger (
  ledger_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    BIGINT UNSIGNED NOT NULL,
  delta         DECIMAL(12,4) NOT NULL,     -- signed
  stock_after   DECIMAL(12,4) NULL,
  reason        VARCHAR(32) NOT NULL,       -- order|order_restore|refund|manufacture|manufacture_reverse|manual_adjust|import|received|cycle_count|po_receive (was ENUM; widened 2026-07-30 so new movement types never need a migration — po_receive added 2026-07-30, Phase 9)
  ref_type      VARCHAR(32) NULL,           -- 'wc_order' | 'manufacture_order' | 'purchase_order' | ...
  ref_id        BIGINT UNSIGNED NULL,
  user_id       BIGINT UNSIGNED NULL,
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL,
  KEY product_time (product_id, created_at), KEY ref (ref_type, ref_id)
);

-- Vendors (added 2026-07-30, Phase 9 §5.13 — created unconditionally, feature-gated at the UI/API layer)
CREATE TABLE {prefix}wcbom_vendors (
  vendor_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(191) NOT NULL,
  email         VARCHAR(191) NULL,
  phone         VARCHAR(64) NULL,
  website       VARCHAR(191) NULL,
  notes         TEXT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,  -- soft archive only; POs reference vendors forever, so vendors are never hard-deleted once referenced
  created_at    DATETIME NOT NULL,
  KEY active (is_active)
);

-- Purchase orders (added 2026-07-30, Phase 9 §5.13)
CREATE TABLE {prefix}wcbom_purchase_orders (
  po_id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id     BIGINT UNSIGNED NOT NULL,
  status        VARCHAR(32) NOT NULL,       -- draft|ordered|partially_received|received|cancelled (VARCHAR not ENUM, same reasoning as ledger.reason)
  reference     VARCHAR(191) NULL,          -- the vendor's own order/invoice number
  expected_date DATE NULL,
  notes         TEXT NULL,
  created_by    BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  ordered_at    DATETIME NULL,
  closed_at     DATETIME NULL,              -- set when received-in-full or cancelled
  KEY vendor (vendor_id), KEY status (status)
);

-- PO lines (added 2026-07-30, Phase 9 §5.13)
CREATE TABLE {prefix}wcbom_po_items (
  poi_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id         BIGINT UNSIGNED NOT NULL,
  component_id  BIGINT UNSIGNED NOT NULL,   -- WC product/variation ID
  qty_ordered   DECIMAL(12,4) NOT NULL,
  qty_received  DECIMAL(12,4) NOT NULL DEFAULT 0,  -- cumulative across partial receipts
  unit_cost     DECIMAL(12,4) NULL,         -- per-unit price paid to the vendor (historical record only — see §5.13 on why this never writes product prices)
  KEY po (po_id), KEY component (component_id)
);
```

Product meta (small per-product flags stay as meta):
- `_wcbom_mode` : `standard | made_to_order | manufactured`
- `_wcbom_is_component` : yes/no (component picker filter; blocks accidental deletion, §9.4)
- `_wcbom_unit` : display unit for the component (`ea`, `g`, `ml`, `cm`, `sheet`)
- `_wcbom_component_cost` : unit cost for COGS roll-up (optional)

---

## 5. Feature specifications

### 5.1 BOM editor (product edit screen)

New **"Bill of Materials"** tab in the WooCommerce product data panel:
- Searchable component picker (AJAX, filtered to `_wcbom_is_component` products, with "create new component" inline).
- Per line: component, qty (decimal), unit (read from component), condition (Always / Attribute=value / Add-on=value), drag-to-sort.
- For **variable products**: BOM at parent level applies to all variations; a variation can override with its own BOM. Attribute-conditional lines usually make variation-level BOMs unnecessary.
- Live panel: current buildable quantity, total component cost roll-up, and a "components in shortage" warning.
- Saving creates a new BOM **version** (old versions retained; MOs snapshot anyway).

### 5.2 Order-driven component consumption (made-to-order)

Hook points (use these, don't invent order state tracking):
- `woocommerce_reduce_order_stock` / per-item `woocommerce_reduce_order_item_stock` — resolve each order item's BOM (base + conditions matched against variation attributes and add-on data on the line item), decrement components via `StockService`, write ledger rows referencing the order.
- Set order item meta `_wcbom_consumed` = JSON of exactly what was consumed — restoration must mirror actual consumption, not re-resolve the (possibly edited) BOM.
- `woocommerce_restore_order_stock` + cancellation/failed transitions — restore from `_wcbom_consumed`.
- Refunds (`woocommerce_order_refunded`): if "restock items" checked on the refund, restore components proportionally to refunded quantity.
- Respect WC's "hold stock" / pending-payment flow: consume when WC reduces stock (on-hold/processing per gateway), not at checkout render.

**Concurrency:** two simultaneous orders must not oversell the last blank. `StockService` does `SELECT ... FOR UPDATE` on the affected product stock rows inside one transaction (or `$wpdb->query` with row locks against `wp_postmeta`/`wc_product_meta_lookup`), mirroring how WC core avoids races. Single code path for *all* multi-component operations (orders and MOs).

### 5.3 Phantom (buildable) stock display

For made-to-order products, displayed/purchasable stock = 
`min( floor(component_stock ÷ qty_per_unit) )` across **always** lines (conditional lines validated at add-to-cart instead — see below).

- Filter `woocommerce_product_get_stock_quantity`, `woocommerce_product_is_in_stock`, and backorder checks for `made_to_order` products.
- **Cache** the computed value in a lookup (transient or a column in the lookup table), invalidated whenever any underlying component's ledger changes. Never compute per-page-load per-product with live queries on the shop grid.
- Add-to-cart / checkout validation re-checks the **full** conditional BOM for the chosen options ("Upgraded metal straws are out of stock" even if blanks are plentiful).
- Component stock pages get a reverse view: "used in N products; selling those consumes this."

### 5.4 Manufacture Orders

Admin screen: **BOM & Stock → Manufacturing** (own top-level admin menu, not WooCommerce's — see §14.8).

Create MO flow:
1. Pick what to build:
   - **Existing product** (restock an in-house design), or
   - **New product from template**: pick a template/base product (e.g., the customizable blank listing), enter title, attribute/option choices (which also drive the conditional BOM), price, photos later. `ProductFactory` duplicates the template, strips the customizer, sets mode = `manufactured`, links the BOM.
2. Enter quantity. UI shows required components vs. on-hand, flags shortages (build blocked unless "allow negative" setting is on).
3. **Draft** state = planned, nothing moved (lets you stage builds / print a pick list).
4. **Complete**: atomically consume components (+ ledger rows), snapshot lines to `manufacture_order_items` with unit costs, increment finished product stock (+ ledger row), status → `completed`.

Reverse MO flow:
- On any completed MO: "Reverse N units" (N ≤ qty_built − qty_reversed).
- Requires finished-good stock ≥ N (can't disassemble sold units); components restored **from the snapshot**, finished stock decremented, status → `partially_reversed`/`reversed`. Ledger rows for everything.
- Optional per-line "scrap" checkbox on reversal — a component ruined during manufacture (glitter, epoxy) shouldn't be restored; write a `manual_adjust` ledger row noting scrap instead.

Extras: printable pick list per MO; MO list filterable by status/product/date; clone MO.

### 5.5 Reporting & alerts

- **Buildable stock report**: every made-to-order product, its bottleneck component, buildable qty.
- **Component usage report**: for a component — which BOMs use it, consumption over the last 30/90 days, simple run-rate days-of-stock estimate.
- **Low stock**: WC's native low-stock threshold works per-product already; add a digest email/webhook option that understands components ("Pink glitter below threshold — blocks 3 products").
- **Ledger browser**: filter by product, reason, date, reference; CSV export.
- **COGS**: MO snapshots capture unit costs → finished-good cost = Σ(component costs); surface on the product and in a simple margin report (price vs. cost).

### 5.6 Units of measure & fractional quantities

Glitter isn't consumed in "each" — it's grams. WC stock is integer by default:
- Component qty in BOMs is `DECIMAL(12,4)`.
- For bulk components, recommend stocking in the smallest sensible unit as integers (grams, ml, cm) — set `_wcbom_unit` so the UI reads "15 g" not "15". This avoids fighting WC's integer stock.
- (Fallback: `woocommerce_stock_amount` filter can allow float stock, but integer-in-small-units is more robust with third-party plugins.)

### 5.7 Inventory management screen (receive / count / adjust) — added 2026-07-30

**BOM & Stock → Inventory** (React admin page, own top-level menu): every component's stock managed from one place — never open a product edit screen to change inventory. Three distinct workflows, each writing its own ledger reason so reports stay meaningful:

| Action | Input | Ledger reason | Semantics |
|---|---|---|---|
| **Receive** | quantity received (+ optional note/PO ref) | `received` | "X more arrived" — additive, the everyday workflow |
| **Count** | the *absolute* physically-counted number | `cycle_count` | System computes delta = counted − on-hand and shows the drift prominently; this is the cycle-count workflow |
| **Adjust** | signed delta + required note | `manual_adjust` | Damage, shrinkage, found stock — the exception path |

- Table lists all `_wcbom_is_component` products (filter/search; toggle to include all managed-stock products): name, SKU, unit, on-hand, used-in-N-BOMs, last movement (from ledger).
- **Receiving session UX:** enter quantities against several components, submit once — one row per component in the ledger, all attributed to the acting user.
- After any movement, show which made-to-order products' buildable counts changed (phantom stock provides this).
- Everything goes through `StockService` — full ledger trail with user attribution, same as every other stock path. (Third-party "stock manager" plugins remain compatible but write stock outside the ledger; `wp wcbom audit` will flag their edits as drift. Prefer this screen.)
- Ledger `reason` column becomes `VARCHAR(32)` (was ENUM) so new movement types (e.g. future `transfer`) never need a schema migration again.

### 5.8 Import/export & CLI

- CSV import/export of BOMs (columns: parent SKU, component SKU, qty, condition). Critical for initial setup of a large catalog and for the "expand materials later" workflow.
- WP-CLI: `wp wcbom audit` (ledger vs. actual stock drift check), `wp wcbom recompute` (phantom stock cache rebuild), `wp wcbom import <file>`.

### 5.9 REST API

`/wp-json/wcbom/v1/`: `boms` (CRUD), `manufacture-orders` (create/complete/reverse/list), `ledger` (read), `buildable/{product_id}`. Auth via standard WC REST keys / application passwords. Enables future dashboards or a Cloudflare Worker front-end if you ever want one.

---

### 5.10 Shipping weight, dimensions & price for customized products (added 2026-07-30)

How physical/shipping attributes behave across our two option mechanisms — verified against WooCommerce core and the *installed* free EPO's source, not vendor marketing:

**What already works natively (nothing to build):**
- **Variations carry their own price, weight, AND dimensions (L/W/H)**, falling back to the parent product's values when a field is left empty. Since choice-type options (colors, sizes, upgraded caps) are variation attributes per the customizer decision (§10), any option that changes size/weight/price is already fully supported — per combination. There is no "stacking" arithmetic in WooCommerce: each variation is one concrete combination and you enter its true final weight/dims/price. Exact, but data entry grows with the number of combinations; acceptable at tumbler scale (a handful of physical-impact combos), bulk-editable when not.

**What the free EPO cannot do (verified in its code, 2026-07-30):**
- **Price:** the free version's frontend applies NO price changes to the cart — every pricing method (flat fee, percentage, dynamic) is Pro-only (~$39/yr). The free admin UI shows price columns in field settings, but nothing in the public class ever charges them. Consequence, reaffirming §10: **anything that costs money should be a variation attribute**; buy EPO Pro only if a personalization field itself must carry a fee (e.g. an engraving charge).
- **Weight/dimensions: nothing, in free or Pro** — the only "weight" in its codebase is CSS `font-weight`. Add-ons plugins categorically don't touch shipping.

**The gap and the plan — BOM-derived shipping weight (Phase 4.5):**
For made-to-order products, the resolved BOM *is* the physical composition of the item — and components are WooCommerce products with their own weight fields. So compute cart weight from the recipe:

> effective weight = Σ (component weight × line qty) over the resolved BOM lines

- **Stacking is automatic by construction** — exactly what the developer asked for: the blank tumbler line contributes the base weight, and every conditional line that matches (upgraded metal straw, sticker pack, extra glitter grams) adds its component's weight × qty. Add a new weight-bearing material later and shipping stays correct with zero configuration — §2.4's extensibility story extends to shipping.
- **Opt-in per product** (`_wcbom_weight_from_bom` toggle on the BOM tab), because premade/manufactured products should keep their normal WC weight field.
- **Implementation surface:** set the weight on the cart item's product object (`woocommerce_add_cart_item` + `woocommerce_get_cart_item_from_session` → `$cart_item['data']->set_weight()`), which both classic and blocks checkout read when building shipping packages. Attribute-conditional lines resolve at cart time today (`ConditionMatcher::resolve_for_selection()`); addon-conditional lines join once EPO cart-item data is mapped (same defensive pattern as the order-item integration).
- Components without a weight contribute 0 (and the BOM editor should hint when a line's component lacks a weight while the toggle is on). Sample data gets realistic component weights so the feature demos out of the box.

**Add-on price surcharges without EPO Pro (added same day):** we are NOT locked out of add-on pricing by the free EPO. A conditional BOM line already binds an option choice to a material; an optional **`surcharge`** on the same line binds it to a customer-facing price too ("consumes 1 metal straw AND costs $5 more"). At cart time the same single line-resolution that computes weight also sets price: `base + Σ(surcharge of matched lines)` via the identical cart-item hooks — stacking automatic, identical semantics to weight. Plugin-agnostic (works with EPO free, any replacement, or plain attributes). Pure-service fees model cleanly as a hidden zero/unmanaged-stock "labor" component line with a surcharge — better bookkeeping than an invisible fee. **Guard: a choice prices through EITHER its variation OR a line surcharge, never both** (double-charge otherwise) — the BOM editor should warn when a surcharge sits on an attribute-conditional line whose variations also carry prices. Known limitation vs EPO Pro: no live price preview on the product page as options are picked; v1 convention is "(+$5)" in the option label, with the cart showing the true adjusted price. Schema: `wcbom_bom_items.surcharge DECIMAL(12,4) NULL` (shipped ahead of the feature, DB_VERSION 0.4.0, per §14.5's additive rule).

**Dimensions deliberately do NOT auto-stack.** Summing L/W/H is physically meaningless (two straws don't double any axis; packing is box-dependent). Policy: dimensions come from the variation's native fields (exact, works today) or the product default. If a real need emerges for add-on-driven size changes, the defensible semantic is a per-BOM-line **dimension override with max-per-axis**: effective dims = max per axis across the base product and every resolved line that declares an override (an upgraded lid that makes the package taller raises only H). Deferred until a tester actually needs it — recorded here so the semantics aren't re-invented badly later.

---

### 5.11 WooCommerce native Cost of Goods Sold (COGS) integration (added 2026-07-30, Phase 7)

the developer asked whether this plugin works with or integrates with WooCommerce's stock Cost of Goods Sold feature. **Today: no integration at all** — the only mention of COGS anywhere in `src/` is a docblock comment. This section specs closing that gap.

#### What WC's COGS feature actually is (verified against WC 10.9.4 source, not docs)

An opt-in feature (`cost_of_goods_sold` in `FeaturesController`, `enabled_by_default => false`, added ~WC 9.5, **not** experimental) that adds a per-product/per-variation **manually typed** cost field:

- `WC_Product::set_cogs_value()` / `get_cogs_value()` — the merchant-entered "defined value" (stored as `_cogs_value` meta). Both **no-op/return null when the feature is disabled** (`CogsAwareTrait::cogs_is_enabled()`), so nothing we do can break a store that leaves it off.
- `WC_Product::get_cogs_total_value()` — the *effective* value WooCommerce actually consumes, and critically **it applies the `woocommerce_get_product_cogs_total_value` filter** (`$total_value, $product`).
- Variations add `cogs_value_is_additive`: when false (the default), the variation's own value wins if set, otherwise it inherits the parent's; when true it's parent + own.
- **At order time**, `WC_Abstract_Order::calculate_totals()` → `calculate_cogs_total_value()` → per item `WC_Order_Item_Product::calculate_cogs_value_core()`, which reads `$product->get_cogs_total_value() × quantity` and **snapshots it onto the order item**. WC Analytics and the v4 REST API read that stored snapshot; refunds subtract proportionally (`get_cogs_refunded_for_item`).
- Optional `wc_product_meta_lookup.cogs_total_value` column, added/removed via a WooCommerce debug tool.

#### Why there's no functional overlap (and why it's worth integrating anyway)

WC's COGS is a **flat number a human retypes**; it has no concept of a recipe. A merchant would have to manually re-enter every tumbler's cost each time a component's price changed. This plugin already computes the real thing bottom-up — `Reports\MarginReport` derives live cost from current component prices per resolved variation, and `Manufacture\ManufactureOrderItem::$unit_cost` snapshots true cost at build time. So the two never fight; WC's field simply sits unused and stale, and its native Analytics profit numbers are wrong (or zero) for exactly the products this plugin exists to manage.

The win: feed our already-correct number into WooCommerce's own feature, so merchants who enable COGS get accurate profit reporting in **WooCommerce's native Analytics** — not only in our custom Reports screen.

#### Design decision: filter the effective value, don't write `_cogs_value` meta

**Verified empirically in the dev environment before writing this spec** (WC 10.9.4, COGS feature toggled on via `FeaturesController`): hooking `woocommerce_get_product_cogs_total_value` to return a test value caused a real order's per-item COGS snapshot to come out at exactly `value × quantity` (7.77 × 3 = 23.31, matching `$order->get_cogs_total_value()`) — proving one filter is sufficient to make order snapshots, and therefore Analytics, correct. No postmeta writing required.

This is deliberately the opposite of the approach `Stock\PhantomStock` had to take for stock (where mirroring into real `_stock` postmeta was *forced* on us, because WC's Store API stock reservation reads it via raw SQL — see the 2026-07-30 Progress Log entry). COGS has no such raw-SQL reader in the order path, so the clean filter approach works, and it's strictly better here:

- **No stale data by construction.** A derived value that's computed on read can never drift from its inputs. Mirroring into meta would need an invalidation cascade on every component price change, every BOM save, every variation edit — the exact class of bug that bit `PhantomStock` twice (variation-cache cascade, reseed poisoning).
- **Never overwrites merchant input.** We leave `_cogs_value` (the "defined value" the merchant sees and can type into on the product screen) completely untouched. If someone has typed a cost by hand, that field still says what they typed.
- **Cleanly reversible.** Turn the toggle off and WooCommerce is exactly as it was — nothing to clean up, nothing written.

#### Behavior spec

**New `Integrations\CogsProvider`** (`Integrations/` is the established home for third-party/core-feature bridges — `Hpos.php`, `ThemeHighEpo.php`), hooking `woocommerce_get_product_cogs_total_value`:

1. **Bail immediately** unless the product is `ProductMode::MADE_TO_ORDER` or `MANUFACTURED` and has a resolvable BOM — a standard product's COGS is none of our business, and returning `$value` unchanged keeps normal WooCommerce behavior intact.
2. **Opt-in per product** via a new `_wcbom_cogs_from_bom` meta checkbox on the BOM tab, with the same parent-fallback rule as `_wcbom_weight_from_bom` (§5.10). Rationale: a merchant may deliberately want a hand-typed cost that includes overhead we don't model. Consistency with the existing weight toggle matters more than saving a click.
3. **Cost source differs by product mode, deliberately:**
   - `MADE_TO_ORDER` → live BOM cost, resolved against that specific variation's attributes via `ConditionMatcher::resolve_for_selection()` (identical to `MarginReport::row()`), so a Blue/Upgraded tumbler reports a different cost than Pink/Standard. Nothing has physically been built yet, so live component prices *are* the best available cost.
   - `MANUFACTURED` → the **latest completed MO's snapshot `unit_cost`** for that product, falling back to live BOM cost if it's never been built. This is strictly more accurate: it's what the units in stock actually cost to make, not what they'd cost at today's prices. Requires one new `ManufactureRepository` query (latest completed MO for a product); the per-line `unit_cost` values are already recorded.
4. **Labor/overhead needs no new mechanism** — §5.10 already established modeling pure-service costs as a hidden unmanaged-stock "labor" component line. Such a line has a component price, so it flows into BOM cost automatically and COGS picks it up for free.
5. **Refactor, don't duplicate:** the Σ(component price × qty) loop currently lives inline in `MarginReport::row()`. Extract it to a shared `Reports\BomCost` (or a method on `BomRepository`) that both `MarginReport` and `CogsProvider` call, so the plugin can never report one cost in its own margin report and a different one to WooCommerce Analytics. **This is the most important structural part of the task** — two independent cost calculations that silently disagree would be worse than no integration.

#### Caveats to document, not solve

- **The product-edit COGS field will look empty** even when the integration is active, because it shows the *defined* value (`get_cogs_value()`) while we filter the *effective* one. Correct — we're not overwriting merchant input — but potentially confusing. Mitigation: a short read-only "Cost from BOM: $X.XX (used for WooCommerce COGS reporting)" line in the BOM tab, next to the toggle. Explicitly **not** writing the value into the field just to make the UI look consistent; that reintroduces every staleness problem the filter approach avoids.
- **Historical orders aren't retroactively corrected.** WC snapshots COGS at order time; orders placed before enabling this keep their old (likely zero) values. Correct and expected — a snapshot is a historical record. `wp wcbom recompute` should NOT touch them.
- **No-op when the COGS feature is off.** `get_cogs_total_value()` returns 0 before ever reaching our filter, so the integration is inert on the (default) disabled setting. Worth stating plainly in `readme.txt` so nobody reports it as a bug.

#### Acceptance tests (extend §9)

14. With the COGS feature **off**, the filter is inert and no product/order COGS value changes.
15. With COGS **on** and the toggle on, a made-to-order variation's `get_cogs_total_value()` equals its BOM cost for that exact attribute combination — and two variations with different conditional lines report *different* costs.
16. A real order for such a variation snapshots per-item COGS = BOM cost × quantity (the exact mechanism verified by hand above; make it a regression test).
17. A `MANUFACTURED` product reports its latest completed MO's snapshot unit cost, not live BOM cost — and correctly falls back to live BOM cost when never built.
18. `MarginReport`'s cost and `CogsProvider`'s cost agree for the same product/variation (the shared-calculation guarantee).
19. A standard (non-BOM) product's COGS is completely untouched.

**Estimate: ~half a day.** Low risk: one filter, one opt-in toggle, one extracted shared calculation, no schema change, no migration, and inert unless a merchant opts into both WC's feature and ours.

---

### 5.12 In-app documentation & training module (added 2026-07-30, Phase 8)

the developer asked for a full documentation/training module built into the plugin — screenshots plus explanations of every feature, enough to train a new user — and asked whether we should include the embedded YouTube training videos he'd seen in ThemeHigh EPO and CartFlows' swatches plugin. He also set the ordering rule: **documentation is built last, so nothing gets created after it and left out of training.**

#### First, a correction on the third-party videos (verified in both plugins' source)

Neither companion plugin actually embeds a video in its admin UI:

- **ThemeHigh EPO** renders a floating "quick widget" popup containing outbound links — "Get support" (their wordpress.org forum) and **"Video Tutorial", a plain `target="_blank"` link to `youtube.com/watch?v=YoVPQhdwuis`** with a red YouTube icon. It's a link out, not an embed. (The icon is almost certainly what read as "embedded video" — an easy and reasonable misread.)
- **Variation Swatches for WooCommerce** has a `[youtube ...]` shortcode in its **`readme.txt`**, which renders only on the wordpress.org plugin *directory* page. There is nothing video-related anywhere in its admin UI — so there is no "page in their app containing their video" to link to.

#### Decision: link to third-party videos, never embed them

Recommended approach, and the reasoning so it isn't relitigated:

1. **Privacy.** A YouTube `<iframe>` loads Google tracking into wp-admin on every render. A store owner doesn't expect their admin to phone out to Google because they opened a help page, and plugins that pull external resources into admin are rightly viewed poorly. If we ever embed anything, it must be **click-to-load** (a static thumbnail that only fetches the iframe after a deliberate click), never auto-load.
2. **We don't control the content.** Hardcoding a video ID we don't own means ThemeHigh silently replacing or deleting that tutorial points our training material at a dead or wrong video, and nothing in our test suite can catch it. A link that 404s is a mild annoyance; an embed that renders someone else's unrelated (or Pro-upsell) video *inside our training module* looks like our mistake.
3. **It's their curriculum, not ours.** Their video teaches their plugin and markets their paid upgrade. That's genuinely useful as a **pointer inside our "companion plugins" section** — where a user is deliberately setting up EPO or swatches — and out of place as a unit of *our* training flow.
4. **Show them contextually.** Only surface the EPO/swatches pointers when that plugin is actually active; `Admin\RecommendedPlugins` already does this detection, so reuse it rather than linking users to setup instructions for software they haven't installed.

**Our own videos: leave a seam, don't block on them.** The content model below lets any section optionally carry a video (title + URL), so if the developer records screencasts later it's a data change, not a code change. Whether those are self-hosted or YouTube is a decision for that day; the click-to-load rule applies either way.

#### Architecture: plain PHP, matching the established split

A new **`Admin\GuidePage`** ("BOM & Stock → Guide"), rendered in plain PHP — **not** React. This follows the existing, deliberate convention: React for genuinely interactive surfaces (BOM editor, Inventory, Manufacturing, Reports), plain PHP for static ones (Endpoints, Settings). Documentation is static content, so React would add a build step, bundle weight, and exposure to the `@wordpress/components` runtime drift called out in §12 risk 4 — for nothing.

**Content model: PHP files returning structured arrays**, one section per entry: `id`, `title`, `body` (HTML), `screenshots` (path + alt text), `links`, optional `video`. Deliberately **not** Markdown files: a parser would be the plugin's first runtime dependency beyond the autoloader, and Markdown content can't pass through `__()`, which would make the entire training module untranslatable right after we got a clean POT (see the i18n Progress Log entry). Long-form prose inside `__()` is slightly awkward for translators, but it's standard WordPress practice and keeps the text domain honest.

**Also add WordPress-native contextual help tabs** (`get_current_screen()->add_help_tab()`) on each of the five plugin screens: two or three sentences plus a deep link into the matching Guide section. Cheap, native, needs no screenshots, and it appears exactly where a confused user looks first — complementing the full guide rather than duplicating it.

#### Screenshots: generated, never hand-captured

This is the part that determines whether the module is still accurate in six months. **Hand-captured screenshots rot on the first UI change**, and this project has already changed admin UI repeatedly (menu reorg, page retitle, modal spacing).

Spec: a dev-only generator script (`bin/capture-docs-screenshots.mjs`, driven by **Playwright as a `devDependency`** — dev-only, never shipped), exposed as `npm run docs:screenshots`, that drives the seeded wp-env environment through each admin screen and writes PNGs to `assets/docs/`.

Requirements that make the output stable and reviewable:

- **Run against a freshly reset fixture** (`wp wcbom seed --reset`) at a **fixed viewport** (1440×900). Without determinism, every regeneration produces churn from shifting product IDs and stock numbers, and the diffs become unreviewable.
- **Downscale to ~1200px wide and optimize**; note that these assets add real weight to a currently-tiny release zip, so keep the set tight (roughly 15–20 images, one per meaningful screen/state, not one per click).
- **`bin/build-release-zip.sh` must add `assets/docs` to its shipped file list** — exactly the gap `readme.txt` had until Phase 6.
- **Every screenshot needs alt text**, wrapped in `__()` like the rest of the content.
- Screenshots come from the local fixture, so they show only our own original sample catalog (GPL, per the sample-data Progress Log entry) — no real store data, no credentials.

**Also include deep links into the live screens** alongside the screenshots ("Open Component Inventory →"). Links never go stale the way images do, and a trainee ends up in the real UI anyway; the screenshot is there so they know what they're looking for before they arrive.

#### Guaranteeing nothing is left out

the developer's "build docs last" rule is right, but it only prevents omission *at that moment* — it does nothing about drift afterward, which is the larger risk. Two mechanisms:

1. **Automated coverage enforcement** (a PHPUnit test): assert that every registered plugin admin page, every `wcbom/v1` REST route, and every `wp wcbom` CLI subcommand maps to a documented section id. A future feature shipped without documentation **fails the test suite**. This turns the requirement into something enforced rather than remembered — the same instinct behind `Admin\EndpointsPage` reading routes live instead of maintaining a hand-written list.
2. **A standing rule** (added to CLAUDE.md's conventions): any change that adds or alters a user-facing surface updates the Guide in the same session. The generator script above is what keeps that cheap enough to actually happen.

#### Content outline (the full user-facing surface, so nothing is missed)

1. **Orientation** — the core mental model: components are products; BOMs are recipes; the three product modes (`standard` / `made_to_order` / `manufactured`); every stock change is ledgered.
2. **First-run setup** — install/remove sample data, flagging a product as a component, choosing units (and why bulk materials are stocked in grams, §5.6).
3. **Building a BOM** — the product BOM tab: lines, always vs. attribute vs. add-on conditions, surcharge, the weight-from-BOM toggle, and why saving creates a new version.
4. **Selling made-to-order** — buildable ("phantom") stock, per-option add-to-cart blocking, what consumption writes, and how cancel/refund restore work from the snapshot.
5. **Component Inventory** — receive vs. cycle count vs. adjust, and when to use each.
6. **Manufacturing** — draft → complete → reverse, partial reversal, scrap, and new-product-from-template.
7. **Reports** — all five tabs (Buildable, Low Stock, Margin, Component Usage, Ledger).
8. **CSV import/export** — SKU-keyed, full-replace-per-parent semantics.
9. **Settings** — uninstall data policy, low-stock digest.
10. **For developers** — REST endpoints, WP-CLI commands, the Endpoints page.
11. **Companion plugins** — EPO and swatches setup; **the only place third-party video links belong.**
12. **Troubleshooting & recovery** — `wp wcbom audit`, oversell/shortage order flags, stock drift.
13. **What this plugin deliberately doesn't do** — no supplier PO tracking, no dimension stacking (§5.10), no live product-page price preview. Setting expectations up front is training, and it prevents support questions about absent features.

#### Acceptance criteria

- A new user can go from a fresh install to a working made-to-order product, a completed manufacture order, and a stock receipt using the Guide alone, without asking a question.
- `npm run docs:screenshots` regenerates every image against a reset fixture, and re-running it twice with no code change produces **no git diff** (proof the fixture and viewport really are deterministic).
- The coverage test fails if a page/route/command is added without a doc section.
- Contextual help tabs appear on all five plugin screens.
- No external resource loads on the Guide page unless the user clicks a video (verify with the browser network panel — this is the privacy claim, so it gets tested, not assumed).

**Estimate: ~2–3 days**, most of it writing content rather than code (screenshot tooling and the coverage test are maybe half a day combined). **Ordering: Phase 8, after Phase 7 (COGS, §5.11)** — and if anything further gets spec'd before this is built, docs move again behind it. That's precisely what the standing rule is for.

---

### 5.13 Vendors & purchase orders — strictly opt-in (added 2026-07-30, Phase 9)

the developer asked for vendor/PO tracking (deferred from v1 scoping, §6's out-of-scope note) with one hard requirement stated up front: **a merchant must be able to ignore this entire feature and keep managing inventory manually exactly as today — to the point that the whole section is invisible until deliberately turned on.**

#### The gap this closes

The plugin already says *when* to reorder (low-stock digest, §5.5's run-rate "days of stock" estimates) but has no memory of *having* reordered:
- Low-stock alerts keep flagging components that were ordered last week — no "on order" state exists to inform them.
- The Inventory screen's Receive action is a bare quantity + note, tied to nothing — not what was ordered, from whom, or at what price.
- There is no record anywhere of vendor pricing history.

#### Gating (the load-bearing design constraint)

- New option `wcbom_vendors_enabled`, default `'no'`, exposed as an "Enable vendors & purchase orders" checkbox on the existing Settings page (the only place the feature is visible when off).
- **When off:** the Purchasing admin page is not registered, its REST routes are not registered, reports/digest show no on-order columns, and nothing else changes — every existing manual flow (receive/count/adjust, seeding, audit) behaves identically to today. This is the same shape as WooCommerce's own COGS feature gate (§5.11): code inert, no dead UI.
- **Tables are created unconditionally** by `Schema::install()` and data survives toggling the feature off — turning it off hides the section, never destroys it (same reasoning as the §14.6 keep-data-by-default uninstall policy). All three tables and the option join `uninstall.php`'s purge list — the exact omission that bit `wcbom_ops` in Phase 6 must not repeat.
- Sample data (§5.7's seeder) deliberately does **not** create vendors/POs: the feature defaults off, and seeded sample rows would imply it's on.

#### Data model & lifecycle

Tables in §4 (`wcbom_vendors`, `wcbom_purchase_orders`, `wcbom_po_items`). Vendors are a lightweight custom-table entity (name/contact/notes/active flag) — **not** WC products or a CPT; they're never sellable, never stocked, and a CPT would buy nothing but admin-UI baggage. Soft-archive only (`is_active = 0`) once referenced by any PO, so PO history always resolves its vendor.

PO status machine, enforced in a `PurchaseOrderService` (mirroring `ManufactureService`'s "repository writes columns, service owns transitions" split):

- `draft` — editable lines, counts toward nothing. Deletable outright (same rule as draft MOs: nothing has moved).
- `ordered` — the merchant has actually placed it with the vendor (`ordered_at` stamped). Lines lock (quantities/costs stop being editable; cancel-and-redraft is the correction path). Begins counting toward on-order quantities.
- `partially_received` / `received` — receiving happens per-line ("received 480 of the 500 blanks"), cumulative into `qty_received`, `closed_at` stamped when every line is full. **Over-receipt is allowed** (vendors ship extra; refusing to record reality would be the §13 anti-pattern) — recorded as `qty_received > qty_ordered`, flagged visually, never blocking.
- `cancelled` — allowed from draft, ordered, or partially_received; stops counting toward on-order. **Deliberately the same action covers two different real-world reasons** (decided 2026-07-31, when the developer asked for a way to close out an under-received PO): "the order was called off before anything shipped" and "we're accepting a short delivery and closing this out." Both end in the same state — already-received stock stays received, the remainder simply stops being expected — so a separate "closed" status would only duplicate this transition for no behavioral difference. The React UI adjusts the button label/confirmation copy contextually (reads "Close" instead of "Cancel" when status is `partially_received`), but it's one `PurchaseOrderService::cancel()` method and one REST route underneath.

**Receiving is a stock write, so it uses the full existing machinery:** one `StockService::adjust_many()` call per receipt (new `Ledger::REASON_PO_RECEIVE = 'po_receive'`, `ref_type 'purchase_order'`, `ref_id` = po_id — the VARCHAR reason column widened 2026-07-30 makes this migration-free), wrapped in an `OperationGuard` idempotency key exactly like MO completion (§13.6), so a double-submitted receive can never double-stock.

**PO line `unit_cost` is a historical record and is never written to any product field.** Decided here so it isn't relitigated: a component's `regular_price` is this plugin's cost basis (§5.5, §5.11) *and* — for dual-role components like the blank tumbler — its live retail price. Auto-pushing a wholesale PO cost into it would corrupt storefront pricing. The never-implemented `_wcbom_component_cost` meta (§4) remains the natural future home for a true separated cost basis; explicitly out of scope here.

#### Freight, tax, and fees — amortized landed cost (added 2026-07-31)

Three additional PO-level fields — `freight_cost`, `tax_cost`, `fees_cost` (all nullable DECIMAL) — capturing the real cost of getting an order landed, beyond the per-line unit prices. **Editable at any PO status**, unlike the vendor/reference/line items (which lock once ordered): the real freight or tax bill routinely arrives after placing the order, or even after it's fully received, so a status restriction here would just force the merchant to re-open something that shouldn't need reopening. A dedicated `update_costs()` path (repository + service + `PUT /purchase-orders/<id>/costs`) exists separately from the draft-only `update_draft()` for exactly this reason.

**Amortized proportional to each line's ordered value** (`qty_ordered × unit_cost`) — the standard landed-cost basis — via a small dedicated `Purchasing\LandedCost` class, kept separate from the REST controller so the allocation math is unit-testable on its own. A line with no `unit_cost` entered has no value to allocate by, so it gets none of the fee total and shows "—" for landed cost; the PO view's total-fees line still reads the true total regardless, so a merchant sees at a glance which lines couldn't participate and knows to enter a unit cost for a complete breakdown — no silent loss, no invented secondary bucket.

**Display-only, same reasoning as `unit_cost` itself**: computed live from the current freight/tax/fee fields on every read, never written to a product field or fed into `Reports\BomCost`/`Integrations\CogsProvider`. Feeding it into cost/margin reporting was considered and explicitly deferred — it raises the question of which PO's landed cost a shared component should use when it's been ordered from multiple vendors at different freight rates, which is real complexity this addendum doesn't need to solve to deliver the requested landed-cost visibility.

#### Sending the PO by email (added 2026-07-31)

A "Send PO" action, available whenever a PO is past draft (same gating as "View" — sending a draft that was never formally placed doesn't make sense). Opens a small modal with two checkboxes, "Send to vendor" (pre-checked) and "Send a copy to myself" (the currently logged-in WP user, not necessarily whoever created the PO).

**Recipients are resolved from records already on file — never a typed-in address**, so this can never become an arbitrary-email relay: the vendor's own `email` field, and the current user's WP account email. A new `Purchasing\PurchaseOrderMailer` composes a plain-text summary (PO #, vendor, reference, line items at their plain `unit_cost` — not the amortized landed cost, which is this plugin's own internal analysis and not something to hand a vendor — plus the freight/tax/fees/grand-total footer) and sends one `wp_mail()` per resolved recipient via `POST /purchase-orders/<id>/send`.

**Partial success over hard failure**, matching this plugin's established §13 stance: if "to vendor" is checked but the vendor has no email on file, that's a warning in the response, not a blocking error — a copy still goes out to whichever recipient *does* resolve. Only throws when literally nothing could be sent (neither checkbox selected, or every selected recipient lacks a usable email).

#### Surfacing on-order awareness

When (and only when) the feature is on:
- `LowStockReport` rows and the digest email gain `on_order` = Σ(`qty_ordered` − `qty_received`) across that component's POs in `ordered`/`partially_received` status, plus the nearest `expected_date`. **Display-only**: a low component with stock on order is still listed (hiding real lowness because a PO *might* arrive would be lying to the merchant) — it just reads "500 on order, expected Aug 12" so the merchant knows not to reorder again.
- The Component Inventory screen shows the same on-order figure per component.

#### UI & API

- **One new admin page, "Purchasing"** (React, same stack/patterns as Manufacturing — including capturing `add_submenu_page()`'s return for the enqueue hook suffix, the §14.8 lesson), with a PO list tab (status-filterable, statuses as the default view) and a Vendors tab (simple CRUD). One page, one gate, one menu item — not two nav entries.
- Modals: new PO (vendor picker + component lines, same component-picker used by the BOM editor), receive (per-line qty inputs prefilled with outstanding amounts), cancel.
- **REST** (`wcbom/v1`): `/vendors` CRUD, `/purchase-orders` CRUD, `/purchase-orders/<id>/place`, `/receive`, `/cancel`. Registered only when the feature is on; same `manage_woocommerce` + nonce gate as everything else.
- **No new CLI subcommands in v1** of this feature (nothing here needs scripting yet); `wp wcbom audit` gains one informational check — PO lines referencing components that no longer exist (same drift class as the orphaned-BOM check queued in Phase 6).
- **Phase 8 note:** the docs coverage test must run with the feature enabled, or the Purchasing page/routes would be invisible to it and the Guide could ship without covering them.

**Estimate: ~2–3 days.** Structurally a sibling of Manufacture Orders (tables + repository + service + React page + REST), reusing StockService/OperationGuard/component-picker wholesale.

### 5.14 Nested BOMs / sub-assemblies (added 2026-07-30, Phase 10)

Deferred at scoping (§6's out-of-scope note); the developer pulled it in 2026-07-30. The use case: batch-build an intermediate good (e.g. "glittered blank") via a manufacture order, then use *that* as a component in other products' BOMs.

#### The one hard rule: sub-assemblies must be MANUFACTURED (or standard) products — never MADE_TO_ORDER

Verified against the actual code before writing this spec: `PhantomStock::compute()` reads each component's stock via `WC_Product::get_stock_quantity()`. For a MANUFACTURED component that's its real, physical count — correct by construction. But a MADE_TO_ORDER product's `get_stock_quantity()` is *filtered* (`StorefrontStock`) to return its own phantom buildable number, so allowing one as a component would (a) feed a derived fiction into another product's buildable math, silently double-counting the shared raw components both recipes draw from, and (b) **recurse infinitely at compute time on any A→B→A cycle** — compute(A) → get_stock_quantity(B) → filter → compute(B) → get_stock_quantity(A) → …. So made-to-order products are rejected as BOM components at save time, with a clear message. Physically-stocked sub-assemblies only.

#### What already works, verified rather than rebuilt (this phase is mostly validation + guards)

- **Consumption:** an MO completing (or an order consuming) a BOM whose line points at a manufactured sub-assembly already decrements its real stock through the row-locked `StockService` path — a component is just a product ID; nothing in the consumption path cares what mode it is.
- **Invalidation chain is already correct, including the subtle case:** sub-assembly B's stock changes only via MO complete/reverse or manual edit — both already fire the triggers `PhantomStock` listens to, so parent A's buildable refreshes. And a *raw material* C (used only in B's recipe) changing does **not** invalidate A — correctly, because A's buildable depends only on B's real on-hand stock, which didn't move.
- **Deletion guard, ledger, reports:** all keyed on component_id, mode-agnostic.

#### What's actually new

1. **Cycle detection in `BomRepository::save()`** — walk the active-BOM component graph from each proposed component; reject the save if the product being saved is reachable (covers direct self-reference and any longer cycle). Depth-capped walk (10 levels) as belt-and-braces; with made-to-order components banned, cycles among manufactured BOMs are the only cycle class left, and they'd corrupt MO planning even though they can't infinitely recurse at compute time.
2. **Mode validation in `BomRepository::save()`** — reject any component whose effective mode is `made_to_order` (rationale above, enforced at the single choke point every save path already goes through: REST, CSV import, seeder, tests).
3. **Buildable semantics documented, not deepened:** a made-to-order product with a manufactured sub-assembly line shows buildable = floor(sub-assembly *on-hand* ÷ qty). "Buildable-through" (counting what *could* be built from raw materials backing the sub-assembly) is explicitly out of scope — the shallow number is the true "sellable right now" count, and the deep one requires the recursion/double-count machinery this section exists to avoid.
4. **Costing note for docs:** a sub-assembly's cost contribution (margin report, §5.11 COGS) is its `regular_price`, same as any component — so merchants must price sub-assemblies even if hidden/unsellable, or downstream costs silently read 0. The *better* number for a built batch already exists (MO snapshot unit costs) but wiring it in per-component is out of scope; documented as a known approximation.

**Estimate: ~1 day**, most of it tests proving the "already works" claims above plus the two save-time guards.

## 6. Suggested features you didn't mention (recommend building)

1. **Stock ledger / audit trail** (§2.5) — without it, "why is my blank count wrong?" is unanswerable. *Build in phase 1.*
2. **Snapshot-based reversal** (§5.4) — if you edit a BOM after building, naive reversal restores the *wrong* components. Snapshots fix this. *Non-negotiable.*
3. **Refund/cancellation restocking of components** — WC only restocks the product on the order; without §5.2 handling, every refunded custom tumbler silently leaks a blank.
4. **Concurrency-safe stock ops** — two customers buying the last blank simultaneously, or an MO completing during a sale. Row-locked single code path.
5. **Draft MOs + pick lists** — plan a production day, print what to pull from shelves.
6. **Scrap/wastage tracking** — manufacture reality: some units fail. Scrap option on reversal + manual adjust with reason.
7. **Reserved stock awareness** — pending-payment orders hold stock in WC; phantom stock must account for holds or you'll oversell during checkout races.
8. **Component shortage gating at add-to-cart** for *option-specific* components (upgraded straw out of stock ≠ product out of stock).
9. **COGS roll-up** — you're capturing all the data anyway; margin visibility is nearly free.
10. **Run-rate reorder hints** — "at current sales pace, blanks last 18 days."
11. **Deletion guards** — block trashing a product that appears in an active BOM or has ledger history (§9.4).
12. **CSV BOM import/export** — future-proofs "add stickers/paint/caps later" at catalog scale.

### Deliberately out of scope for v1 (note for later)

- ~~**Nested BOMs / sub-assemblies**~~ **Pulled into scope 2026-07-30** — spec'd as §5.14 / Phase 10.
- ~~supplier purchase orders~~ **Pulled into scope 2026-07-30** — spec'd as §5.13 / Phase 9, strictly opt-in (default off, invisible until enabled).
- Multi-warehouse/location stock; barcode scanning; serial/lot tracking — still out.

---

## 7. Problems & pitfalls to plan around

### 7.1 Where does "customization" data live?
The customer-facing customizer (colors/text/design picker) is **not this plugin's job** — it's variations + an options/add-ons plugin (or a future custom customizer). This plugin consumes that data (attributes + add-on line-item meta) to resolve conditional BOM lines. **Decided (see §10):** variation attributes + Variation Swatches for choices; ThemeHigh EPO free for text/uploads/upgrades. The `Integrations/` layer keeps this swappable — its first implementation is `Integrations/ThemeHighEpo.php`. Text personalization ("engrave a name") usually consumes no extra components — condition-less lines cover it.

### 7.2 Third-party stock writers
Other plugins/imports can change component stock without ledger rows (that's fine — ledger records *plugin-driven* moves; `wp wcbom audit` reports drift). Do **not** fight WC core: always mutate stock via `wc_update_product_stock()` so `wc_product_meta_lookup` and caches stay coherent.

### 7.3 HPOS + Blocks
Declare compatibility with High-Performance Order Storage (`FeaturesUtil::declare_compatibility`) and use WC CRUD (`$order->get_items()` etc.), never direct post queries on orders. Checkout-blocks stores add-on data slightly differently than shortcode checkout — test both.

### 7.4 Variable products
A "24oz Blank Tumbler" might itself have variations (colors). Conditional BOM lines keyed on attributes usually suffice; per-variation BOM override is the escape hatch. Components can be variations too (`Glitter → Pink` variation) — component picker must support variation selection.

### 7.5 Performance
Shop-grid pages must not run BOM math per product. Cache buildable qty; invalidate on ledger writes touching an involved component (maintain a component→products reverse index in memory/cache).

### 7.6 Trust boundaries
All admin AJAX/REST behind `manage_woocommerce` capability + nonces. MO complete/reverse are money-adjacent operations — log the acting user in the ledger.

---

## 8. Build phases (each phase ships something testable)

### Phase 0 — Environment & scaffold (~half day)
- `wp-env` (Docker) with WP + WooCommerce + free `woo-extra-product-options` (ThemeHigh) + a free variation-swatches plugin, plus sample tumbler catalog seeded via script; or LocalWP if preferred.
- Plugin scaffold, composer PSR-4 autoload, activation hook creating tables, PHPCS (WordPress-Extra) + PHPStan level 6, `@wordpress/scripts` build for admin JS.
- Seed fixture script: blank tumbler, 6–8 components, one premade design, one made-to-order product. **Every later phase demos against this fixture.**

### Phase 1 — Data model, ledger, BOM editor (~2–3 days)
- Schema install/migrations; `Bom`/`BomRepository`; `Ledger` + `StockService` (row-locked, transactional).
- Product data tab BOM editor (React): CRUD lines, conditions, component picker, create-component inline.
- Product mode setting + component flag. Deletion guards.
- ✅ Demo: build the tumbler BOM in admin, see cost roll-up and buildable count.

### Phase 2 — Order consumption + restoration (~2–3 days)
- OrderSync: reduce/restore hooks, `_wcbom_consumed` item meta, refund handler, condition matching from attributes + add-on meta.
- ✅ Demo: place order for customized tumbler → blank −1, ledger rows; refund with restock → blank +1.

### Phase 3 — Phantom stock (~2 days)
- Buildable computation + cache + invalidation; storefront stock display/validation filters; add-to-cart conditional-component checks.
- ✅ Demo: set blanks to 3 → made-to-order product shows 3 available; buy 3 → out of stock; upgraded-straw stock 0 → only that option blocked.

### Phase 3.5 — Inventory management screen (~1–2 days, added 2026-07-30)
- **BOM & Stock → Inventory** React page per §5.7: all components in one table, Receive / Count / Adjust row actions + receiving-session bulk entry, everything through `StockService` with the new `received`/`cycle_count` ledger reasons.
- ✅ Demo: receive 50 blanks without touching the product edit screen → stock +50, ledger row, buildable count updates; cycle-count glitter at 480 g against a system 500 g → drift −20 shown and ledgered.

### Phase 4 — Manufacture orders (~3–4 days)
- MO CRUD screen, draft/complete/reverse (partial), snapshots, scrap handling, `ProductFactory` (new-listing-from-template flow), pick list print view.
- ✅ Demo: the exact scenario from the brief — manufacture 12 pink glitter tumblers, new listing appears with stock 12, blanks −12; reverse 4 → stock 8, blanks +4.

### Phase 4.5 — BOM-derived shipping weight & add-on surcharges (~1 day, added 2026-07-30) — ✅ **done and verified 2026-07-30, see CLAUDE.md Progress Log**
- Per §5.10: opt-in per-product toggle; cart-item weight = Σ(component weight × qty) over resolved BOM lines via the cart-item filters; BOM editor hint for weightless components; sample components gain realistic weights.
- Add-on surcharges per §5.10: optional per-line surcharge in the BOM editor; the same cart-time line resolution sets price = base + Σ(matched surcharges); double-charge warning for surcharges on attribute lines with variation pricing.
- ✅ Demo (verified, using the seeded Blue Glitter $2 surcharge in place of the brief's hypothetical sticker example): Blue/Standard added to cart → cart price $26.99 exactly ($24.99 + $2 surcharge); all four variation combinations' weight independently verified against hand-computed Σ(component weight × qty) — Pink/Standard 0.604, Pink/Upgraded 0.634, Blue/Standard 0.604, Blue/Upgraded 0.634 lbs, each exact.

### Phase 5 — Reporting, alerts, import/export, API (~2–3 days) — ✅ **done and verified 2026-07-30, see CLAUDE.md Progress Log**
- Ledger browser + CSV export, buildable/usage reports, low-component-stock digest, CSV BOM import/export, REST endpoints, WP-CLI audit/recompute.
- ✅ Demo (verified): `wp wcbom audit --fix` detected and correctly rebuilt a deliberately-stripped order-item consumption snapshot from ledger rows, restoring stock correctly on a subsequent cancel; BOM CSV round-tripped a qty/surcharge edit through both the admin-post download/upload and `wp wcbom import`; Reports screen's five tabs (Buildable/Low Stock/Margin/Component Usage/Ledger) all verified against the seeded catalog, including Margin correctly folding surcharges into price; new Endpoints admin page lists every registered route live.

### Phase 6 — Hardening & release prep (~2–3 days)
- Integration tests for every ledger-touching path (order, refund, cancel, MO complete/reverse, concurrent orders via parallel requests).
- HPOS on/off matrix, blocks vs. shortcode checkout, PHP 8.1/8.3, WC latest + latest−1.
- Uninstall behavior (keep data by default; opt-in purge). Readme, inline docs, i18n (`wcbom` text domain).

### Phase 7 — WooCommerce native COGS integration (~half day, added 2026-07-30) — ✅ **done and verified 2026-07-30, see CLAUDE.md Progress Log**
- Per §5.11: `Integrations\CogsProvider` hooking `woocommerce_get_product_cogs_total_value`; `_wcbom_cogs_from_bom` opt-in toggle on the BOM tab; extract the Σ(component price × qty) calculation out of `MarginReport` into something shared so our margin report and WooCommerce Analytics can never disagree; `MANUFACTURED` products source cost from their latest completed MO snapshot, made-to-order from the live per-variation BOM.
- ✅ Demo: enable WooCommerce's Cost of Goods Sold feature, place an order for a made-to-order variation, and see WooCommerce's **own** Analytics report the correct BOM-derived profit — with two different variations correctly reporting different costs, and the whole integration inert when either toggle is off.

### Phase 8 — In-app documentation & training module (~2–3 days, added 2026-07-30) — **must be built LAST**
- Per §5.12: `Admin\GuidePage` (plain PHP, structured-array content), WP-native contextual help tabs on all five screens, generated screenshots via a dev-only Playwright script (`npm run docs:screenshots`), a coverage test that fails when a page/route/CLI command has no doc section, and third-party companion-plugin videos **linked, never embedded**.
- **Ordering rule (the developer's, 2026-07-30):** documentation is built last so nothing is created after it and left out of training. As of the 2026-07-30 scope additions, that means after Phases 9 and 10. The coverage test and screenshot script must run **with the §5.13 vendors feature enabled**, or the gated Purchasing surfaces would be invisible to both.
- ✅ Demo: a new user follows the Guide alone from fresh install → working made-to-order product → completed manufacture order → stock receipt, with no questions; re-running the screenshot generator twice produces no git diff.

### Phase 9 — Vendors & purchase orders, strictly opt-in (~2–3 days, added 2026-07-30) — ✅ **done and verified 2026-07-30, see CLAUDE.md Progress Log**
- Per §5.13: three new tables (+ uninstall purge list), `VendorRepository`/`PurchaseOrderRepository`/`PurchaseOrderService`, receive-against-PO through StockService + OperationGuard (`po_receive` ledger reason), one gated "Purchasing" admin page (React; PO list + Vendors tabs), gated REST routes, on-order quantities in the low-stock report/digest/Inventory screen, one new audit check.
- **The gate is the feature's most important property:** `wcbom_vendors_enabled` default `'no'`; when off, nothing anywhere changes vs. today.
- ✅ Demo: with the feature off, the admin is pixel-identical to Phase 7's; flip the setting on, create a vendor + PO for 500 blanks, place it, see "500 on order" beside the blank in the low-stock report, receive 480, watch stock and ledger update exactly, cancel the remainder.

### Phase 10 — Nested BOMs / sub-assemblies (~1 day, added 2026-07-30) — ✅ **done and verified 2026-07-31, see CLAUDE.md Progress Log**
- Per §5.14: cycle detection + made-to-order-component rejection in `BomRepository::save()`; tests proving consumption/invalidation/buildable behavior for manufactured components already works end-to-end; docs notes on shallow-buildable semantics and sub-assembly pricing.
- ✅ Demo (verified): built a manufactured sub-assembly and used it as a made-to-order product's only always-line — buildable = floor(sub-assembly on-hand ÷ qty), refreshing correctly as the sub-assembly was built/reversed/consumed, while a raw material used only inside the sub-assembly's own recipe correctly did not move it; a self-referencing save, an indirect A→B→A cycle, and a made-to-order component were all rejected with clear messages through the real BOM editor UI, not just direct code calls.

**Total estimate: ~13–18 focused build days** (plus ~half a day for Phase 7, ~2–3 days for Phase 9, ~1 day for Phase 10, and ~2–3 days for Phase 8, which ships last). Phases 1–4 are the core; 5–6 can trail while the store starts using it.

---

## 9. Test scenarios checklist (acceptance)

1. Order 1 custom tumbler (default options) → blank −1, ledger row, order item meta records consumption.
2. Order with upgraded straw + sticker add-on → those components consumed too.
3. Cancel unpaid order → components restored exactly.
4. Refund 1 of 2 units with restock → half the components restored.
5. Edit BOM, then refund an *old* order → restoration matches original consumption (item meta), not new BOM.
6. Two concurrent checkouts for the last blank → exactly one succeeds.
7. MO complete → components down, product created with correct stock, costs snapshotted.
8. MO partial reverse → proportional restore; reverse more than remaining → blocked.
9. Reverse blocked when finished stock already sold below N.
10. BOM edited between MO complete and reverse → reversal uses snapshot.
11. Component trashed while in active BOM → blocked with message.
12. Phantom stock on shop grid with 200 products → no N+1 queries (assert query count).
13. `wp wcbom audit` detects a manual wp-admin stock edit as untracked drift (report-only).

*Phase 7 (COGS integration, §5.11) adds scenarios 14–19 — listed in that section rather than repeated here.*

Phase 9 (vendors & POs, §5.13) adds:

20. Feature **off** (default): no Purchasing page, no vendor/PO REST routes, and every manual inventory flow behaves identically to pre-Phase-9 — the no-change guarantee.
21. Full PO lifecycle: draft → placed → partial receive (480 of 500) → receive remainder → `received`; component stock and ledger rows (`po_receive`, ref `purchase_order`/po_id) exact at each step; `qty_received` cumulative per line.
22. Receiving is idempotent: replaying the same receive operation key doesn't double-stock (OperationGuard, §13.6 pattern).
23. Low-stock report/digest show on-order quantity + nearest expected date for components on open POs — and still list the component (on-order never hides real lowness).
24. Cancelling a PO (including a partially-received one) stops the outstanding quantity counting toward on-order; already-received stock is untouched.
25. A draft PO deletes outright; a placed PO cannot be deleted, only cancelled.

Phase 10 (nested BOMs, §5.14) adds:

26. An MO for a parent product consumes a manufactured sub-assembly's real stock through the ledgered path; a made-to-order parent's buildable = floor(sub-assembly on-hand ÷ qty), refreshing when the sub-assembly is built/reversed — and a raw material used only *inside* the sub-assembly's recipe does not move the parent's buildable.
27. BOM save rejects: a direct self-reference, an A→B→A cycle through active BOMs, and any made-to-order product as a component — each with a clear error.

§11 closeout (2026-07-30) adds:

28. With "Allow negative component stock" **off** (default), a manual adjustment below zero is blocked without the explicit override; with it **on**, the same adjustment proceeds and ledgers the exact negative. Order consumption goes negative (flagged) in both states — §13.3 is not a setting.

Phase 9 addendum (freight/tax/fees + close relabeling, added 2026-07-31) adds:

29. Freight/tax/fees are editable via `update_costs()` regardless of PO status — draft, ordered, or fully received — and `null` clears a previously-set field rather than zeroing it.
30. Landed cost amortizes proportional to each line's ordered value; a line with no unit_cost gets zero allocation and a null landed_unit_cost, while the PO's total-fees figure still reflects the true total. Zero fees means every line's landed cost equals its plain unit cost.
31. Sending a PO with both recipients selected and both emails on file sends two distinct emails (one per recipient) and reports both addresses back; a vendor with no email on file produces a warning rather than a failure when "to myself" is still selected and resolvable. Selecting neither recipient, or having no selected recipient resolve to a usable email at all, throws rather than silently no-oping.

---

## 10. Decisions already made (don't re-litigate at build time)

| Decision | Choice |
|---|---|
| Components | WC products with flag, hidden when not sellable |
| BOM/ledger storage | Custom tables, dbDelta, versioned migrations |
| Product modes | standard / made_to_order / manufactured (per product) |
| Extensibility | Conditional BOM lines (attribute/add-on) — no code for new materials |
| Reversal correctness | Per-MO component snapshots; per-order-item consumption meta |
| Stock mutation | Always via `wc_update_product_stock()` + ledger row, single locked code path |
| Units | Decimal BOM qtys; stock bulk materials in smallest integer unit (g/ml/cm) |
| Admin UI | React (`@wordpress/scripts`) for BOM editor & MO screen; PHP for the rest |
| Customer customizer | **Variation attributes** for choice-type options (colors, glitter, sizes) with the free "Variation Swatches for WooCommerce" plugin for swatch UI; **ThemeHigh Extra Product Options (free, wordpress.org `woo-extra-product-options`)** for the long tail: text personalization, upgrades, file uploads. Attribute-conditional BOM lines are the primary consumption driver; the `Integrations/` layer maps EPO field values for addon-conditional lines. No visual live-preview designer in v1. |

## 11. Open decisions (resolve during build)

1. ~~Which product options/add-ons plugin~~ **DECIDED 2026-07-29** — see §10. If visual live preview is later needed for conversion, trial Fancy Product Designer (~$99 one-time at fancyproductdesigner.com — the $59 CodeCanyon version is maintenance-only; Lumise ~$69 one-time but development has slowed; Zakeke/Customily/Kickflip are subscriptions — excluded). Build in-house only if FPD falls short, and then as a flat wrap-strip fabric.js canvas (production-usable export), not a curved-surface 3D preview.
2. ~~Whether negative component stock is ever allowed~~ **RESOLVED 2026-07-30** — "Allow negative component stock" checkbox on the Settings page, default **off**. Scope is deliberately narrow: it governs only whether *manual* operations (Inventory adjustments, MO completion) block on insufficient stock or proceed without the explicit per-operation override. Paid-order consumption is **not** governed by it — that always proceeds negative with the `[SHORTAGE]` flag, because §13.3 (never fatal a paid checkout) is a design invariant, not a preference.
3. ~~Whether phantom stock shows an exact number or just in/out-of-stock~~ **RESOLVED 2026-07-30** — no plugin setting. `StorefrontStock` exposes the buildable number through the standard `get_stock_quantity()` filter, so WooCommerce's own **Products → Inventory → "Stock display format"** option already fully controls presentation (exact number / only-when-low / never) for phantom stock exactly as for real stock. Verified live rather than assumed — see CLAUDE.md Progress Log. A duplicate plugin-level setting could only disagree with the WC one.
4. Hosting/deploy target for the store itself (plugin is host-agnostic; note Sliquid's other apps are Cloudflare — a WP store won't be, but the REST API keeps integration options open).
5. ~~**UI/cosmetic pass across every admin page this plugin adds**~~ **DONE 2026-07-30 during Phase 6** (see CLAUDE.md Progress Log: modal spacing fixes, Inventory retitle, cosmetic sweep) — struck through 2026-07-30 when the drift was noticed. Note: Phase 9's new Purchasing page should get the same treatment as part of its own build, not as a re-run of the global pass.

## 12. Dependency risk register & upgrade strategy (added 2026-07-30)

WordPress, WooCommerce, and the customizer plugins all update on their own schedules. This section lists every external surface the plugin touches, ranked by breakage risk, plus the process that keeps upgrades deliberate instead of accidental. **Proof this matters: on 2026-07-29 the unpinned dev environment silently installed WooCommerce 11.0.0-rc.2 — a release candidate — while stable was 10.9.4.**

### High risk (plan around these actively)

1. **WooCommerce's block-based product editor will eventually replace the classic product data panel.** Our BOM tab hooks `woocommerce_product_data_tabs`/`woocommerce_product_data_panels`/`woocommerce_process_product_meta` — classic-editor extension points that don't exist in the new React product editor WooCommerce has been building. When WC flips the default, our tab disappears for stores that adopt it.
   *Mitigation already in the architecture:* all BOM editor logic lives in the REST API + a host-agnostic React component; the metabox is a thin mounting shell. When the block editor becomes viable/default, we write a new thin adapter for its extension points and keep the classic shell for older stores. **Rule: never add logic to the metabox shell — everything goes in REST/React so both shells stay thin.** Watch the WooCommerce developer blog for rollout announcements.

2. **`StockService`'s row lock targets `wp_postmeta._stock` by direct SQL.** WooCommerce moved orders to custom tables (HPOS); product data tables are a recurring roadmap item. If product stock storage moves, our `SELECT ... FOR UPDATE` would lock a row nobody writes to anymore — locking silently stops working while everything else appears fine.
   *Mitigation:* the lock lives in exactly one method (`StockService::adjust_many()`), so it's a one-file fix; the §9 concurrency test (two simultaneous checkouts for the last blank) is the tripwire that catches it — run that test on every WC major upgrade, not just at release.

### Medium risk

3. **Dev-environment version drift (proven, see above).** `.wp-env.json` now pins exact plugin versions. Upgrades happen by bumping the pin, rebuilding, and running the fixture demo/tests — git history becomes the compatibility log. Cadence: check for WC updates roughly monthly (WC releases monthly); bump promptly but deliberately.

4. **`@wordpress/components` runtime drift.** `wp-scripts` externalizes `@wordpress/*` imports to the `window.wp.*` globals *served by whatever WordPress version is installed* — so the admin UI's runtime behavior changes with WP core updates, without us rebuilding anything. *Rules:* stick to long-stable components (Button, SelectControl, TextControl, ComboboxControl, Notice, Card — current usage); never touch `__experimental*`/`__unstable*` APIs; keep opting into the `__next*` forward-compatibility props. Smoke-test the BOM editor against WP beta before each WP major (fold into the Phase 6 matrix).

5. **Order stock hook timing (Phase 2 surface).** `woocommerce_reduce_order_stock`/`woocommerce_restore_order_stock` are stable hooks, but *when* they fire has nuances that shift across WC versions (blocks checkout draft orders, hold-stock-minutes, payment-gateway differences). *Mitigation already designed:* consumption writes the `_wcbom_consumed` snapshot and restoration reads only that snapshot — history is insulated from hook-timing changes; only the trigger points need re-verifying per WC release (§9 tests 1–5).

6. **ThemeHigh EPO order-item meta format** (Phase 2's `Integrations/ThemeHighEpo.php` reads it). Healthy today (updated 2026-07), but free plugins change formats or die. *Mitigations:* (a) the Integrations layer is the only code that knows EPO's format — swap-out cost is one class; (b) `_wcbom_consumed` snapshots mean past orders never re-read EPO data; (c) parse defensively — unrecognized format logs a warning and skips add-on-conditional lines rather than fataling; (d) attribute-conditional lines (the primary mechanism) don't involve EPO at all.

### Low risk (monitor, don't engineer around)

7. **Variation Swatches for WooCommerce** — purely presentational. Our BOM logic keys off variation attributes, which are WooCommerce core. If swatches breaks or is abandoned (it's the stalest of our deps: last updated 2026-03, tested only to WP 6.8.6), the storefront falls back to dropdown pickers and nothing about stock/BOM behavior changes. Replaceable with any other swatches plugin, zero data migration.
8. **WC CRUD & public API** (`wc_get_product`, `wc_update_product_stock`, `FeaturesUtil`, product meta) — the blessed surface WooCommerce commits to backward compatibility on. This is why the hard rules say "WC CRUD only."
9. **WordPress core surfaces** — `dbDelta`, REST API infrastructure, `pre_trash_post`/`pre_delete_post`, post meta. Among the most stable APIs in WordPress; custom tables are entirely ours.
10. **Build toolchain** (`@wordpress/scripts`, node_modules) — build-time only. Built assets are committed, so the plugin runs on any WP site even if the toolchain rots; toolchain upgrades can happen lazily.

### Upgrade process (adopt from Phase 2 onward)

- **Pin everything in `.wp-env.json`** (done 2026-07-30): exact WC/EPO/swatches versions. Never test against an RC unintentionally again.
- **Monthly-ish bump ritual:** bump pins → rebuild env → `wp wcbom seed --reset` → run the demo flows (later: the Phase 6 test suite) → commit the pin bump. Each commit documents "verified against WC X.Y.Z".
- **Maintain `WC tested up to` in the plugin header** alongside `WC requires at least`, updated with each verified bump.
- **Phase 6 matrix** (already planned: WC latest + latest−1, HPOS on/off, blocks + shortcode checkout) additionally gets: WP current beta smoke test, and the §9 concurrency test on every WC major.
- **Phase 6 hardening:** add a runtime `version_compare` guard in the bootstrap (deactivate gracefully with an admin notice below minimum WC/WP, rather than fataling).
- **`wp wcbom audit` (Phase 5)** doubles as the drift detector — if some future WC/plugin change starts moving stock outside our ledger, the audit surfaces it as untracked drift.

## 13. Write-failure & crash-safety model (added 2026-07-30)

the developer's prompt: wp-admin times out, freezes, and crashes in the real world — what happens to stock writes and manufacture builds mid-flight? The model below is what the plugin guarantees, ranked from the failure mode we're already safe against to the one that needs deliberate design.

### 13.1 What's already safe: half-written stock movements can't happen

Every stock mutation goes through `StockService::adjust_many()`, which wraps **all** of an operation's stock writes + ledger rows in one InnoDB transaction. If PHP dies mid-operation — timeout, OOM, fatal, server restart — the MySQL connection drops and InnoDB **rolls back the uncommitted transaction automatically**. Result: either every component moved and every ledger row exists, or nothing happened at all. A 12-component manufacture consume can never land as "7 components deducted, 5 not, ledger disagrees." This is why the single-code-path hard rule exists.

Timeout risk for our operations themselves is inherently low: they're a handful of indexed SQL writes (milliseconds), no remote HTTP in any write path. The realistic timeout scenarios are *around* our writes (a bloated admin page, another plugin, a slow gateway), which is exactly when the transaction guarantee matters.

### 13.2 Gap fixed 2026-07-30: cache poisoning on rollback

`wc_update_product_stock()` updates WordPress object caches *immediately*, before our COMMIT. On the success path that's harmless. But on ROLLBACK, a site with a **persistent object cache** (Redis/Memcached — most managed WP hosting) would keep serving the rolled-back stock value from cache while the DB has the real one. Invisible in dev (no persistent cache), corrupting in production. **Fixed:** `StockService`'s rollback path now explicitly flushes each touched product's caches (`wc_delete_product_transients` + postmeta cache group) so the next read comes from the DB.

### 13.3 Gap fixed 2026-07-30: an unexpected write failure must not fatal checkout

Order consumption runs inside WooCommerce's checkout/payment flow. Phase 2 already handled the *expected* failure (insufficient stock → consume negative + loud order note), but an **unexpected** Throwable — e.g. `innodb_lock_wait_timeout` under heavy concurrency — would have propagated up and fataled the customer's order-received page *after payment succeeded*. **Fixed:** per-item consumption is wrapped in a catch-all; on unexpected failure the transaction has already rolled back cleanly (13.1), and we (a) log via `wc_get_logger()` (source: `wcbom`), (b) add a ⚠ order note telling the merchant consumption failed and to reconcile, (c) let checkout complete. Bookkeeping is recoverable; a paid customer seeing a white screen is not. The missing consumption is visible three ways: the order note, the log, and `wp wcbom audit` (no ledger rows for that order).

### 13.4 The sneakiest failure: the retry after a timeout that actually succeeded

A gateway 504 does **not** kill PHP — the server often finishes the write after the browser gives up. The user sees an error, clicks "Receive 50" again, and now 100 arrived. This is a *duplicate submit*, not a half-write, and transactions don't help. Defense — **idempotency keys**, infrastructure added 2026-07-30 so Phases 3.5/4 build on it:

- New table `wcbom_ops` (`op_key` PK + `created_at`/`user_id`/`summary`), DB_VERSION 0.3.0, and `Stock\OperationGuard::claim($op_key)` — an INSERT-first check that returns false on duplicate. Old rows are purged opportunistically (>7 days).
- **Phase 3.5 rule:** every mutating Inventory-screen REST call sends a client-generated UUID (crypto.randomUUID(), generated when the user *opens the form*, not when they click). Server claims it before applying; a replay gets the friendly "already applied" response instead of a second application.
- **Phase 4 rule:** manufacture orders get the same key on completion *plus* a status state machine (`draft → completed → …`) — completing an already-completed MO is a no-op by state check, so MO buttons are doubly protected.
- Client side: disable buttons in flight; on a network error say "The request may still have completed — refresh before retrying" (safe to retry anyway, because of the key).

### 13.5 Known, accepted crash window: order snapshot meta (documented, audited)

In order consumption, the `_wcbom_consumed` snapshot meta is written *after* the stock transaction commits (WC order-item meta can't share our transaction without invasive restructuring). Crash in that tiny window → components consumed + ledgered, but no snapshot. The ordering is deliberate: the reverse order (snapshot first) could restore stock that was never consumed on a later cancel — silent inflation, strictly worse. With the current order the failure is *detectable and recoverable*: ledger rows exist for the order, so **`wp wcbom audit` (Phase 5) must include the check "order has `order` ledger rows but item lacks `_wcbom_consumed`"** and offer to rebuild the snapshot from those rows. (Phase 6 may revisit sharing one transaction via HPOS's order-item tables; not worth the complexity now.)

### 13.6 Manufacture orders (Phase 4): design rules so a crashed build is never ambiguous

1. **Create-or-find the product listing first, outside the atomic step.** An empty listing with 0 stock is a harmless orphan if we crash after creating it; retrying finds and reuses it.
2. **Then one `StockService`-style transaction for everything money-shaped:** component deductions + ledger rows + MO item snapshot rows + finished-good stock increment + ledger row + MO status flip to `completed`. All our own tables + postmeta — one COMMIT. A crash anywhere leaves the MO in its prior state with zero stock moved (13.1), and the pre-created listing simply waits.
3. Completion/reversal buttons carry idempotency keys (13.4) and are state-machine-guarded.
4. Reversal follows the identical pattern (snapshot-driven, one transaction, status flip inside it).

### 13.7 Recovery tooling (Phase 5 `wp wcbom audit` — consolidated checklist)

The audit command is the recovery net for everything above. It must detect: (a) WC `_stock` ≠ last ledger `stock_after` per product (external/untracked edits — informational); (b) orders with consumption ledger rows but missing snapshot meta (13.5 — offer rebuild); (c) MOs in a non-terminal state older than N minutes (crashed mid-flight — always safe to resume or mark failed, because of 13.6's all-or-nothing step); (d) `wcbom_ops` rows with no corresponding ledger activity (a claimed key whose operation never committed — informational, the retry path handles it).

## 14. Distribution & updates (added 2026-07-30)

The plugin ships as a normal installable zip, with updates delivered from GitHub through WordPress's standard update UI — no wordpress.org listing required. Built 2026-07-30; this section is the design + release runbook.

### 14.1 How GitHub-powered updates work

WordPress 5.8+ supports third-party update channels natively: a plugin declares `Update URI:` in its header, and core fires an `update_plugins_{$hostname}` filter during every update check. Our `Updates\GitHubUpdater` hooks `update_plugins_github.com`, asks the GitHub Releases API for the latest release (cached 6h in a transient; cleared when the user clicks Dashboard → Updates → "Check Again"), compares versions, and hands WordPress the release's zip asset URL. From there it's a completely normal WordPress update: the "update available" row, one-click update, auto-update toggle — all standard.

Two guards worth knowing about:
- **The updater only activates when the installed folder is `wc-bom-stock`** (the release zip's root). WordPress replaces the plugin folder using the zip's root name on update, so a folder mismatch would strand the old copy. This also makes the updater inert in the wp-env dev mount (`wordpress-bom`) — dev never sees phantom updates.
- All failure modes (API down, rate-limited, repo unreachable, no releases yet) cache a "no update" result and stay silent. An update check can never break a site.

### 14.2 The private-repo caveat (decision needed before first external tester)

**GitHub's API returns 404 for private repos to unauthenticated callers — and this repo is currently private.** Tester sites can't see releases until one of:
1. **Make the repo public** (simplest; code becomes public).
2. **Keep it private; testers add a token**: `define( 'WCBOM_GITHUB_TOKEN', '...' );` in wp-config.php (a fine-grained, read-only, this-repo-only token). The updater sends it when defined. Fine for a handful of trusted testers; clumsy at scale.
3. **A separate public releases repo** (zips + release entries only, code stays private) — point `Update URI`/updater there later; one-line change.

Until decided, everything still works — checks just no-op silently.

### 14.3 Release workflow (what the developer actually does)

1. Bump `Version:` in `wc-bom-stock.php` **and** the `WCBOM_VERSION` constant (and `Schema::DB_VERSION` if the schema changed).
2. Commit and push as normal.
3. `git tag v0.2.0 && git push origin v0.2.0`

That's it. A GitHub Action (`.github/workflows/release.yml`) fires on the tag: builds JS, assembles the zip via `bin/build-release-zip.sh`, **fails the release if the tag doesn't match the plugin header version** (can't ship a mislabeled zip), and publishes a GitHub Release with the zip attached. Sites see the update within ~6h, or immediately via "Check Again". Plain pushes to main never trigger updates — releases are always a deliberate tag.

Manual fallback (no Actions): `bin/build-release-zip.sh` locally, then `gh release create v0.2.0 dist/wc-bom-stock-0.2.0.zip`.

### 14.4 The release zip

Built by `bin/build-release-zip.sh` into `dist/` (gitignored). Rooted at **`wc-bom-stock/`** — this becomes the installed folder name and must never change between releases (see 14.1). Contains runtime files only: bootstrap + `uninstall.php`, `src/`, `assets/build/` (compiled JS, no `assets/src`), and a freshly generated `--no-dev` Composer autoloader (`vendor/` — no packages, just the PSR-4 autoloader the bootstrap requires). Excludes all dev material: git/CI files, node_modules, planning docs, lint configs, tests.

### 14.5 Updates must never require an uninstall — and never lose data

- **File replacement is safe by design:** all plugin data lives in custom tables, product/order meta, and options — none of it in the plugin folder. WordPress's updater replaces files only.
- **Schema migrations run automatically post-update:** `Schema::maybe_upgrade()` on `plugins_loaded` re-runs the idempotent dbDelta whenever `DB_VERSION` changes (activation hooks do NOT fire on updates — this is why the check lives on plugins_loaded). Already proven in production-like conditions: 0.1.0 → 0.2.0 (ENUM→VARCHAR, 37 live ledger rows preserved) → 0.3.0 (new table).
- **Migration compatibility rules:** additive only in normal releases (new tables/columns/reasons); never drop/rename columns or change semantics of stored data (ledger rows, `_wcbom_consumed` snapshots, MO snapshots are historical records — new code must always read old records); destructive cleanups only in a major version with an explicit migration and changelog warning.
- Downgrades are not supported (standard WordPress practice); the ledger/audit trail is the recovery net if anyone force-installs an older zip.

### 14.6 Uninstall data policy

Deleting the plugin from wp-admin keeps all data by default — `uninstall.php` only drops the tables when the merchant has explicitly enabled **"Remove all data on uninstall"** (BOM & Stock → Settings, unchecked by default; built 2026-07-30, moved off the WooCommerce Advanced tab 2026-07-30 — see §14.8). Deactivate-then-reinstall, updates, and accidental deletions therefore never lose the ledger, BOMs, or manufacture history. The settings text spells out exactly what would be deleted.

### 14.7 Companion-plugin dependencies (added 2026-07-30)

- **WooCommerce is a hard dependency**, declared two ways: the WP 6.5+ `Requires Plugins: woocommerce` header (install-time enforcement — WordPress won't activate us without it and offers an install link), plus the existing runtime guard (admin notice + bail) for older WP.
- **The customizer companions are recommendations, never requirements.** ThemeHigh EPO (`woo-extra-product-options`) and Variation Swatches (`variation-swatches-woo`) are deliberately NOT in `Requires Plugins`: the plugin is fully functional without them — swatches is purely presentational and EPO only powers addon-conditional BOM lines. `Admin\RecommendedPlugins` shows a dismissible notice (plugins screen, product editor, Inventory page; `install_plugins` capability required) listing whichever is missing, with core's native one-click Install links (or Activate, if installed but inactive). Dismissal is permanent per site via an option.

### 14.8 Own top-level admin menu, not WooCommerce's (added 2026-07-30)

Through Phase 5, every admin screen (Inventory, Manufacturing, Reports, Endpoints) lived under WooCommerce's own menu (`add_submenu_page('woocommerce', ...)`), and Settings lived inside WooCommerce → Settings → Advanced as a "BOM & Stock" tab section. the developer asked for all of it consolidated into the plugin's own top-level menu instead, separate from WooCommerce's.

- **New `Admin\PluginMenu`** registers a single top-level "BOM & Stock" menu (slug `wcbom`, `dashicons-archive`, position 56 — just below WooCommerce). Every page class now parents its `add_submenu_page()` call to `PluginMenu::SLUG` instead of `'woocommerce'`. `Admin\InventoryPage` additionally uses `PluginMenu::SLUG` as **its own** slug too (not just its parent's) — WordPress's standard technique for making the top-level menu link itself open a specific page, instead of a generic duplicate first entry — so clicking "BOM & Stock" lands directly on Inventory.
- **Settings moved off the WooCommerce tab entirely.** New `Admin\SettingsPage` is a plain submenu page reusing WooCommerce's own field renderer/saver directly (`woocommerce_admin_fields()` / `WC_Admin_Settings::save_fields()`) — both are plain static helpers with zero dependency on the `WC_Settings_Page` tab-registration system, so they work standalone with our own `<form>`/nonce exactly as well as inside a real WC settings tab. `save_fields()` does no nonce/capability check itself (that's normally the tab system's job), so `SettingsPage` adds its own `check_admin_referer()`. Same two settings (uninstall data policy, low-stock digest enable/email), same option keys — only the page location changed.
- **Real bug found by testing, not by reasoning about the code:** the three React-app pages (Manufacturing, Reports) went blank ("Loading…" forever) after the move. Root cause: their `admin_enqueue_scripts` gate compared the current hook against a hand-computed string assumed to be `{parent_slug}_page_{submenu_slug}` — but WordPress's actual hook-suffix algorithm (`get_plugin_page_hookname()`) prefixes with `sanitize_title()` of the parent menu's **display title**, not its slug, via a core global (`$admin_page_hooks[$parent_slug] = sanitize_title($menu_title)`, set inside `add_menu_page()`). WooCommerce's own menu title is literally "WooCommerce" → sanitizes to `woocommerce`, matching its slug and masking this entirely; our menu's title "BOM & Stock" sanitizes to `bom-stock` ≠ slug `wcbom`, so the hand-computed guess was wrong. **Fixed** by capturing `add_submenu_page()`'s own return value (the exact, correct hook suffix) in an instance property instead of ever hand-computing it — applied to all three page classes (Inventory's happened to still be correct by luck of the `toplevel_page_{slug}` special case, but was changed too for consistency). `Admin\RecommendedPlugins`'s screen-ID check for showing the companion-plugin notice on the Inventory page was updated to the new `toplevel_page_wcbom` screen ID too.
- Verified end-to-end in wp-env: new menu shows all five pages in the right order and landing on Inventory; WooCommerce's own menu no longer lists Inventory/Manufacturing/Endpoints; WooCommerce → Settings → Advanced no longer has a "BOM & Stock" tab; the Settings page saves for real through the actual UI (not simulated) and the low-stock digest cron event schedules/unschedules correctly off that real save; Manufacturing and Reports both render their React apps correctly after the hook-suffix fix; the product-edit BOM tab (a separate, unrelated mechanism) is confirmed unaffected. PHPCS/PHPStan clean; `debug.log` stayed empty throughout.
