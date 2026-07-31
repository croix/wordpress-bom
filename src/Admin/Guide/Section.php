<?php
/**
 * One documented topic in the in-app Guide.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide;

defined( 'ABSPATH' ) || exit;

/**
 * A single Guide section: title, an ordered sequence of content blocks
 * (text and screenshots interleaved, so a screenshot renders right after
 * the paragraph that describes it rather than as a wall of images at the
 * end), deep links, an optional video, and — the mechanism
 * GuideCoverageTest relies on — which real admin pages/REST routes/CLI
 * commands this section documents. A page/route/command absent from
 * every section's covers_* list is undocumented, and the coverage test
 * fails on exactly that.
 */
final class Section {

	/**
	 * Constructs the section.
	 *
	 * @param string                                                                                    $id              Stable slug, used as the in-page anchor.
	 * @param string                                                                                    $title           Section heading.
	 * @param array<int,array{type:'text',html:string}|array{type:'screenshot',file:string,alt:string}> $blocks   Ordered content, built via self::text()/self::screenshot().
	 * @param array<int,array{label:string,url:string}>                                                 $links           Deep links into the live screens this section describes.
	 * @param array{title:string,url:string}|null                                                       $video           Optional third-party or self-recorded video pointer — click-to-load, never auto-embedded.
	 * @param array<int,string>                                                                         $covers_pages    Admin menu slugs this section documents.
	 * @param array<int,string>                                                                         $covers_routes   wcbom/v1 REST route patterns this section documents.
	 * @param array<int,string>                                                                         $covers_commands `wp wcbom` subcommand names this section documents.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly array $blocks,
		public readonly array $links = array(),
		public readonly ?array $video = null,
		public readonly array $covers_pages = array(),
		public readonly array $covers_routes = array(),
		public readonly array $covers_commands = array()
	) {}

	/**
	 * A paragraph (or other HTML, already run through __()) block.
	 *
	 * @param string $html HTML content.
	 * @return array{type:'text',html:string}
	 */
	public static function text( string $html ): array {
		return array(
			'type' => 'text',
			'html' => $html,
		);
	}

	/**
	 * A screenshot block, placed at the point in the section where it's
	 * relevant rather than grouped at the end.
	 *
	 * @param string $file Filename under assets/docs/.
	 * @param string $alt  Alt text (already run through __()).
	 * @return array{type:'screenshot',file:string,alt:string}
	 */
	public static function screenshot( string $file, string $alt ): array {
		return array(
			'type' => 'screenshot',
			'file' => $file,
			'alt'  => $alt,
		);
	}
}
