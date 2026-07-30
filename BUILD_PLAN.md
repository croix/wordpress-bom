# WooCommerce BOM & Stock Management Plugin — Build Plan

**Working name:** `wc-bom-stock` (WooCommerce BOM & Stock Manager)
**Author:** the developer (Sliquid)
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

Admin screen: **WooCommerce → Manufacturing**.

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

**WooCommerce → Inventory** (React admin page): every component's stock managed from one place — never open a product edit screen to change inventory. Three distinct workflows, each writing its own ledger reason so reports stay meaningful:

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
- **WooCommerce → Inventory** React page per §5.7: all components in one table, Receive / Count / Adjust row actions + receiving-session bulk entry, everything through `StockService` with the new `received`/`cycle_count` ledger reasons.
- ✅ Demo: receive 50 blanks without touching the product edit screen → stock +50, ledger row, buildable count updates; cycle-count glitter at 480 g against a system 500 g → drift −20 shown and ledgered.

### Phase 4 — Manufacture orders (~3–4 days)
- MO CRUD screen, draft/complete/reverse (partial), snapshots, scrap handling, `ProductFactory` (new-listing-from-template flow), pick list print view.
- ✅ Demo: the exact scenario from the brief — manufacture 12 pink glitter tumblers, new listing appears with stock 12, blanks −12; reverse 4 → stock 8, blanks +4.

### Phase 5 — Reporting, alerts, import/export, API (~2–3 days)
- Ledger browser + CSV export, buildable/usage reports, low-component-stock digest, CSV BOM import/export, REST endpoints, WP-CLI audit/recompute.

### Phase 6 — Hardening & release prep (~2–3 days)
- Integration tests for every ledger-touching path (order, refund, cancel, MO complete/reverse, concurrent orders via parallel requests).
- HPOS on/off matrix, blocks vs. shortcode checkout, PHP 8.1/8.3, WC latest + latest−1.
- Uninstall behavior (keep data by default; opt-in purge). Readme, inline docs, i18n (`wcbom` text domain).

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
