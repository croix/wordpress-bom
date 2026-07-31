<?php
/**
 * WP-CLI commands for wc-bom-stock.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Cli;

use WC_Order_Item_Product;
use WC_Product_Variable;
use WCBOM\Bom\BomCsv;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ProductMode;
use WCBOM\Install\SampleData;
use WCBOM\Manufacture\ManufactureOrder;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Orders\OrderSync;
use WCBOM\Purchasing\PurchaseOrderRepository;
use WCBOM\Purchasing\VendorsFeature;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\PhantomStock;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp wcbom ...` commands: seed (sample catalog), audit (crash-safety
 * recovery checklist, BUILD_PLAN.md §13.7), recompute (buildable-qty
 * cache rebuild), import (BOM CSV, BUILD_PLAN.md §5.8).
 */
final class Commands {

	private const STALE_DRAFT_MINUTES = 60;
	private const STALE_OPS_SECONDS   = HOUR_IN_SECONDS;

	/**
	 * Installs the sample tumbler catalog: components, a premade
	 * (manufactured) product, and a made-to-order customizable product
	 * with a starter BOM.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Delete any previously installed sample products/BOMs first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcbom seed
	 *     wp wcbom seed --reset
	 *
	 * @param array<int,string>    $args Positional arguments (unused).
	 * @param array<string,string> $assoc_args Flags.
	 * @when after_wp_load
	 */
	public function seed( array $args, array $assoc_args ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce must be active to install sample data.' );
		}

		$sample_data = new SampleData();

		if ( isset( $assoc_args['reset'] ) ) {
			try {
				$removed = $sample_data->remove();
			} catch ( \RuntimeException $e ) {
				WP_CLI::error( $e->getMessage() );
				return;
			}
			WP_CLI::log( sprintf( 'Removed %d previously installed sample product(s).', $removed ) );
		}

