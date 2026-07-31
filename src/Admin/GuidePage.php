<?php
/**
 * "BOM & Stock → Guide" admin page.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WCBOM\Admin\Guide\GuideContent;
use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the in-app documentation/training module (BUILD_PLAN.md
 * §5.12): every Guide section, in order, with its screenshots and deep
 * links. Plain PHP, not React — this content is static, matching the
 * existing split (React only for genuinely interactive surfaces).
 *
 * Loads no external resource of any kind — screenshots are local files
 * under assets/docs/, and the one third-party video pointer (Companion
 * plugins section) is a plain outbound link the user must click, never
 * an auto-loading embed.
 */
final class GuidePage {

	/**
	 * The hook suffix WordPress assigns this submenu, captured from
	 * add_submenu_page()'s return value (see Admin\ManufacturePage's
	 * docblock on the same property for why).
	 *
	 * @var string|false|null
	 */
	private $hook_suffix;

	/**
	 * Hooks the admin menu and enqueue callbacks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the page under the plugin's own top-level menu.
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_submenu_page(
			PluginMenu::SLUG,
			__( 'Guide', 'wcbom' ),
			__( 'Guide', 'wcbom' ),
			'manage_woocommerce',
			'wcbom-guide',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the Guide's stylesheet on its own admin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( $this->hook_suffix !== $hook ) {
			return;
		}

		$css_path = WCBOM_PLUGIN_DIR . '/assets/css/wcbom-guide.css';
		if ( ! file_exists( $css_path ) ) {
			return;
		}

		wp_enqueue_style(
			'wcbom-guide',
			plugins_url( 'assets/css/wcbom-guide.css', WCBOM_PLUGIN_FILE ),
			array(),
			(string) filemtime( $css_path )
		);
	}

	/**
	 * Renders the table of contents and every section.
	 */
	public function render_page(): void {
		$sections = GuideContent::sections();

		echo '<div class="wrap wcbom-guide">';
		echo '<h1>' . esc_html__( 'BOM & Stock Guide', 'wcbom' ) . '</h1>';
		echo '<p>' . esc_html__( 'Everything this plugin adds, explained in one place — enough to train a new user from a fresh install.', 'wcbom' ) . '</p>';

		echo '<nav class="wcbom-guide-toc"><ul>';
		foreach ( $sections as $section ) {
			echo '<li><a href="#' . esc_attr( $section->id ) . '">' . esc_html( $section->title ) . '</a></li>';
		}
		echo '</ul></nav>';

		foreach ( $sections as $section ) {
			$this->render_section( $section );
		}

		echo '</div>';
	}

	/**
	 * Renders one section.
	 *
	 * @param Section $section Section to render.
	 */
	private function render_section( Section $section ): void {
		echo '<section id="' . esc_attr( $section->id ) . '" class="wcbom-guide-section">';
		echo '<h2>' . esc_html( $section->title ) . '</h2>';

		foreach ( $section->blocks as $block ) {
			if ( 'screenshot' === $block['type'] ) {
				$this->render_screenshot( $block );
			} else {
				echo wp_kses_post( $block['html'] );
			}
		}

		if ( ! empty( $section->video ) ) {
			echo '<p class="wcbom-guide-video"><a class="button" target="_blank" rel="noopener noreferrer" href="'
				. esc_url( $section->video['url'] ) . '">'
				. esc_html( $section->video['title'] ) . ' &#9654;</a></p>';
		}

		$this->render_links( $section->links );

		echo '</section>';
	}

	/**
	 * Renders one screenshot block, skipped entirely (no broken <img>) if
	 * the file is missing — e.g. before `npm run docs:screenshots` has
	 * ever been run.
	 *
	 * @param array{type:'screenshot',file:string,alt:string} $block Screenshot block.
	 */
	private function render_screenshot( array $block ): void {
		$path = WCBOM_PLUGIN_DIR . '/assets/docs/' . $block['file'];
		if ( ! file_exists( $path ) ) {
			return;
		}

		echo '<img class="wcbom-guide-screenshot" loading="lazy" src="'
			. esc_url( plugins_url( 'assets/docs/' . $block['file'], WCBOM_PLUGIN_FILE ) )
			. '" alt="' . esc_attr( $block['alt'] ) . '" />';
	}

	/**
	 * Renders a section's deep links, skipping any gated on a companion
	 * plugin that is not currently active.
	 *
	 * @param array<int,array{label:string,url:string,requires_plugin?:string,external?:bool}> $links Links to render.
	 */
	private function render_links( array $links ): void {
		if ( empty( $links ) ) {
			return;
		}

		echo '<p class="wcbom-guide-links">';
		foreach ( $links as $link ) {
			if ( ! empty( $link['requires_plugin'] ) && ! is_plugin_active( $link['requires_plugin'] ) ) {
				continue;
			}

			$external = ! empty( $link['external'] );

			echo '<a class="button" href="' . esc_url( $link['url'] ) . '"'
				. ( $external ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>'
				. esc_html( $link['label'] ) . '</a> ';
		}
		echo '</p>';
	}
}
