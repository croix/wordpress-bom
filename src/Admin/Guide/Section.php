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
 * A single Guide section: title, body copy, screenshots, deep links, an
 * optional video, and — the mechanism GuideCoverageTest relies on — which
 * real admin pages/REST routes/CLI commands this section documents. A
 * page/route/command absent from every section's covers_* list is
 * undocumented, and the coverage test fails on exactly that.
 */
final class Section {

	/**
	 * Constructs the section.
	 *
	 * @param string                                    $id              Stable slug, used as the in-page anchor.
	 * @param string                                    $title           Section heading.
	 * @param string                                    $body            HTML body copy (already run through __()).
	 * @param array<int,array{file:string,alt:string}>  $screenshots     Images under assets/docs/, in display order.
	 * @param array<int,array{label:string,url:string}> $links           Deep links into the live screens this section describes.
	 * @param array{title:string,url:string}|null       $video           Optional third-party or self-recorded video pointer — click-to-load, never auto-embedded.
	 * @param array<int,string>                         $covers_pages    Admin menu slugs this section documents.
	 * @param array<int,string>                         $covers_routes   wcbom/v1 REST route patterns this section documents.
	 * @param array<int,string>                         $covers_commands `wp wcbom` subcommand names this section documents.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $body,
		public readonly array $screenshots = array(),
		public readonly array $links = array(),
		public readonly ?array $video = null,
		public readonly array $covers_pages = array(),
		public readonly array $covers_routes = array(),
		public readonly array $covers_commands = array()
	) {}
}
