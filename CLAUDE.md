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

## Environment quickstart (fresh machine, e.g. the Intel home laptop)

One-stop, ordered runbook. The Progress Log below has the full story of *why*; this is just the *what*, in order. Skip any step whose prerequisite already works.

**1. Prerequisites — check each, install only if missing:**
- Homebrew: `which brew`. If missing: `/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"`. **On Intel, there is only one correct prefix, `/usr/local`** — none of the dual-Homebrew/Rosetta gotchas in the Progress Log below apply; those were specific to the arm64 build machine having only an emulated Intel Homebrew.
- Node: `node -v`. If missing: `brew install node`.
- Command Line Tools: `xcode-select -p`. If it errors: `xcode-select --install` (GUI installer, needs you at the keyboard).
- Docker Desktop: `docker info`. If missing: `brew install --cask docker`, then launch `/Applications/Docker.app` and complete first-run setup yourself (license + privileged-helper password prompt — can't be scripted).
- Composer (pulls PHP as a dependency): `composer --version`. If missing: `brew install composer`.

**2. Clone and install dependencies:**
```
git clone https://github.com/croix/wordpress-bom.git
cd wordpress-bom
composer install
npm install
```
(`composer.lock`/`package-lock.json` are committed, so this pulls the exact versions verified working — see Multi-machine note above on why reinstalling fresh here matters.)

**3. Bring up the dev environment — try the standard path first:**
```
npx wp-env start
```
Give it a few minutes (first run pulls/builds Docker images). Check `docker ps` — you should see 4+ containers (mysql, wordpress, cli, phpmyadmin, plus `tests-*` variants). If so, skip to step 4.

**If it silently stalls** (only the `mysql` container ever appears, command exits 0 with no error) — this happened reliably on the arm64 build machine, cause unconfirmed (possibly specific to running through Claude Code's sandboxed shell rather than a real Terminal, so it may just work fine here). Manual fallback:
```
cd ~/.wp-env/<hash>          # find <hash> via: ls ~/.wp-env/
# Check each plugin folder actually has its main file:
ls woocommerce/*.php woo-extra-product-options/*.php variation-swatches-woo/*.php
# For any that are missing/incomplete, redo it directly:
curl -fSL https://downloads.wordpress.org/plugin/<slug>.zip -o /tmp/<slug>.zip
unzip -q /tmp/<slug>.zip -d ~/.wp-env/<hash>/
docker compose up -d
docker compose exec -T cli wp core install --path=/var/www/html --url="http://localhost:8888" --title="wc-bom-stock dev" --admin_user=admin --admin_password=password --admin_email=[redacted] --skip-email
docker compose exec -T cli wp plugin activate woocommerce woo-extra-product-options variation-swatches-woo wordpress-bom/wc-bom-stock.php --path=/var/www/html
docker compose exec -T cli wp wcbom seed --path=/var/www/html
```
(The plugin identifier is `wordpress-bom/wc-bom-stock.php` — wp-env names the in-container folder after this repo's directory name, `wordpress-bom`, not the plugin slug. A plain `git clone` above preserves that name automatically.)

**4. Verify it worked:**
- Visit `http://localhost:8888/wp-admin`, log in `admin` / `password`.
- Products list should show 12 items (10 components + premade + made-to-order) — see BUILD_PLAN.md's fixture catalog.
- Plugins screen: WooCommerce, ThemeHigh EPO, variation-swatches-woo, and wc-bom-stock all active, no error banners.
- `docker compose exec -T wordpress cat /var/www/html/wp-content/debug.log` should be empty/missing (no PHP notices).

## Conventions

- PHP 8.1+, PSR-4 (`WCBOM\` → `src/`), WPCS-Extra + PHPStan level 6.
- Admin BOM editor / MO screens: React via `@wordpress/scripts`. Everything else PHP.
- Dev env: `wp-env` (Docker) with a seeded tumbler fixture catalog (Phase 0 creates the seed script) — demo every phase against it.
- Text domain: `wcbom`.

## Status / next step

- [x] Phase 0: wp-env setup, plugin scaffold, tables, fixture seeder — **done and verified 2026-07-29, see Progress Log**
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

### 2026-07-29 — Phase 0 complete: scaffold built, wp-env verified end-to-end

**Phase 0 is done and fully verified**, continuing straight from the environment setup below (same session, same machine — arm64 Mac). Everything in BUILD_PLAN.md's Phase 0 (wp-env setup, plugin scaffold, tables, fixture seeder) now exists and has been proven to work by actually running it, not just written.

**What was built (all committed to the repo):**
- `composer.json` — PSR-4 (`WCBOM\` → `src/`), require-dev: `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp`, `phpstan/phpstan` (^2.1 — bumped up from an initial ^1.11, see gotcha below), `szepeviktor/phpstan-wordpress` (^2.0), `php-stubs/woocommerce-stubs`, `php-stubs/wp-cli-stubs`.
- `wc-bom-stock.php` — bootstrap: constants, autoload require, activation hook → `Schema::install()`, `before_woocommerce_init` → `Hpos::declare_compatibility()`, `plugins_loaded` → `Plugin::instance()->init()` (with a WooCommerce-missing admin notice guard), `WP_CLI::add_command('wcbom', ...)`.
- `uninstall.php` — drops tables only if `wcbom_purge_data_on_uninstall` option is `'yes'` (default: keep data). Logic wrapped in a prefixed `wcbom_run_uninstall()` function (WPCS requires prefixed globals at file scope).
- `src/Plugin.php` — singleton service-wiring root, currently a no-op `init()` — Phase 1 fills this in.
- `src/Install/Schema.php` — dbDelta definitions for all 5 tables from BUILD_PLAN.md §4 (`wcbom_boms`, `wcbom_bom_items`, `wcbom_manufacture_orders`, `wcbom_manufacture_order_items`, `wcbom_stock_ledger`), versioned via a `wcbom_db_version` option so `maybe_upgrade()` can re-run dbDelta (idempotent) when `Schema::DB_VERSION` bumps.
- `src/Integrations/Hpos.php` — declares `custom_order_tables` + `cart_checkout_blocks` compatibility.
- `src/Cli/Commands.php` — `wp wcbom seed [--reset]`: creates the 10 fixture components (blank tumbler + glitter/vinyl/epoxy/straws/caps/stickers), 1 premade product (`Ocean Wave 24oz Tumbler`, stock 12), and 1 made-to-order variable product (`Custom 24oz Tumbler`, glitter-color × straw-upgrade attributes, 4 variations) — **and writes a real starter BOM directly into `wcbom_boms`/`wcbom_bom_items`** (always-lines for blank/epoxy/cap, attribute-conditional lines for glitter color and straw upgrade). This gives Phase 1's BOM editor real data to load against from day one.
- `phpcs.xml.dist` (WordPress-Extra minus the two `WordPress.Files.FileName` sniffs — those enforce classic `class-{name}.php` naming, which directly conflicts with PSR-4's file-name-matches-class-name requirement; excluding them is the standard approach for namespaced/Composer-autoloaded WP plugins) and `phpstan.neon.dist` (level 6). **Both run clean against the full source tree right now.**
- `.wp-env.json` — WP + WooCommerce + `woo-extra-product-options` + `variation-swatches-woo`, `afterStart` lifecycle script activates all four plugins and runs `wp wcbom seed`.
- `package.json` / `assets/src/bom-editor/index.js` — `@wordpress/env` + `@wordpress/scripts` scaffold; `bom-editor` entry is an empty stub, not yet enqueued (Phase 1 work).

**Gotchas hit getting tooling to actually run clean (all fixed, all documented here so they aren't re-discovered):**
1. **PHPStan + WooCommerce stubs need real memory and no parallelism.** Plain `vendor/bin/phpstan analyse` crashed at the default memory limit even at 512M–1536M *in parallel mode* — each parallel worker loads its own copy of the (large) WooCommerce stubs. Fix baked into `phpstan.neon.dist`: `parameters.parallel.maximumNumberOfProcesses: 1`. Fix baked into `package.json`'s `analyze` script: `--memory-limit=1536M`. With both, a plain `composer`/`npm run analyze` just works — no flags to remember.
2. **WP-CLI stubs: use `wp-cli-stubs.php` only, not `wp-cli-commands-stubs.php`.** The latter references `Composer\IO\NullIO`, which isn't loaded in PHPStan's bootstrap context and throws. Only the base stub is needed to satisfy `WP_CLI::*` calls in `Commands.php`.
3. **`excludePaths` entries must exist or be marked optional.** `phpstan.neon.dist` excludes `vendor` and `node_modules` — before either is installed, PHPStan errors on a missing path unless suffixed `(?)`. Both are now marked optional.
4. **Root-owned npm cache** (`~/.npm/_cacache/...`, leftover from some earlier `sudo npm` invocation) broke `npm install` outright (`EACCES`/`EEXIST` on cache writes). Fixed without touching the broken cache (would need sudo) by pointing npm at a fresh cache dir instead: `npm config set cache "/Users/colin/.npm-cache-wcbom" --location=user`. If `npm install` ever fails with cache `EACCES`/`EEXIST` again on this machine, check `npm config get cache` first.
5. **`npx wp-env start` reliably fails silently partway through in this environment (Claude Code's sandboxed shell), every time it was tried, in both foreground and backgrounded runs.** It downloads the plugin zips fine and extracts them into `~/.wp-env/<hash>/<slug>.temp/<slug>/`, but the final "move `.temp/<slug>` → `<slug>` and build the wordpress/cli images" step never happens — the process just exits 0 right after the mysql container starts, as if finished, with no error anywhere. **Root cause unconfirmed** — may be specific to running wp-env through this harness's Bash tool rather than a real interactive Terminal; worth just trying `wp-env start` directly in a normal Terminal at home before assuming this workaround is needed there too. **Workaround that got a fully working environment (used this session):**
   - Let `wp-env start` run once (it generates `~/.wp-env/<hash>/docker-compose.yml` + Dockerfiles + downloads/partially-extracts the zips even though it won't finish).
   - `cd ~/.wp-env/<hash>` and check each plugin folder for a top-level `*.php` file (`ls <slug>/*.php`). If missing/incomplete (this session: all three were incomplete — only a couple of subfolders each, no main plugin file), delete the folder and redo it yourself: `curl -fSL https://downloads.wordpress.org/plugin/<slug>.zip -o /tmp/<slug>.zip && unzip -q /tmp/<slug>.zip -d ~/.wp-env/<hash>/`.
   - `docker compose up -d` (brings up mysql, builds + starts wordpress/cli).
   - `docker compose exec -T cli wp core install --path=/var/www/html --url="http://localhost:8888" --title="wc-bom-stock dev" --admin_user=admin --admin_password=password --admin_email=[redacted] --skip-email`
   - `docker compose exec -T cli wp plugin activate woocommerce woo-extra-product-options variation-swatches-woo wordpress-bom/wc-bom-stock.php --path=/var/www/html` — **note the plugin identifier**: it's `wordpress-bom/wc-bom-stock.php`, not `wc-bom-stock`. wp-env mounts the plugin-under-development using the **local repo directory's basename** as the in-container folder name, and this repo's directory is `wordpress-bom` (not `wc-bom-stock`). This is why `.wp-env.json`'s `lifecycleScripts.afterStart` also references the full `wordpress-bom/wc-bom-stock.php` path. **This will keep working at home as long as `git clone` isn't given a custom target dirname** (plain `git clone https://github.com/croix/wordpress-bom.git` preserves the `wordpress-bom` folder name automatically) — if anyone ever clones this into a differently-named directory, update the folder segment in `.wp-env.json`'s `lifecycleScripts.afterStart` to match.
   - `docker compose exec -T cli wp wcbom seed --path=/var/www/html`
6. **If a plugin folder under `~/.wp-env/<hash>/` is deleted and recreated *while the wordpress/cli containers are already running*, Docker's bind mount goes stale and shows the folder as empty inside the container**, even though the host filesystem is correct. Fix: `docker compose restart wordpress cli` — it re-resolves the mount. (This bit us mid-session: after manually re-extracting the plugin zips, `wp plugin list` still couldn't see them until we restarted those two containers.)

**Verified working end-to-end this session** (via `docker compose exec` and a real browser login at `http://localhost:8888`, admin/password):
- `composer install`, `vendor/bin/phpcs` (clean), `vendor/bin/phpstan analyse` (clean, level 6) all run successfully on this machine.
- `wp wcbom seed` → `Success: Seeded 10 components, 1 premade product (#20), 1 made-to-order product (#21) with BOM #1.`
- Queried `wp_wcbom_boms`/`wp_wcbom_bom_items` directly — one BOM header + 7 lines, exactly matching the intended always/attribute-conditional split (blank tumbler/epoxy/standard cap always; glitter color and straw choice attribute-conditional).
- 4 variations created on the made-to-order product with correct attribute combinations and prices ($24.99 standard straw / $29.99 upgraded).
- wp-admin Products list: all 12 products present, correct stock quantities, variable product correctly shows a `$24.99–$29.99` price range.
- No entries at all in `wp-content/debug.log` (WP_DEBUG_LOG on) — no PHP notices/warnings/errors anywhere in the flow.
- Plugins screen: all 4 plugins (WooCommerce, ThemeHigh EPO, variation-swatches-woo, wc-bom-stock) active, no error banners.

**Current running state:** the wp-env stack is up right now at `http://localhost:8888` (wp-admin: `admin` / `password`), hash dir `~/.wp-env/ea23fdf09a0f4cde2cad3f159c4e344a`. Not destroyed — should still be running/resumable via `docker start` on the same containers if this session's Docker Desktop instance is still around; otherwise redo the workaround in point 5 above (steps after the initial `wp-env start` generates the compose files).

**Next step: Phase 1** — ledger (`Stock\Ledger`), `Stock\StockService` (row-locked, `wc_update_product_stock()`), and the React BOM editor metabox (`Admin\ProductBomMetabox` + `assets/src/bom-editor`) reading/writing the same `wcbom_boms`/`wcbom_bom_items` tables the seeder already populates.

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
