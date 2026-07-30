<?php
/**
 * WP-CLI commands for wc-bom-stock.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Cli;

use WCBOM\Install\SampleData;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp wcbom ...` commands. `seed` installs the sample tumbler catalog
 * every phase of this plugin is demoed against (see BUILD_PLAN.md
 * conventions) — the same catalog the Inventory screen's "Install sample
 * products" button creates (Install\SampleData).
 */
final class Commands {

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
}
