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

## Multi-machine note: arm64 (this machine) ↔ Intel (the developer's home laptop)

This build moves between at least two Macs with **different CPU architectures** — the one this was scoped/built on so far is Apple Silicon (`arm64`); the developer's home laptop is Intel (`x86_64`). Implications for whoever picks this up next:

- **Docker Desktop / `wp-env` itself: not a concern.** WordPress, WooCommerce, and MariaDB container images are multi-arch — the same `.wp-env.json` and containers work identically on both machines. No Docker config should ever hardcode a platform.
- **Never commit compiled/native artifacts.** `node_modules/`, `vendor/` (Composer), and any `wp-env` Docker volumes/cache must stay gitignored and be reinstalled fresh on each machine (`npm install`, `composer install`) — a `node_modules` with native bindings or a `vendor/` with compiled extensions built on one architecture will not run on the other.
- **Homebrew prefix differs *for a real reason* on each machine, not just as a bug.** On Apple Silicon, native Homebrew lives at `/opt/homebrew`; on Intel Macs, `/usr/local` **is** the correct native prefix (this is not the Rosetta problem described below — on the home Intel laptop, `/usr/local` needs no special scrutiny). The Rosetta gotcha logged 2026-07-29 is specific to *this* arm64 machine having only an Intel/Rosetta Homebrew installed — it does not apply at home.
- Before running `brew install` on any machine, confirm `uname -m` matches the architecture the active `brew` on `PATH` actually targets — cheap check, avoids a repeat of the wrong-architecture Docker install.
- Lockfiles (`package-lock.json`, `composer.lock`) **should** be committed as normal — they pin versions, not architecture-specific binaries — but always re-run the install command after cloning on a new machine rather than assuming installed dependencies carried over via git.

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

**Correction (same session):** the `brew install --cask docker` above installed the **Intel (x86_64) build of Docker Desktop**, which runs slow/wrong under Rosetta. Root cause: this Mac is `arm64` (`uname -m` confirms it, macOS 26.5), but the only Homebrew present is the **Intel Homebrew at `/usr/local`** (running itself under Rosetta) — there is no native `/opt/homebrew` install on this machine. Any `brew install` run through that shell pulls Intel binaries/casks. the developer is installing the correct arm64 Docker Desktop build himself (likely via a native `/opt/homebrew` Homebrew, or the arm64 .dmg directly from docker.com). **If a future session needs to `brew install` anything else on this machine, check `uname -m` vs. which `brew` is on `PATH` first** — don't assume `/usr/local/bin/brew` is native just because `brew --version` works.

**Confirmed working:** Docker Desktop (correct arm64 build) is installed and running — `docker info --format '{{.OSType}}/{{.Architecture}}'` reports `linux/aarch64`, `docker --version` reports 29.6.2.

**Also noted this session:** the developer's home laptop is **Intel (x86_64)**, not arm64 — this build will move between the two. See the new "Multi-machine note" section above (added 2026-07-29) for what that does and doesn't affect. Short version: Docker/wp-env is fine either way; just don't commit `node_modules`/`vendor`, and don't assume the arm64-specific Rosetta-Homebrew gotcha applies on the Intel machine.

**Next steps now that Docker is confirmed running:**
1. `brew install composer` (needed on host for PSR-4 autoload + PHPCS/PHPStan dev tooling — wp-env runs WP/WooCommerce inside containers, but Composer runs on the host against the plugin source).
2. Install `@wordpress/env` (`npm install -g @wordpress/env` or as a dev dependency) and scaffold `.wp-env.json` per BUILD_PLAN.md Phase 0.
3. Continue Phase 0: plugin scaffold (`wc-bom-stock.php`, `composer.json` PSR-4), activation hook + `Install/Schema.php` table creation, fixture seeder script (blank tumbler, 6–8 components, one premade design, one made-to-order product), PHPCS (WPCS-Extra) + PHPStan level 6 config.

**Repo:** https://github.com/croix/wordpress-bom (private). Push progress + update this log at natural stopping points, not just at phase boundaries — a session can end mid-Phase-0.
