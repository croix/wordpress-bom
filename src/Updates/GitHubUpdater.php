<?php
/**
 * GitHub-Releases-powered plugin updates.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds WordPress's native update system from GitHub Releases via the
 * WP 5.8+ `Update URI` mechanism (BUILD_PLAN.md §14.1): core fires
 * update_plugins_{hostname} for plugins whose Update URI points at that
 * host, and whatever we return becomes a completely normal update row —
 * one-click install, auto-updates, the lot.
 *
 * Failure modes (API down, rate limit, private repo without a token, no
 * releases yet) all cache "no update" and stay silent — an update check
 * must never break a site. While the repo is private, define
 * WCBOM_GITHUB_TOKEN in wp-config.php (fine-grained read-only token) to
 * authenticate checks; see §14.2 for the distribution options.
 */
final class GitHubUpdater {

	private const REPO          = 'croix/wordpress-bom';
	private const EXPECTED_SLUG = 'pv-bom-stock';
	private const CACHE_KEY     = 'wcbom_update_check';
	private const CACHE_TTL     = 6 * HOUR_IN_SECONDS;

	/**
	 * Hooks the update filter and the cache-busting action.
	 */
	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check_for_update' ), 10, 3 );

		// Dashboard → Updates → "Check Again" deletes core's update_plugins
		// site transient; piggyback on that to drop our own cache so a
		// manual re-check is genuinely fresh.
		add_action( 'delete_site_transient_update_plugins', array( $this, 'flush_cache' ) );
	}

	/**
	 * Supplies update info for our plugin when core asks github.com-hosted
	 * plugins for updates.
	 *
	 * @param array<string,mixed>|false $update      Existing update data (usually false).
	 * @param array<string,mixed>       $plugin_data Parsed plugin headers.
	 * @param string                    $plugin_file Plugin basename being checked.
	 * @return array<string,mixed>|false
	 */
	public function check_for_update( $update, array $plugin_data, string $plugin_file ) {
		if ( plugin_basename( WCBOM_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		// The release zip is rooted pv-bom-stock/, and WordPress replaces
		// the plugin folder using the zip's root name — offering an update
		// to a differently-named install (like the wp-env dev mount) would
		// strand the old folder. See BUILD_PLAN.md §14.1.
		if ( self::EXPECTED_SLUG !== dirname( $plugin_file ) ) {
			return $update;
		}

		$release = $this->get_latest_release();
		if ( null === $release ) {
			return $update;
		}

		$current = (string) ( $plugin_data['Version'] ?? WCBOM_VERSION );
		if ( version_compare( $release['version'], $current, '<=' ) ) {
			return $update;
		}

		return array(
			'id'      => 'https://github.com/' . self::REPO,
			'slug'    => self::EXPECTED_SLUG,
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * Clears the cached release lookup.
	 */
	public function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * The latest release's version/package/url, from cache or the GitHub
	 * API. Null when there's no usable release (or the check failed).
	 *
	 * @return array{version:string,package:string,url:string}|null
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return array() !== $cached ? $cached : null;
		}

		$release = $this->fetch_latest_release();

		// Cache failures as an empty array so a broken/unreachable API is
		// re-asked at most once per TTL, not on every admin page load.
		set_transient( self::CACHE_KEY, $release ?? array(), self::CACHE_TTL );

		return $release;
	}

	/**
	 * Asks the GitHub API for the latest release and extracts what the
	 * update row needs.
	 *
	 * @return array{version:string,package:string,url:string}|null
	 */
	private function fetch_latest_release(): ?array {
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'pv-bom-stock-updater',
		);

		if ( defined( 'WCBOM_GITHUB_TOKEN' ) && '' !== WCBOM_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . WCBOM_GITHUB_TOKEN;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'headers' => $headers,
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( (string) $body['tag_name'], 'vV' );

		$package = null;
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset )
				&& isset( $asset['name'], $asset['browser_download_url'] )
				&& str_starts_with( (string) $asset['name'], self::EXPECTED_SLUG )
				&& str_ends_with( (string) $asset['name'], '.zip' )
			) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}

		if ( null === $package ) {
			return null; // A release without our zip asset isn't installable.
		}

		return array(
			'version' => $version,
			'package' => $package,
			'url'     => (string) ( $body['html_url'] ?? 'https://github.com/' . self::REPO . '/releases' ),
		);
	}
}
