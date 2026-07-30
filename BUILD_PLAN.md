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
  reason        VARCHAR(32) NOT NULL,       -- order|order_restore|refund|manufacture|manufacture_reverse|manual_adjust|import|received|cycle_count (was ENUM; widened 2026-07-30 so new movement types never need a migration)
  ref_type      VARCHAR(32) NULL,           -- 'wc_order' | 'manufacture_order' | ...
  ref_id        BIGINT UNSIGNED NULL,
  user_id       BIGINT UNSIGNED NULL,
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL,
  KEY product_time (product_id, created_at), KEY ref (ref_type, ref_id)
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

- **Nested BOMs / sub-assemblies** (a manufactured item used as a component in another BOM). Schema supports it (components are just products); the phantom-stock recursion and cycle detection are the work. Design doesn't block it — defer.
- Multi-warehouse/location stock; supplier purchase orders (receiving *is* in scope as of 2026-07-30 — §5.7's Inventory screen — but PO tracking against suppliers is not); barcode scanning; serial/lot tracking.

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

### Phase 7 — WooCommerce native COGS integration (~half day, added 2026-07-30)
- Per §5.11: `Integrations\CogsProvider` hooking `woocommerce_get_product_cogs_total_value`; `_wcbom_cogs_from_bom` opt-in toggle on the BOM tab; extract the Σ(component price × qty) calculation out of `MarginReport` into something shared so our margin report and WooCommerce Analytics can never disagree; `MANUFACTURED` products source cost from their latest completed MO snapshot, made-to-order from the live per-variation BOM.
- ✅ Demo: enable WooCommerce's Cost of Goods Sold feature, place an order for a made-to-order variation, and see WooCommerce's **own** Analytics report the correct BOM-derived profit — with two different variations correctly reporting different costs, and the whole integration inert when either toggle is off.

**Total estimate: ~13–18 focused build days.** Phases 1–4 are the core; 5–6 can trail while the store starts using it.

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
2. Whether negative component stock is ever allowed (setting; default **off**).
3. Whether phantom stock shows an exact number or just in/out-of-stock to customers (setting; default: show number, respecting WC's "stock display format" option).
4. Hosting/deploy target for the store itself (plugin is host-agnostic; note Sliquid's other apps are Cloudflare — a WP store won't be, but the REST API keeps integration options open).
5. **UI/cosmetic pass across every admin page this plugin adds** (added 2026-07-30, the developer's request) — Inventory, Manufacturing, BOM editor tab, Reports, Endpoints, Settings section. Everything so far has been built and verified for *correctness* (real data, real REST calls, live browser checks) but not reviewed for visual polish/consistency as a deliberate pass. Do this once the admin surface stops changing shape — Phase 6 (hardening/release prep) is the natural point, so it isn't redone after every subsequent phase adds another screen.

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
