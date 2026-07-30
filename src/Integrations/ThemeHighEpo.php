<?php
/**
 * ThemeHigh Extra Product Options integration.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Integrations;

use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies add-on field values to addon-conditional BOM lines by exposing
 * the order item's visible meta — which is where ThemeHigh EPO records the
 * customer's selected field values. Parsed defensively (BUILD_PLAN §12
 * risk 6): unrecognized/missing data simply yields no addon values, which
 * makes addon-conditional lines not match — never a fatal.
 *
 * Keys are passed through sanitize_key() to align with how the BOM editor
 * stores condition_key, so a BOM line's "Add-on field name" matches the
 * EPO field name/label regardless of case or spacing.
 */
final class ThemeHighEpo {

	/**
	 * Hooks the addon-values filters (order-item time and cart time).
	 */
	public function register(): void {
		add_filter( 'wcbom_order_item_addon_values', array( $this, 'provide_values' ), 10, 2 );
		add_filter( 'wcbom_cart_item_addon_values', array( $this, 'provide_cart_values' ), 10, 2 );
	}

	/**
	 * Exposes the item's visible meta as sanitized key => value pairs.
	 *
	 * @param array<string,string>  $values Values from earlier providers.
	 * @param WC_Order_Item_Product $item   The order line item.
	 * @return array<string,string>
	 */
	public function provide_values( array $values, WC_Order_Item_Product $item ): array {
		foreach ( $item->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) $data['key'];

			// Underscore-prefixed meta is internal (including our own
			// _wcbom_* snapshots) — EPO's customer-facing values are not.
			if ( '' === $key || str_starts_with( $key, '_' ) ) {
				continue;
			}

			if ( ! is_scalar( $data['value'] ) ) {
				continue;
			}

			$sanitized_key = sanitize_key( $key );
			if ( '' !== $sanitized_key && ! isset( $values[ $sanitized_key ] ) ) {
				$values[ $sanitized_key ] = (string) $data['value'];
			}
		}

		return $values;
	}

	/**
	 * Exposes a cart item's EPO field selections as sanitized key => value
	 * pairs, for BOM lines that need to gate/surcharge on an add-on choice
	 * before an order exists (Cart\CartPricing's weight/surcharge display).
	 * EPO free stores these under `$cart_item['thwepof_options']`, keyed by
	 * the field's internal name — the same key it later writes as the
	 * order item's meta key (`wc_add_order_item_meta( $item_id, $name,
	 * $value )`), so `sanitize_key( $name )` here matches
	 * `sanitize_key( $key )` in provide_values() above for the same field.
	 *
	 * @param array<string,string> $values    Values from earlier providers.
	 * @param array<string,mixed>  $cart_item The cart item array.
	 * @return array<string,string>
	 */
	public function provide_cart_values( array $values, array $cart_item ): array {
		$options = $cart_item['thwepof_options'] ?? null;
		if ( ! is_array( $options ) ) {
			return $values;
		}

		foreach ( $options as $name => $data ) {
			if ( ! is_array( $data ) || ! isset( $data['value'] ) || ! is_scalar( $data['value'] ) ) {
				continue;
			}

			$sanitized_key = sanitize_key( (string) $name );
			if ( '' !== $sanitized_key && ! isset( $values[ $sanitized_key ] ) ) {
				$values[ $sanitized_key ] = (string) $data['value'];
			}
		}

		return $values;
	}
}
