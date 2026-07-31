<?php
/**
 * WP-native contextual help tabs, sourced from Guide content.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a small "BOM & Stock Guide" help tab (top-right of the screen,
 * under the "Help" dropdown) to a specific admin page, deep-linking into
 * the matching full Guide section. Cheap and native — no screenshots
 * needed here, since the full Guide page already has them.
 */
final class ContextualHelp {

	/**
	 * Hooks a help tab onto one admin screen.
	 *
	 * @param string|false|null $hook_suffix The screen's hook suffix, as returned by add_submenu_page().
	 * @param string            $section_id  Which Guide section this screen corresponds to.
	 */
	public static function attach( $hook_suffix, string $section_id ): void {
		if ( ! $hook_suffix ) {
			return;
		}

		add_action(
			"load-{$hook_suffix}",
			static function () use ( $section_id ): void {
				$screen  = get_current_screen();
				$section = self::find( $section_id );

				if ( ! $screen || ! $section ) {
					return;
				}

				$screen->add_help_tab(
					array(
						'id'      => "wcbom-guide-{$section->id}",
						'title'   => __( 'BOM & Stock Guide', 'pv-bom-stock' ),
						'content' => '<p>' . esc_html( wp_strip_all_tags( $section->title ) ) . '</p><p><a href="'
							. esc_url( admin_url( 'admin.php?page=wcbom-guide#' . $section->id ) )
							. '">' . esc_html__( 'Open the full Guide section', 'pv-bom-stock' ) . ' &rarr;</a></p>',
					)
				);
			}
		);
	}

	/**
	 * Finds a section by id.
	 *
	 * @param string $id Section id.
	 */
	private static function find( string $id ): ?Section {
		foreach ( GuideContent::sections() as $section ) {
			if ( $section->id === $id ) {
				return $section;
			}
		}

		return null;
	}
}
