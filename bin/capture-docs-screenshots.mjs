#!/usr/bin/env node
/**
 * Regenerates every screenshot the in-app Guide (BUILD_PLAN.md §5.12)
 * references, into assets/docs/. Dev-only — never shipped (see
 * bin/build-release-zip.sh, which only stages runtime files).
 *
 * What "deterministic" means here, and why it matters: re-running this
 * script twice with no code change must produce an identical assets/docs/
 * (a git diff of zero) — otherwise every regeneration becomes an
 * unreviewable diff. That's why every run starts by wiping and rebuilding
 * the fixture from scratch (bin/docs-fixture-cleanup.php, then
 * `wp wcbom seed --reset`, then bin/docs-fixture.php) rather than
 * screenshotting whatever state a dev environment happens to be in.
 *
 * Usage: npm run docs:screenshots
 * Requires: `npx wp-env start` already running, admin/password (the
 * documented dev credentials — see CLAUDE.md), Playwright's chromium
 * installed (`npx playwright install chromium` once per machine).
 */

import { chromium } from 'playwright';
import sharp from 'sharp';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readdirSync, unlinkSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const ROOT = path.resolve( __dirname, '..' );
const DOCS_DIR = path.join( ROOT, 'assets', 'docs' );
const BASE_URL = process.env.WCBOM_DOCS_BASE_URL || 'http://localhost:8888';

// wp-env names the in-container plugin folder after this repo's directory
// name, not the plugin slug — see CLAUDE.md's "plugin identifier" gotcha.
// This assumes a plain `git clone` (no custom target dirname).
const CONTAINER_PLUGIN_PATH = '/var/www/html/wp-content/plugins/wordpress-bom';

const VIEWPORT = { width: 1440, height: 900 };
const OUTPUT_WIDTH = 1200;

// Strips third-party admin chrome so screenshots only ever show this
// plugin's own UI (BUILD_PLAN.md §5.12, added 2026-07-31 after live
// WP Mail SMTP / Action Scheduler notices showed up in every screen).
// A blanket strip, not an allowlist of today's offenders, so it stays
// robust against whatever happens to be installed in the capture
// environment.
const NOTICE_STRIP_CSS =
	'#wpbody-content .notice, #wpbody-content .updated, #wpbody-content .error, ' +
	'#wpadminbar #wp-admin-bar-woocommerce-site-visibility { display: none !important; }';

function wpCli( args ) {
	console.log( `> wp ${ args.join( ' ' ) }` );
	execFileSync( 'npx', [ 'wp-env', 'run', 'cli', '--', 'wp', ...args, '--path=/var/www/html' ], {
		stdio: 'inherit',
		cwd: ROOT,
	} );
}

