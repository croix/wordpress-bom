<?php
/**
 * "Bill of Materials" product data tab.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WC_Product;
use WCBOM\Bom\BomRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the BOM editor tab to the product data panel: the component flag,
 * stock mode, and unit fields (plain WooCommerce form helpers), plus the
 * mount point and script enqueue for the React line editor.
 */
final class ProductBomMetabox {

	/**
	 * Constructs the metabox.
	 *
	 * @param BomRepository $boms Used for the "used in N products" reverse view.
	 */
	public function __construct( private readonly BomRepository $boms ) {}

	/**
	 * Hooks all product-data-panel and enqueue callbacks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the "Bill of Materials" tab to the product data panel tab list.
	 *
	 * @param array<string,array<string,mixed>> $tabs Existing tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_tab( array $tabs ): array {
		$tabs['wcbom'] = array(
			'label'    => __( 'Bill of Materials', 'wcbom' ),
			'target'   => 'wcbom_bom_data',
			'class'    => array(),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * Renders the tab's panel markup: mode/component/unit fields plus the
	 * BOM editor's mount point.
	 */
	public function render_panel(): void {
		global $post;

		$product_id      = $post->ID;
		$mode_meta       = get_post_meta( $product_id, '_wcbom_mode', true );
		$mode            = '' !== $mode_meta ? $mode_meta : 'standard';
		$is_component    = 'yes' === get_post_meta( $product_id, '_wcbom_is_component', true );
		$unit_meta       = get_post_meta( $product_id, '_wcbom_unit', true );
		$unit            = '' !== $unit_meta ? $unit_meta : 'ea';
		$weight_from_bom = 'yes' === get_post_meta( $product_id, '_wcbom_weight_from_bom', true );

		echo '<div id="wcbom_bom_data" class="panel woocommerce_options_panel">';
		echo '<div class="options_group">';

		woocommerce_wp_select(
			array(
				'id'      => '_wcbom_mode',
				'label'   => __( 'Stock mode', 'wcbom' ),
				'options' => array(
					'standard'      => __( 'Standard (WooCommerce native stock)', 'wcbom' ),
					'made_to_order' => __( 'Made-to-order (BOM-driven, components consumed on sale)', 'wcbom' ),
					'manufactured'  => __( 'Manufactured finished good (built via Manufacture Orders)', 'wcbom' ),
				),
				'value'   => $mode,
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => '_wcbom_is_component',
				'label'       => __( 'Component', 'wcbom' ),
				'description' => __( 'This product can be used as a raw material in another product\'s BOM.', 'wcbom' ),
				'value'       => $is_component ? 'yes' : 'no',
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => '_wcbom_unit',
				'label'   => __( 'Component unit', 'wcbom' ),
				'options' => array(
					'ea'    => __( 'Each', 'wcbom' ),
					'g'     => __( 'Grams', 'wcbom' ),
					'ml'    => __( 'Milliliters', 'wcbom' ),
					'cm'    => __( 'Centimeters', 'wcbom' ),
					'sheet' => __( 'Sheets', 'wcbom' ),
				),
				'value'   => $unit,
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => '_wcbom_weight_from_bom',
				'label'       => __( 'Weight from BOM', 'wcbom' ),
				'description' => __( 'Override this product\'s cart/shipping weight with the sum of its resolved BOM lines\' component weights (Σ weight × qty), instead of the Shipping tab\'s fixed weight field. Leave unchecked for premade/manufactured products that already have a correct fixed weight.', 'wcbom' ),
				'value'       => $weight_from_bom ? 'yes' : 'no',
			)
		);

		echo '</div>';

		if ( $is_component ) {
			$this->render_used_in( $product_id );
		}

		echo '<div class="options_group" id="wcbom-bom-editor-root" data-product-id="' . esc_attr( (string) $product_id ) . '">';
		echo '<p class="description">' . esc_html__( 'Loading BOM editor…', 'wcbom' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders the "used in N products" reverse view for a component: which
	 * currently-active BOMs consume it, so a merchant can see the blast
	 * radius of adjusting or discontinuing this product.
	 *
	 * @param int $component_id The component being edited.
	 */
	private function render_used_in( int $component_id ): void {
		$used_in = $this->boms->used_in( $component_id );

		echo '<div class="options_group">';
		echo '<p><strong>' . esc_html__( 'Used in', 'wcbom' ) . ':</strong> ';

		if ( array() === $used_in ) {
			echo esc_html__( 'Not used in any active BOM.', 'wcbom' );
		} else {
			$links = array_map(
				static fn( array $row ): string => sprintf(
					'<a href="%s">%s</a>',
					esc_url( (string) get_edit_post_link( $row['product_id'] ) ),
					esc_html( $row['name'] )
				),
				$used_in
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each $links entry is already escaped above.
			echo implode( ', ', $links );
		}

		echo '</p>';
		echo '</div>';
	}

	/**
	 * Saves the mode/component/unit/weight-toggle fields on product save.
	 * BOM lines themselves are saved separately via the REST API, not this
	 * form post.
	 *
	 * @param int $product_id The product being saved.
	 */
	public function save_fields( int $product_id ): void {
		$mode = isset( $_POST['_wcbom_mode'] ) ? sanitize_key( wp_unslash( $_POST['_wcbom_mode'] ) ) : 'standard'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-save nonce before firing this action.
		if ( ! in_array( $mode, array( 'standard', 'made_to_order', 'manufactured' ), true ) ) {
			$mode = 'standard';
		}
		update_post_meta( $product_id, '_wcbom_mode', $mode );

		$is_component = isset( $_POST['_wcbom_is_component'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $product_id, '_wcbom_is_component', $is_component );

		$unit = isset( $_POST['_wcbom_unit'] ) ? sanitize_key( wp_unslash( $_POST['_wcbom_unit'] ) ) : 'ea'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $product_id, '_wcbom_unit', $unit );

		$weight_from_bom = isset( $_POST['_wcbom_weight_from_bom'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $product_id, '_wcbom_weight_from_bom', $weight_from_bom );
	}

	/**
	 * Enqueues the React BOM editor on the product edit screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		$asset_file = WCBOM_PLUGIN_DIR . '/assets/build/bom-editor/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'wcbom-bom-editor',
			plugins_url( 'assets/build/bom-editor/index.js', WCBOM_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( in_array( 'wp-components', $asset['dependencies'], true ) ) {
			wp_enqueue_style( 'wp-components' );
		}

		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
				wp_json_encode( wp_create_nonce( 'wp_rest' ) )
			),
			'after'
		);

		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;

		wp_localize_script(
			'wcbom-bom-editor',
			'wcbomBomEditor',
			array(
				'productId'           => $post ? $post->ID : 0,
				'restNamespace'       => 'wcbom/v1',
				'variationAttributes' => $product ? $this->variation_attributes( $product ) : array(),
				'weightFromBom'       => 'yes' === get_post_meta( $post ? $post->ID : 0, '_wcbom_weight_from_bom', true ),
			)
		);
	}

	/**
	 * The product's variation-level attributes, for building "attribute"
	 * condition pickers in the BOM editor.
	 *
	 * @param WC_Product $product The product being edited.
	 * @return array<int,array{taxonomy:string,label:string,terms:array<int,array{slug:string,name:string}>}>
	 */
	private function variation_attributes( WC_Product $product ): array {
		$result = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, \WC_Product_Attribute::class ) || ! $attribute->get_variation() || ! $attribute->is_taxonomy() ) {
				continue;
			}

			$taxonomy = $attribute->get_name();
			$terms    = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'all' ) );

			$result[] = array(
				'taxonomy' => $taxonomy,
				'label'    => wc_attribute_label( $taxonomy ),
				'terms'    => array_map(
					static fn( $term ): array => array(
						'slug' => $term->slug,
						'name' => $term->name,
					),
					$terms
				),
			);
		}

		return $result;
	}
}
