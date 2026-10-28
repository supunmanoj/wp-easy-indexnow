<?php
/**
 * Delivers plugin updates from GitHub releases through the normal
 * WordPress update system (Dashboard → Updates, the Plugins screen and
 * WordPress auto-updates).
 *
 * To ship an update: bump "Version:" in easy-indexnow.php, commit, then
 * publish a GitHub release whose tag is the new version (e.g. v1.4.1).
 *
 * @package Easy_IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Easy_IndexNow_Updater {

	const REPO  = 'supunmanoj/wp-easy-indexnow';
	const CACHE = 'easy_indexnow_update_info';

	/** Only download update packages from these hosts. */
	const ALLOWED_HOSTS = array( 'github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com' );

	private $basename;
	private $slug;
	private $version;

	public function __construct( $plugin_file, $version ) {
		$this->basename = plugin_basename( $plugin_file );
		$this->slug     = dirname( $this->basename );
		$this->version  = $version;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_easy_indexnow_check_update', array( $this, 'handle_check_update' ) );
	}

	/**
	 * Latest published release, cached for 6 hours (1 hour after a failure)
	 * to stay well inside GitHub's API limits.
	 *
	 * @return array|null { version, package, url, changelog, published }
	 */
	public function get_release( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Easy-IndexNow/' . $this->version,
				),
			)
		);

		$data = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			set_site_transient( self::CACHE, 'none', HOUR_IN_SECONDS );
			return null;
		}

		// Prefer a .zip attached to the release; otherwise GitHub's source zip.
		$package = isset( $data['zipball_url'] ) ? $data['zipball_url'] : '';
		foreach ( isset( $data['assets'] ) ? (array) $data['assets'] : array() as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
		if ( ! in_array( wp_parse_url( $package, PHP_URL_HOST ), self::ALLOWED_HOSTS, true ) ) {
			$package = '';
		}

		$release = array(
			'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
			'package'   => esc_url_raw( $package ),
			'url'       => esc_url_raw( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . self::REPO . '/releases' ),
			'changelog' => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);
		set_site_transient( self::CACHE, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** Base update record shared by the update list and "no update" list. */
	private function update_item( $new_version, $package = '' ) {
		return (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $new_version,
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $package,
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/** Tells WordPress whether a newer version is available. */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$release = $this->get_release();

		if ( $release && $release['package'] && version_compare( $release['version'], $this->version, '>' ) ) {
			$transient->response[ $this->basename ] = $this->update_item( $release['version'], $release['package'] );
			unset( $transient->no_update[ $this->basename ] );
		} else {
			// Listing it as "up to date" lets WordPress show the auto-update toggle.
			$transient->no_update[ $this->basename ] = $this->update_item( $this->version );
			unset( $transient->response[ $this->basename ] );
		}
		return $transient;
	}

	/** Content for the "View details" popup. */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$release = $this->get_release();
		$version = $release ? $release['version'] : $this->version;

		$changelog = $release && '' !== trim( $release['changelog'] )
			? wpautop( esc_html( $release['changelog'] ) )
			: '<p>' . esc_html__( 'See the release notes on GitHub.', 'easy-indexnow' ) . '</p>';
		if ( $release ) {
			$changelog .= '<p><a href="' . esc_url( $release['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View this release on GitHub', 'easy-indexnow' ) . '</a></p>';
		}

		return (object) array(
			'name'          => 'Easy IndexNow',
			'slug'          => $this->slug,
			'version'       => $version,
			'author'        => '<a href="https://github.com/supunmanoj">Supun Manoj Kumara</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release ? $release['published'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Automatically notifies Bing, Yandex, Seznam, Naver and other IndexNow search engines when content is published, updated or removed.', 'easy-indexnow' ) . '</p>',
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * GitHub zips unpack to folders like "supunmanoj-wp-easy-indexnow-1a2b3c".
	 * Rename it to the installed folder so WordPress replaces the plugin
	 * in place and keeps it active.
	 */
	public function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		$desired = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( trailingslashit( $source ) === $desired ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
			return $desired;
		}
		return new WP_Error( 'easy_indexnow_update_folder', __( 'Easy IndexNow: could not prepare the update folder.', 'easy-indexnow' ) );
	}

	public function after_update( $upgrader, $options ) {
		if ( isset( $options['type'], $options['plugins'] ) && 'plugin' === $options['type'] && in_array( $this->basename, (array) $options['plugins'], true ) ) {
			delete_site_transient( self::CACHE );
		}
	}

	public static function check_update_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=easy_indexnow_check_update' ), 'easy_indexnow_check_update' );
	}

	public function row_meta( $links, $file ) {
		if ( $file === $this->basename && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( self::check_update_url() ) . '">' . esc_html__( 'Check for updates', 'easy-indexnow' ) . '</a>';
		}
		return $links;
	}

	/** Skips the cache and asks GitHub right now. */
	public function handle_check_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'easy-indexnow' ) );
		}
		check_admin_referer( 'easy_indexnow_check_update' );

		$this->get_release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( admin_url( 'plugins.php?plugin_status=all' ) );
		exit;
	}
}