async function login( page ) {
	await page.goto( `${ BASE_URL }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForSelector( '#wpadminbar' );
}

async function stripNotices( page ) {
	await page.addStyleTag( { content: NOTICE_STRIP_CSS } );
}

async function goto( page, adminPath ) {
	await page.goto( `${ BASE_URL }/wp-admin/${ adminPath }` );
	await stripNotices( page );
	await page.waitForLoadState( 'networkidle' );
}

async function capture( page, file, alt, { fullPage = false } = {} ) {
	if ( fullPage ) {
		// Chromium's full-page screenshot stitches together several
		// viewport-height captures while scrolling. Anything position:fixed
		// stays pinned to the viewport during that scroll, so it can get
		// captured again at each stitch point instead of only once at the
		// true top — a well-known Playwright/Puppeteer full-page-screenshot
		// artifact, not a WordPress or plugin bug. Two different fixed
		// elements caused this here: WordPress's own #wpadminbar, and (the
		// one that survived the first fix, traced by querying every
		// computed-fixed/sticky element on the page) WooCommerce's own
		// `.woocommerce-layout__header.is-scrolled.is-chrome-only` — a
		// Chrome-specific sticky clone of its embedded page header that it
		// shows once the page scrolls. Hiding both outright removes them
		// from layout entirely, leaving nothing for the stitcher to
		// duplicate. A brief settle wait lets the reflow finish before
		// Playwright starts scrolling/capturing.
		await page.addStyleTag( { content: '#wpadminbar, .woocommerce-layout__header.is-scrolled { display: none !important; }' } );
		await page.waitForTimeout( 100 );
	}

	const raw = await page.screenshot( { fullPage } );
	await sharp( raw ).resize( { width: OUTPUT_WIDTH } ).png( { quality: 90 } ).toFile( path.join( DOCS_DIR, file ) );
	console.log( `  ✔ ${ file } — ${ alt }` );
}

async function productIdBySku( sku ) {
	const out = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', '--', 'wp', 'post', 'list', '--post_type=product', '--field=ID', `--meta_key=_sku`, `--meta_value=${ sku }`, '--path=/var/www/html' ],
		{ cwd: ROOT }
	).toString().trim();
	const id = parseInt( out.split( '\n' ).pop(), 10 );
	if ( ! id ) {
		throw new Error( `No product found for SKU ${ sku }` );
	}
	return id;
}

async function main() {
	mkdirSync( DOCS_DIR, { recursive: true } );
	for ( const existing of readdirSync( DOCS_DIR ) ) {
		unlinkSync( path.join( DOCS_DIR, existing ) );
	}

	// docs-fixture.php (vendor/PO/MO demo data) is deliberately NOT run yet
	// here — its own generated products carry BOMs that reference the
	// sample components, which would block the "remove sample products"
	// demo click below with the same conflict DeletionGuard protects
	// against elsewhere. It runs once, after that reinstall dance, instead.
	console.log( 'Rebuilding fixture (cleanup → seed --reset → vendors on)...' );
	wpCli( [ 'eval-file', `${ CONTAINER_PLUGIN_PATH }/bin/docs-fixture-cleanup.php` ] );
	wpCli( [ 'wcbom', 'seed', '--reset' ] );
	wpCli( [ 'option', 'update', 'wcbom_vendors_enabled', 'yes' ] );

	const browser = await chromium.launch();
	const context = await browser.newContext( { viewport: VIEWPORT } );
	const page = await context.newPage();

	await login( page );

	console.log( 'Orientation...' );
	await goto( page, 'admin.php?page=wcbom' );
	await capture( page, 'orientation-menu.png', 'menu overview' );

	console.log( 'First-run setup...' );
	await goto( page, 'admin.php?page=wcbom' );
	await page.getByRole( 'button', { name: 'Remove sample products' } ).click();
	await page.waitForSelector( 'text=No components yet' );
	await stripNotices( page );
	await capture( page, 'first-run-empty-inventory.png', 'empty inventory, offering sample install' );
	await page.getByRole( 'button', { name: 'Install sample products' } ).click();
	await page.waitForSelector( 'table.wcbom-inventory-table' );

	// Rebuild the rest of the fixture now that sample install minted fresh
	// product IDs (SKU-based, so this is safe to re-run).
	wpCli( [ 'eval-file', `${ CONTAINER_PLUGIN_PATH }/bin/docs-fixture.php` ] );
	const blankId2 = await productIdBySku( 'WCBOM-BLANK' );
	const customTumblerId2 = await productIdBySku( 'WCBOM-CUSTOM-TUMBLER' );

	await goto( page, `post.php?post=${ blankId2 }&action=edit` );
	await page.getByRole( 'link', { name: 'Bill of Materials' } ).click();
	await capture( page, 'first-run-component-flag.png', 'component flag + unit on a product edit screen' );

	console.log( 'Building a BOM...' );
	await goto( page, `post.php?post=${ customTumblerId2 }&action=edit` );
	await page.getByRole( 'link', { name: 'Bill of Materials' } ).click();
	await page.waitForSelector( '#wcbom-bom-editor-root table' );
	await page.waitForTimeout( 500 ); // let the buildable/cost estimate finish loading.
	await capture( page, 'bom-editor-lines.png', 'checkboxes + BOM lines + buildable/cost estimate', { fullPage: true } );

	await goto( page, `post.php?post=${ await productIdBySku( 'WCBOM-PREMADE-OCEAN' ) }&action=edit` );
	await page.getByRole( 'link', { name: 'Bill of Materials' } ).click();
	await page.waitForSelector( '#wcbom-bom-editor-root table' );
	await page.waitForTimeout( 500 );
	await capture( page, 'bom-editor-subassembly.png', 'a BOM line whose component is a manufactured sub-assembly', { fullPage: true } );

	console.log( 'Component Inventory...' );
	await goto( page, 'admin.php?page=wcbom' );
	await page.waitForSelector( 'table.wcbom-inventory-table' );
	await capture( page, 'inventory-table.png', 'component table with stock/usage/movement' );
	await page.getByRole( 'button', { name: 'Count' } ).first().click();
	await page.waitForSelector( '.components-modal__frame' );
	await page.waitForTimeout( 200 );
	await capture( page, 'inventory-receive-modal.png', 'stock modal (count/adjust/receive share this shape)' );
	await page.keyboard.press( 'Escape' );
	await page.waitForSelector( '.components-modal__frame', { state: 'detached' } );

	console.log( 'Manufacturing...' );
	await goto( page, 'admin.php?page=wcbom-manufacturing' );
	await page.waitForSelector( 'table' );
	await capture( page, 'manufacture-list.png', 'manufacture order list' );

	await page.getByRole( 'button', { name: 'Complete' } ).click();
	await page.waitForSelector( '.components-modal__frame' );
	await page.waitForSelector( 'text=shortage', { timeout: 10000 } ).catch( () => {} );
	await page.waitForTimeout( 200 );
	await capture( page, 'manufacture-complete-shortage.png', 'complete modal with a component shortage table' );
	await page.keyboard.press( 'Escape' );
	await page.waitForSelector( '.components-modal__frame', { state: 'detached' } );

	await page.getByRole( 'button', { name: 'Reverse' } ).click();
	await page.waitForSelector( '.components-modal__frame' );
	await page.waitForTimeout( 200 );
	await capture( page, 'manufacture-reverse-scrap.png', 'reverse modal with per-component scrap checkboxes' );
	await page.keyboard.press( 'Escape' );
	await page.waitForSelector( '.components-modal__frame', { state: 'detached' } );

	console.log( 'Vendors & Purchase Orders...' );
	await goto( page, 'admin.php?page=wcbom-purchasing' );
	await page.waitForSelector( 'table' );
	await capture( page, 'purchasing-po-list.png', 'purchase order list' );

	await page.getByRole( 'tab', { name: 'Vendors' } ).click();
	await page.waitForSelector( 'table' );
	await capture( page, 'purchasing-vendors.png', 'vendor list' );

	await page.getByRole( 'tab', { name: 'Purchase Orders' } ).click();
	await page.waitForSelector( 'table' );
	await page.waitForTimeout( 400 ); // let the tab switch fully settle before opening a modal.
	await page.getByRole( 'button', { name: /New purchase order/i } ).click();
	await page.waitForSelector( '.components-modal__frame' );
	await page.waitForTimeout( 200 );
	await capture( page, 'purchasing-new-po.png', 'new purchase order modal' );
	await page.keyboard.press( 'Escape' );
	await page.waitForSelector( '.components-modal__frame', { state: 'detached' } );

	await page.getByRole( 'button', { name: 'Receive' } ).first().click();
	await page.waitForSelector( '.components-modal__frame' );
	await page.waitForTimeout( 200 );
	await capture( page, 'purchasing-receive-costs.png', 'receive modal' );
	await page.keyboard.press( 'Escape' );

	console.log( 'Reports...' );
	await goto( page, 'admin.php?page=wcbom-reports' );
	await page.waitForSelector( '[role="tabpanel"], table' );
	await capture( page, 'reports-buildable.png', 'Buildable tab' );

	await page.getByRole( 'tab', { name: 'Low Stock' } ).click();
	await page.waitForTimeout( 300 );
	await capture( page, 'reports-low-stock.png', 'Low Stock tab' );

	await page.getByRole( 'tab', { name: 'Margin' } ).click();
	await page.waitForTimeout( 300 );
	await capture( page, 'reports-margin.png', 'Margin tab' );

	await page.getByRole( 'tab', { name: 'Component Usage' } ).click();
	await page.waitForTimeout( 300 );
	await capture( page, 'reports-usage.png', 'Component Usage tab' );

	await page.getByRole( 'tab', { name: 'Ledger' } ).click();
	await page.waitForTimeout( 300 );
	await capture( page, 'reports-ledger.png', 'Ledger tab' );

	console.log( 'Settings...' );
	await goto( page, 'admin.php?page=wcbom-settings' );
	await capture( page, 'settings-page.png', 'Settings page' );

	console.log( 'Endpoints...' );
	await goto( page, 'admin.php?page=wcbom-endpoints' );
	await capture( page, 'endpoints-page.png', 'Endpoints page' );

	await browser.close();

	console.log( `\nDone. ${ readdirSync( DOCS_DIR ).length } screenshots in ${ path.relative( ROOT, DOCS_DIR ) }/` );
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
