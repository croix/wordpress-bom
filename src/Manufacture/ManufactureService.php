<?php
/**
 * Orchestrates manufacture order creation, completion, and reversal.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Manufacture;

use WCBOM\Bom\BomRepository;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\NegativeStockPolicy;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\StockService;

defined( 'ABSPATH' ) || exit;

/**
 * Draft creation moves no stock (BUILD_PLAN.md §5.4). Completion and
 * reversal each do their real stock movement via one
 * StockService::adjust_many() call — a single mixed-sign delta map
 * covering components down (or up, on reversal) *and* the finished good
 * up (or down) in the same atomic transaction, per §13.6 rule 2. The
 * manufacture-order bookkeeping (snapshot rows, status flip) happens
 * immediately after that commit, never before — identical reasoning to
 * §13.5's order-consumption snapshot ordering: a crash in that narrow
 * window leaves ledger rows that prove the movement happened but an MO
 * still showing its prior status, which is detectable and recoverable
 * (Phase 5's `wp wcbom audit`), never silently wrong in the other
 * direction (bookkeeping claiming a movement that never happened).
 */
final class ManufactureService {

	/**
	 * Constructs the service.
	 *
	 * @param ManufactureRepository $orders  MO persistence.
	 * @param BomRepository         $boms    BOM lookup.
	 * @param StockService          $stock   The single stock-mutation path.
	 * @param OperationGuard        $guard   Idempotency-key claim/release.
	 * @param ProductFactory        $factory Creates a product from a template.
	 */
	public function __construct(
		private readonly ManufactureRepository $orders,
		private readonly BomRepository $boms,
		private readonly StockService $stock,
		private readonly OperationGuard $guard,
		private readonly ProductFactory $factory
	) {}

	/**
	 * Creates a draft MO to restock an existing manufactured product.
	 *
	 * @param int         $product_id The existing manufactured product.
	 * @param int         $qty_built  Units to build.
	 * @param string|null $notes      Free-text notes.
	 *
	 * @throws \RuntimeException If the product has no active BOM.
	 */
	public function create_draft_for_existing( int $product_id, int $qty_built, ?string $notes ): ManufactureOrder {
		$bom = $this->boms->get_active_for_product( $product_id );
		if ( null === $bom ) {
			throw new \RuntimeException( esc_html__( 'This product has no active BOM.', 'wcbom' ) );
		}

		$mo_id = $this->orders->create_draft( $product_id, $bom->bom_id, $qty_built, $notes, get_current_user_id() );

		return $this->must_get( $mo_id );
	}

	/**
	 * Creates a new manufactured product from a template, plus a draft MO
	 * to build the first batch of it.
	 *
	 * @param int                  $template_product_id The made-to-order product to base this on.
	 * @param string               $title               New product title.
	 * @param string               $price                New product's regular price.
	 * @param array<string,string> $attributes           Chosen attribute combination.
	 * @param int                  $qty_built            Units to build.
	 * @param string|null          $notes                Free-text notes.
	 */
	public function create_draft_from_template(
		int $template_product_id,
		string $title,
		string $price,
		array $attributes,
		int $qty_built,
		?string $notes
	): ManufactureOrder {
		$result = $this->factory->create_from_template( $template_product_id, $title, $price, $attributes );
		$mo_id  = $this->orders->create_draft( $result['product_id'], $result['bom_id'], $qty_built, $notes, get_current_user_id() );

		return $this->must_get( $mo_id );
	}

	/**
	 * Completes a draft MO: consumes components, increments finished
	 * stock, snapshots the recipe used, flips status to completed.
	 * Idempotent by state (a non-draft MO is returned as-is) and by
	 * op_key (a claimed-but-failed key is released so a corrected retry
	 * with the same key still works).
	 *
	 * @param int    $mo_id          The manufacture order to complete.
	 * @param string $op_key         Client-generated idempotency key.
	 * @param bool   $allow_negative Build even if components would go negative.
	 *
	 * @throws \RuntimeException If the MO is unknown or its BOM is gone.
	 * @throws \Throwable Re-thrown (after releasing the op_key) from the
	 *                    underlying stock adjustment — most commonly
	 *                    InsufficientStockException if a component is
	 *                    short and $allow_negative is false.
	 */
	public function complete( int $mo_id, string $op_key, bool $allow_negative = false ): ManufactureOrder {
		// The sitewide setting (§11.2) makes the per-operation "build
		// anyway" override unnecessary; OR-ing it here rather than in the
		// REST layer means every caller honors it and it's unit-testable.
		$allow_negative = $allow_negative || NegativeStockPolicy::allowed();

		$mo = $this->must_get( $mo_id );

		if ( ManufactureOrder::STATUS_DRAFT !== $mo->status ) {
			return $mo; // Already completed (or beyond) — state-machine idempotency.
		}

		$bom = $this->boms->get( $mo->bom_id );
		if ( null === $bom || array() === $bom->items ) {
			throw new \RuntimeException( esc_html__( 'The BOM used for this manufacture order no longer has any lines.', 'wcbom' ) );
		}

		if ( ! $this->guard->claim( $op_key, "Complete MO #{$mo_id}" ) ) {
			return $mo; // A prior identical request already completed this.
		}

		try {
			$deltas         = array();
			$snapshot_items = array();

			foreach ( $bom->items as $line ) {
				$qty_total = $line->qty * $mo->qty_built;
				$component = wc_get_product( $line->component_id );

				$deltas[ $line->component_id ] = ( $deltas[ $line->component_id ] ?? 0.0 ) - $qty_total;

				$snapshot_items[] = array(
					'component_id' => $line->component_id,
					'qty_per_unit' => $line->qty,
					'qty_total'    => $qty_total,
					'unit_cost'    => $component ? (float) $component->get_regular_price() : null,
				);
			}
			$deltas[ $mo->product_id ] = ( $deltas[ $mo->product_id ] ?? 0.0 ) + $mo->qty_built;

			$this->stock->adjust_many(
				$deltas,
				Ledger::REASON_MANUFACTURE,
				'manufacture_order',
				$mo_id,
				sprintf( 'Manufacture Order #%d: built %d unit(s)', $mo_id, $mo->qty_built ),
				$allow_negative
			);
		} catch ( \Throwable $e ) {
			// Nothing committed (StockService rolled back atomically) — the
			// key must not stay claimed, or a corrected retry would be
			// silently ignored as "already applied".
			$this->guard->release( $op_key );
			throw $e;
		}

		// Bookkeeping after the stock movement commits — see class docblock.
		$this->orders->save_items( $mo_id, $snapshot_items );
		$this->orders->mark_completed( $mo_id );

		return $this->must_get( $mo_id );
	}

