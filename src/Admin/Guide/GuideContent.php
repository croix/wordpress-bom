<?php
/**
 * Aggregates all Guide sections in display order.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide;

defined( 'ABSPATH' ) || exit;

/**
 * One class per section (per BUILD_PLAN.md §5.12), listed here in the
 * exact order the Guide displays them. Adding a section is: write the
 * class, add it to this list. GuideCoverageTest checks the union of every
 * section's covers_* arrays against what is actually registered — not
 * this list's mere existence — so this file itself has nothing to keep
 * in sync by hand beyond "is the new section here at all."
 */
final class GuideContent {

	private const SECTION_CLASSES = array(
		Sections\Orientation::class,
		Sections\FirstRunSetup::class,
		Sections\BuildingABom::class,
		Sections\SellingMadeToOrder::class,
		Sections\ComponentInventory::class,
		Sections\Manufacturing::class,
		Sections\VendorsAndPurchaseOrders::class,
		Sections\Reports::class,
		Sections\CsvImportExport::class,
		Sections\SettingsSection::class,
		Sections\ForDevelopers::class,
		Sections\CompanionPlugins::class,
		Sections\Troubleshooting::class,
		Sections\WhatThisPluginDoesnt::class,
	);

	/**
	 * Every Guide section, in display order.
	 *
	 * @return array<int,Section>
	 */
	public static function sections(): array {
		return array_map( static fn( string $section_class ): Section => $section_class::get(), self::SECTION_CLASSES );
	}
}
