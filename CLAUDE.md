# WooCommerce BOM & Stock Manager (`wc-bom-stock`)

WordPress plugin adding Bill-of-Materials and component-level stock management to WooCommerce. Owner: the developer ([redacted]).

## Start here

**Read [BUILD_PLAN.md](BUILD_PLAN.md) before writing any code.** It contains the full scope, schema, architecture decisions, and phased build order. Scoped 2026-07-29; the build is tracked in this git repo so work can continue from whichever machine the developer is on — **read the Progress Log at the bottom of this file first** to see exactly where the last session left off and what's still pending on the current machine's environment setup. Commit and push updates to this file (and BUILD_PLAN.md, if scope changes) at the end of each session.

## The one-paragraph version

Store sells premade tumblers, customer-customizable tumblers, and in-house manufactured designs. Blank tumblers are both sellable products AND raw materials (shared stock pool). Selling a customized item consumes component stock via its BOM. "Manufacture Orders" batch-convert components into a finished-good listing (e.g., 12 blanks + glitter → "Pink Glitter Tumbler" with stock 12) and are reversible via per-MO snapshots.

## Hard rules (decided during scoping — do not revisit)

- Components are **WooCommerce products** (flagged, hidden if not sellable) — never a parallel entity.
- BOMs, manufacture orders, and the stock ledger live in **custom tables** (schema in BUILD_PLAN.md §4), not post meta.
- **Every plugin stock change writes a ledger row** and goes through one row-locked `StockService` path using `wc_update_product_stock()`.
- Reversals use **snapshots** (MO item snapshots; `_wcbom_consumed` order-item meta), never re-resolve current BOM.
- Extensibility for new materials (stickers, paint, upgraded straws/caps) = **conditional BOM lines** keyed on variation attributes or add-on values — no code per material.
- Declare **HPOS compatibility**; use WC CRUD only; test blocks + shortcode checkout.

## Conventions

- PHP 8.1+, PSR-4 (`WCBOM\` → `src/`), WPCS-Extra + PHPStan level 6.
- Admin BOM editor / MO screens: React via `@wordpress/scripts`. Everything else PHP.
- Dev env: `wp-env` (Docker) with a seeded tumbler fixture catalog (Phase 0 creates the seed script) — demo every phase against it.
- Text domain: `wcbom`.

## Status / next step

- [ ] Phase 0: wp-env setup, plugin scaffold, tables, fixture seeder — **environment setup in progress, see Progress Log**
- [ ] Phase 1: ledger + StockService + BOM editor
- [ ] Phase 2: order consumption/restoration
- [ ] Phase 3: phantom (buildable) stock
- [ ] Phase 4: manufacture orders (build/reverse)
- [ ] Phase 5: reports, import/export, REST, CLI
- [ ] Phase 6: hardening, tests, release prep

Update this checklist as phases complete. Remaining open decisions are in BUILD_PLAN.md §11.

**Customizer decision (made 2026-07-29):** variation attributes drive choice-type options (colors/glitter/sizes) with the free "Variation Swatches for WooCommerce" plugin for the UI; free ThemeHigh Extra Product Options (wordpress.org `woo-extra-product-options`) covers text personalization, upgrades, and file uploads. First integration class: `Integrations/ThemeHighEpo.php`. No visual live-preview designer in v1 — if needed later, trial Fancy Product Designer (~$99 one-time) before building anything in-house.

## Progress Log

Append a dated entry each session (newest on top). Don't rewrite history — if a decision changes, add a new entry noting the change, and update BUILD_PLAN.md §10/§11 if it's a scope-level decision.

### 2026-07-29 — Environment setup (Phase 0, in progress)

**Machine state found at session start:** no Docker, PHP, Composer, or WP-CLI installed. Node v24.16.0/npm 11.13.0 present. Homebrew 6.0.9 present. `gh` CLI present and authenticated as GitHub user `croix`.

**Decision: Docker Desktop, not native Homebrew LEMP stack, not Colima.** Keeps `wp-env` working exactly as documented in BUILD_PLAN.md/CLAUDE.md rather than deviating to a native PHP+MySQL host setup or a Docker-API shim. (Considered and rejected for now: native brew stack — most production-like but deviates from the documented `wp-env` convention; Colima — lighter than Docker Desktop but can be finicky on first run.)

**Gotcha hit:** `brew install --cask docker` fails when run through an automated/sandboxed shell — it needs to `sudo mkdir -p /usr/local/cli-plugins` (that dir is root-owned) and prompts for an interactive password. **Must be run in a real Terminal window**, not through Claude Code's tool-call shell. Command: `brew install --cask docker`.

**State at end of session:** the developer is running that install command himself in his own Terminal. Still pending: (1) confirm `brew install --cask docker` completed, (2) launch `/Applications/Docker.app` and complete first-run setup (accept license, grant privileged-helper permission — macOS will prompt for password directly), (3) verify with `docker info` that the daemon is up.

**Next steps once Docker is confirmed running:**
1. `brew install composer` (needed on host for PSR-4 autoload + PHPCS/PHPStan dev tooling — wp-env runs WP/WooCommerce inside containers, but Composer runs on the host against the plugin source).
2. Install `@wordpress/env` (`npm install -g @wordpress/env` or as a dev dependency) and scaffold `.wp-env.json` per BUILD_PLAN.md Phase 0.
3. Continue Phase 0: plugin scaffold (`wc-bom-stock.php`, `composer.json` PSR-4), activation hook + `Install/Schema.php` table creation, fixture seeder script (blank tumbler, 6–8 components, one premade design, one made-to-order product), PHPCS (WPCS-Extra) + PHPStan level 6 config.

**Repo:** https://github.com/croix/wordpress-bom (private). Push progress + update this log at natural stopping points, not just at phase boundaries — a session can end mid-Phase-0.