	/**
	 * Reverses N units of a completed manufacture order, restoring
	 * components from the completion snapshot (never the current BOM).
	 * Components in $scrap_component_ids are not restored — a zero-delta
	 * ledger note documents why instead (they weren't recoverable, e.g.
	 * cured epoxy).
	 *
	 * @param int            $mo_id               The manufacture order to reverse.
	 * @param int            $qty                 Units to reverse (1..remaining).
	 * @param string         $op_key              Client-generated idempotency key.
	 * @param array<int,int> $scrap_component_ids Component IDs to skip restoring.
	 *
	 * @throws \RuntimeException If the MO can't be reversed in its current
	 *                           state, $qty is out of range, or finished
	 *                           stock can't cover it (some may be sold).
	 * @throws \Throwable Re-thrown (after releasing the op_key) if the
	 *                    underlying stock adjustment fails for any reason.
	 */
	public function reverse( int $mo_id, int $qty, string $op_key, array $scrap_component_ids = array() ): ManufactureOrder {
		$mo = $this->must_get( $mo_id );

		if ( ! in_array( $mo->status, array( ManufactureOrder::STATUS_COMPLETED, ManufactureOrder::STATUS_PARTIALLY_REVERSED ), true ) ) {
			throw new \RuntimeException( esc_html__( 'This manufacture order cannot be reversed from its current status.', 'wcbom' ) );
		}

		if ( $qty <= 0 || $qty > $mo->remaining_units() ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %d: units still available to reverse */
						__( 'Enter between 1 and %d unit(s) to reverse.', 'wcbom' ),
						$mo->remaining_units()
					)
				)
			);
		}

		$finished = wc_get_product( $mo->product_id );
		if ( ! $finished || (float) $finished->get_stock_quantity() < $qty ) {
			throw new \RuntimeException( esc_html__( 'Not enough finished-good stock to reverse — some units may already be sold.', 'wcbom' ) );
		}

		if ( ! $this->guard->claim( $op_key, "Reverse MO #{$mo_id} x{$qty}" ) ) {
			return $mo; // A prior identical request already applied this reversal.
		}

		try {
			$deltas = array( $mo->product_id => -1.0 * $qty );
			$ledger = new Ledger();

			foreach ( $mo->items as $item ) {
				$restore_qty = $item->qty_per_unit * $qty;

				if ( in_array( $item->component_id, $scrap_component_ids, true ) ) {
					$component = wc_get_product( $item->component_id );
					$ledger->record(
						$item->component_id,
						0.0,
						$component ? (float) $component->get_stock_quantity() : null,
						Ledger::REASON_MANUAL_ADJUST,
						'manufacture_order',
						$mo_id,
						sprintf( 'Scrapped on MO #%d reversal — %s not restored.', $mo_id, rtrim( rtrim( number_format( $restore_qty, 4 ), '0' ), '.' ) )
					);
					continue;
				}

				$deltas[ $item->component_id ] = ( $deltas[ $item->component_id ] ?? 0.0 ) + $restore_qty;
			}

			$this->stock->adjust_many(
				$deltas,
				Ledger::REASON_MANUFACTURE_REVERSE,
				'manufacture_order',
				$mo_id,
				sprintf( 'Manufacture Order #%d reversal: %d unit(s)', $mo_id, $qty ),
				true // Finished-good availability already checked above; components only ever increase.
			);
		} catch ( \Throwable $e ) {
			$this->guard->release( $op_key );
			throw $e;
		}

		$new_qty_reversed = $mo->qty_reversed + $qty;
		$new_status       = $new_qty_reversed >= $mo->qty_built ? ManufactureOrder::STATUS_REVERSED : ManufactureOrder::STATUS_PARTIALLY_REVERSED;
		$this->orders->update_reversal( $mo_id, $new_qty_reversed, $new_status );

		return $this->must_get( $mo_id );
	}

	/**
	 * Deletes a draft MO outright — safe because a draft never moved
	 * any stock. Refuses for any other status.
	 *
	 * @param int $mo_id The manufacture order to delete.
	 */
	public function delete_draft( int $mo_id ): bool {
		$mo = $this->orders->get( $mo_id );
		if ( null === $mo || ManufactureOrder::STATUS_DRAFT !== $mo->status ) {
			return false;
		}

		return $this->orders->delete_draft( $mo_id );
	}

	/**
	 * Fetches a manufacture order or throws.
	 *
	 * @param int $mo_id The manufacture order's ID.
	 *
	 * @throws \RuntimeException If no such manufacture order exists.
	 */
	private function must_get( int $mo_id ): ManufactureOrder {
		$mo = $this->orders->get( $mo_id );
		if ( null === $mo ) {
			throw new \RuntimeException( esc_html__( 'Unknown manufacture order.', 'wcbom' ) );
		}

		return $mo;
	}
}