		try {
			$result = $sample_data->install();
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() . ' Use --reset to reinstall.' );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Seeded %d components, 1 premade product (#%d), 1 made-to-order product (#%d) with BOM #%d.',
				$result['components'],
				$result['premade_id'],
				$result['product_id'],
				$result['bom_id']
			)
		);
	}

	/**
	 * Runs the crash-safety recovery checklist (BUILD_PLAN.md §13.7):
	 * (a) live stock vs. last ledger entry drift, (b) orders with
	 * consumption ledger rows but no snapshot meta, (c) manufacture
	 * orders stuck in draft, (d) stale idempotency-key rows.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Rebuild missing order-item consumption snapshots (check b) where
	 * it can be done unambiguously — only when the order has exactly one
	 * made-to-order item and it's the one missing its snapshot. Every
	 * other finding is report-only; act on it via the Manufacturing/
	 * Inventory screens.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcbom audit
	 *     wp wcbom audit --fix
	 *
	 * @param array<int,string>    $args Positional arguments (unused).
	 * @param array<string,string> $assoc_args Flags.
	 * @when after_wp_load
	 */
	public function audit( array $args, array $assoc_args ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce must be active to run the audit.' );
		}

		$fix    = isset( $assoc_args['fix'] );
		$issues = 0;

		$issues += $this->audit_stock_drift();
		$issues += $this->audit_missing_snapshots( $fix );
		$issues += $this->audit_stuck_drafts();
		$issues += $this->audit_stale_ops();
		$issues += $this->audit_orphaned_po_items();

		if ( $issues > 0 ) {
			WP_CLI::warning( sprintf( 'Audit complete: %d finding(s) above — most are informational, see each section.', $issues ) );
		} else {
			WP_CLI::success( 'Audit complete: nothing to report.' );
		}
	}

	/**
	 * Check (a): products whose live stock disagrees with their most
	 * recent ledger row's stock_after — informational, since a manual
	 * wp-admin edit or a third-party plugin legitimately moves stock
	 * outside the ledger.
	 */
	private function audit_stock_drift(): int {
		global $wpdb;

		WP_CLI::log( '--- (a) Stock drift: live stock vs. last ledger entry ---' );

		$product_ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$wpdb->prefix}wcbom_stock_ledger" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static query, no user input.
		$ledger      = new Ledger();
		$found       = 0;

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( (int) $product_id );
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}

			$recent = $ledger->for_product( (int) $product_id, 1 );
			if ( array() === $recent || null === $recent[0]['stock_after'] ) {
				continue;
			}

			$expected = (float) $recent[0]['stock_after'];
			$actual   = (float) $product->get_stock_quantity();

			if ( abs( $actual - $expected ) > 0.0001 ) {
				++$found;
				WP_CLI::log(
					sprintf(
						'  Drift: %s (#%d) — ledger expects %s, live stock is %s.',
						$product->get_name(),
						$product->get_id(),
						rtrim( rtrim( number_format( $expected, 4 ), '0' ), '.' ),
						rtrim( rtrim( number_format( $actual, 4 ), '0' ), '.' )
					)
				);
			}
		}

		WP_CLI::log( $found > 0 ? "  {$found} product(s) drifted from their last ledger entry." : '  No drift found.' );

		return $found;
	}

	/**
	 * Check (b): orders with `order`-reason ledger rows but a made-to-order
	 * item lacking its `_wcbom_consumed` snapshot (BUILD_PLAN.md §13.5's
	 * known crash window). With --fix, safely rebuilds only when the order
	 * has exactly one made-to-order item total — attributing that order's
	 * ledger rows to any other item would be a guess, so those are
	 * reported only.
	 *
	 * @param bool $fix Whether to attempt the safe single-item rebuild.
	 */
	private function audit_missing_snapshots( bool $fix ): int {
		global $wpdb;

		WP_CLI::log( '' );
		WP_CLI::log( '--- (b) Orders missing a consumption snapshot ---' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT ref_id FROM {$wpdb->prefix}wcbom_stock_ledger WHERE ref_type = %s AND reason = %s",
				'wc_order',
				Ledger::REASON_ORDER
			)
		);

		$boms   = new BomRepository();
		$ledger = new Ledger();
		$found  = 0;
		$fixed  = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order ) {
				continue;
			}

			$made_to_order_items = array();
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$product = $item->get_product();
				if ( $product && ProductMode::MADE_TO_ORDER === ProductMode::resolve( $product->get_id() ) ) {
					$made_to_order_items[] = $item;
				}
			}

			$missing = array_values(
				array_filter(
					$made_to_order_items,
					static fn( WC_Order_Item_Product $item ): bool => '' === (string) $item->get_meta( OrderSync::META_CONSUMED )
				)
			);

			if ( array() === $missing ) {
				continue;
			}

			$found += count( $missing );
			WP_CLI::log( sprintf( '  Order #%d: %d item(s) missing a snapshot.', $order_id, count( $missing ) ) );

			if ( ! $fix ) {
				continue;
			}

			if ( 1 !== count( $made_to_order_items ) || 1 !== count( $missing ) ) {
				WP_CLI::log( "    Skipped --fix: order has multiple made-to-order items — can't safely attribute which ledger rows belong to which." );
				continue;
			}

			$item       = $missing[0];
			$components = array();
			foreach ( $ledger->for_ref( 'wc_order', (int) $order_id ) as $row ) {
				if ( Ledger::REASON_ORDER !== $row['reason'] ) {
					continue;
				}
				$item_qty     = max( 1, (int) $item->get_quantity() );
				$components[] = array(
					'component_id' => (int) $row['product_id'],
					'qty_per_unit' => abs( (float) $row['delta'] ) / $item_qty,
					'qty_total'    => abs( (float) $row['delta'] ),
				);
			}

			if ( array() === $components ) {
				WP_CLI::log( '    Skipped --fix: no matching ledger rows found for this order.' );
				continue;
			}

			$active_bom = $boms->get_active_for_product( (int) $item->get_product_id() );
			$item->update_meta_data(
				OrderSync::META_CONSUMED,
				(string) wp_json_encode(
					array(
						'bom_id'     => $active_bom ? $active_bom->bom_id : 0,
						'item_qty'   => (int) $item->get_quantity(),
						'components' => $components,
					)
				)
			);
			$item->save();
			++$fixed;
			WP_CLI::log( sprintf( '    Rebuilt snapshot for order #%d from %d ledger row(s).', $order_id, count( $components ) ) );
		}

		WP_CLI::log( $found > 0 ? "  {$found} missing snapshot(s) found" . ( $fixed > 0 ? ", {$fixed} rebuilt." : '.' ) : '  No missing snapshots found.' );

		return $found;
	}

	/**
	 * Check (c): draft manufacture orders older than
	 * self::STALE_DRAFT_MINUTES — always safe to complete or delete via
	 * the Manufacturing screen (BUILD_PLAN.md §13.6 means a draft never
	 * has partial stock movement), so this is report-only.
	 */
	private function audit_stuck_drafts(): int {
		WP_CLI::log( '' );
		WP_CLI::log( '--- (c) Stuck draft manufacture orders ---' );

		$mo_repo = new ManufactureRepository();
		$now     = time();
		$found   = 0;

		foreach ( $mo_repo->list( ManufactureOrder::STATUS_DRAFT ) as $mo ) {
			$age_minutes = (int) round( ( $now - (int) strtotime( $mo->created_at . ' UTC' ) ) / 60 );
			if ( $age_minutes < self::STALE_DRAFT_MINUTES ) {
				continue;
			}

			++$found;
			WP_CLI::log(
				sprintf(
					'  MO #%d (product #%d, qty %d) has been draft for %d minute(s).',
					$mo->mo_id,
					$mo->product_id,
					$mo->qty_built,
					$age_minutes
				)
			);
		}

		WP_CLI::log(
			$found > 0
				? "  {$found} draft MO(s) older than " . self::STALE_DRAFT_MINUTES . ' minutes — review via the Manufacturing screen.'
				: '  No stuck drafts found.'
		);

		return $found;
	}

	/**
	 * Check (d): claimed idempotency keys with no recent activity.
	 * Informational only — a stale key just means the retry path is
	 * available again (OperationGuard has no schema link from a key back
	 * to specific ledger rows, so this can't be more precise than a count).
	 */
	private function audit_stale_ops(): int {
		global $wpdb;

		WP_CLI::log( '' );
		WP_CLI::log( '--- (d) Stale idempotency-key rows ---' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wcbom_ops WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_OPS_SECONDS )
			)
		);

		WP_CLI::log( "  {$count} claimed operation key(s) older than 1 hour — informational, the retry path already handles these." );

		return 0; // Never counted toward the "found issues" total — purely informational by design.
	}

	/**
	 * Check (e): purchase-order line items referencing a component
	 * product/variation that no longer exists — the same drift class as
	 * the orphaned-BOM check queued in the Phase 6 Progress Log. Silent
	 * (no section printed, no count) when VendorsFeature is disabled, so
	 * `wp wcbom audit`'s output stays identical to before Phase 9 for a
	 * merchant who's never turned the feature on (BUILD_PLAN.md §5.13/§9.20).
	 */
	private function audit_orphaned_po_items(): int {
		if ( ! VendorsFeature::enabled() ) {
			return 0;
		}

		WP_CLI::log( '' );
		WP_CLI::log( '--- (e) Purchase-order lines referencing a deleted component ---' );

		$orphans = ( new PurchaseOrderRepository() )->orphaned_items();

		if ( array() === $orphans ) {
			WP_CLI::log( '  No orphaned PO line items found.' );

			return 0;
		}

		foreach ( $orphans as $orphan ) {
			WP_CLI::log( "  PO #{$orphan['po_id']} line #{$orphan['poi_id']}: component #{$orphan['component_id']} no longer exists." );
		}

		return count( $orphans );
	}

	/**
	 * Clears and rebuilds the buildable-qty cache for every made-to-order
	 * product and variation — useful after any change that might leave
	 * PhantomStock's transient cache stale.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcbom recompute
	 *
	 * @param array<int,string>    $args Positional arguments (unused).
	 * @param array<string,string> $assoc_args Flags (unused).
	 * @when after_wp_load
	 */
	public function recompute( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI's command signature requires both params even when unused.
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce must be active to recompute buildable stock.' );
		}

		$phantom = new PhantomStock( new BomRepository() );
		$count   = 0;

		foreach ( ProductMode::products_with_mode( array( ProductMode::MADE_TO_ORDER ) ) as $product_id ) {
			$phantom->invalidate( $product_id );
			$qty = $phantom->get_buildable_qty( $product_id );
			++$count;

			$product = wc_get_product( $product_id );
			WP_CLI::log( sprintf( '  %s (#%d): %d', $product ? $product->get_name() : "#{$product_id}", $product_id, $qty ) );

			if ( $product instanceof WC_Product_Variable ) {
				foreach ( $product->get_children() as $variation_id ) {
					$phantom->get_buildable_qty( $variation_id );
					++$count;
				}
			}
		}

		WP_CLI::success( sprintf( 'Recomputed buildable-qty cache for %d product/variation(s).', $count ) );
	}

	/**
	 * Imports a BOM CSV file (BUILD_PLAN.md §5.8) — same format and
	 * behavior as the Reports screen's "Upload BOMs CSV" button.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcbom import boms.csv
	 *
	 * @param array<int,string>    $args Positional arguments: [0] the file path.
	 * @param array<string,string> $assoc_args Flags (unused).
	 * @when after_wp_load
	 */
	public function import( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI's command signature requires both params even when unused.
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce must be active to import BOMs.' );
		}

		$file = $args[0] ?? '';
		if ( '' === $file || ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', '' !== $file ? $file : '(none given)' ) );
			return;
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reading a locally-supplied file path, not a remote/user-facing request.
		if ( false === $content ) {
			WP_CLI::error( sprintf( 'Could not read file: %s', $file ) );
			return;
		}

		$result = ( new BomCsv( new BomRepository() ) )->import( $content, get_current_user_id() );

		foreach ( $result['updated'] as $name ) {
			WP_CLI::log( "Updated: {$name}" );
		}
		foreach ( $result['errors'] as $error ) {
			WP_CLI::warning( $error );
		}

		WP_CLI::success( sprintf( '%d BOM(s) updated, %d error(s).', count( $result['updated'] ), count( $result['errors'] ) ) );
	}
}
