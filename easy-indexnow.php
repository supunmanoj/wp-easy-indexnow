<?php
/**
 * Plugin Name:       Easy IndexNow
 * Plugin URI:        https://github.com/supunmanoj/wp-easy-indexnow
 * Description:       Automatically notifies search engines (Bing, Yandex, Seznam, Naver and others) through the IndexNow protocol when content is published, updated or removed.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Supun Manoj Kumara
 * Author URI:        https://github.com/supunmanoj
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easy-indexnow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Easy_IndexNow {

	const OPTION    = 'easy_indexnow_settings';
	const LOG       = 'easy_indexnow_log';
	const QUEUE     = 'easy_indexnow_queue';
	const CRON_HOOK = 'easy_indexnow_send_queue';
	const LOG_LIMIT = 50;
	const MAX_URLS  = 10000; // IndexNow limit per request.
	const RETRY     = 'easy_indexnow_retry';
	const RETRY_HOOK = 'easy_indexnow_send_retry';

	/** Minutes to wait before each automatic retry of a failed submission. */
	const RETRY_DELAYS = array( 10, 30, 120 );

	/** @var Easy_IndexNow|null */
	private static $instance = null;

	/** Permalinks captured before a post is updated/trashed. */
	private $old_permalinks = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Serve the key file at /{key}.txt.
		add_action( 'init', array( $this, 'serve_key_file' ), 0 );

		// Detect content changes.
		add_action( 'pre_post_update', array( $this, 'capture_old_permalink' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 1 );

		// Background sending.
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );
		add_action( self::RETRY_HOOK, array( $this, 'process_retry' ) );

		// Admin.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maintenance' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_easy_indexnow_manual', array( $this, 'handle_manual' ) );
		add_action( 'admin_post_easy_indexnow_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_easy_indexnow_regen', array( $this, 'handle_regen' ) );
		add_action( 'admin_post_easy_indexnow_check', array( $this, 'handle_check' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings & key
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'key'            => '',
			'key_created'    => 0, // When the key was generated (new keys need verifying).
			'endpoint'       => 'https://api.indexnow.org/indexnow',
			'post_types'     => array( 'post', 'page' ),
			'auto'           => 1,
			'auto_new_types' => 0,       // Automatically enable newly found content types.
			'known_types'    => array(), // Content types the admin has already seen.
		);
	}

	/**
	 * Every content type that has public URLs, including ones registered by
	 * other plugins/themes (WooCommerce products, portfolios, courses, etc.).
	 *
	 * @return WP_Post_Type[] keyed by name.
	 */
	public static function viewable_post_types() {
		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $type ) {
			if ( 'attachment' !== $name && is_post_type_viewable( $type ) ) {
				$types[ $name ] = $type;
			}
		}
		return $types;
	}

	/** Viewable content types not yet seen by the admin. */
	public static function new_post_types() {
		$settings = self::get_settings();
		// Posts and pages are the defaults, so they are never "new".
		$known    = array_merge( array( 'post', 'page' ), (array) $settings['known_types'] );
		return array_diff_key( self::viewable_post_types(), array_flip( $known ) );
	}

	public static function endpoints() {
		return array(
			'https://api.indexnow.org/indexnow'         => __( 'IndexNow.org (shared with all participating engines, recommended)', 'easy-indexnow' ),
			'https://www.bing.com/indexnow'             => __( 'Microsoft Bing', 'easy-indexnow' ),
			'https://yandex.com/indexnow'               => __( 'Yandex', 'easy-indexnow' ),
			'https://search.seznam.cz/indexnow'         => __( 'Seznam.cz', 'easy-indexnow' ),
			'https://searchadvisor.naver.com/indexnow'  => __( 'Naver', 'easy-indexnow' ),
		);
	}

	public static function get_settings() {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( ! self::is_valid_key( $settings['key'] ) ) {
			$settings['key']         = self::generate_key();
			$settings['key_created'] = time();
			update_option( self::OPTION, $settings );
		}
		return $settings;
	}

	public static function generate_key() {
		// 32 hex characters (spec allows 8–128 of a-z, A-Z, 0-9 and dashes).
		return str_replace( '-', '', wp_generate_uuid4() );
	}

	public static function is_valid_key( $key ) {
		return is_string( $key ) && (bool) preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $key );
	}

	public static function key_location( $key ) {
		return home_url( '/' . $key . '.txt' );
	}

	public static function activate() {
		$settings = self::get_settings(); // Creates the key on first activation.
		self::write_key_file( $settings['key'] );
	}

	/* ------------------------------------------------------------------
	 * Physical key file. Many hosts, CDNs and security plugins handle a real
	 * static .txt file more reliably than one generated by WordPress, so we
	 * write one to the site root when possible. The virtual file below stays
	 * as a fallback.
	 * ------------------------------------------------------------------ */

	/** Path of the physical key file, or '' when WordPress isn't in the web root. */
	public static function key_file_path( $key ) {
		if ( untrailingslashit( home_url() ) !== untrailingslashit( site_url() ) ) {
			return ''; // WordPress lives in a subfolder; rely on the virtual file + keyLocation.
		}
		return trailingslashit( ABSPATH ) . $key . '.txt';
	}

	public static function write_key_file( $key ) {
		$path = self::key_file_path( $key );
		if ( ! $path ) {
			return false;
		}
		if ( file_exists( $path ) ) {
			return trim( (string) file_get_contents( $path ) ) === $key; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( ! wp_is_writable( dirname( $path ) ) ) {
			return false;
		}
		return false !== file_put_contents( $path, $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function delete_key_file( $key ) {
		$path = self::key_file_path( $key );
		// Only remove a file we created (it must contain exactly the key).
		if ( $path && file_exists( $path ) && trim( (string) file_get_contents( $path ) ) === $key ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_delete_file( $path );
		}
	}

	/**
	 * Runs on admin page loads: keeps the physical key file in place and
	 * picks up content types registered since the admin last looked.
	 */
	public function maintenance() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get_settings();

		$path = self::key_file_path( $settings['key'] );
		if ( $path && ! file_exists( $path ) ) {
			self::write_key_file( $settings['key'] );
		}

		if ( ! empty( $settings['auto_new_types'] ) ) {
			$new = self::new_post_types();
			if ( $new ) {
				$settings['post_types']  = array_values( array_unique( array_merge( (array) $settings['post_types'], array_keys( $new ) ) ) );
				$settings['known_types'] = array_keys( self::viewable_post_types() );
				update_option( self::OPTION, $settings );
				set_transient(
					'easy_indexnow_types_added',
					wp_list_pluck( $new, 'label' ),
					DAY_IN_SECONDS
				);
			}
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::RETRY_HOOK );
	}

	/** True while a freshly generated key may still be awaiting verification. */
	public static function key_is_new() {
		$settings = self::get_settings();
		return ! empty( $settings['key_created'] ) && ( time() - (int) $settings['key_created'] ) < HOUR_IN_SECONDS;
	}

	/* ------------------------------------------------------------------
	 * Key file: answers https://example.com/{key}.txt with the key.
	 * No physical file is written, so it works on any host.
	 * ------------------------------------------------------------------ */

	public function serve_key_file() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! $request_path || '.txt' !== substr( $request_path, -4 ) ) {
			return; // Fast exit for normal page loads.
		}

		$settings  = self::get_settings();
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = $home_path ? trailingslashit( $home_path ) : '/';

		if ( $request_path === $home_path . $settings['key'] . '.txt' ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			echo esc_html( $settings['key'] );
			exit;
		}
	}

	/* ------------------------------------------------------------------
	 * Detecting changes
	 * ------------------------------------------------------------------ */

	private function is_tracked( $post ) {
		$settings = self::get_settings();
		if ( empty( $settings['auto'] ) || ! $post instanceof WP_Post ) {
			return false;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		return in_array( $post->post_type, (array) $settings['post_types'], true )
			&& is_post_type_viewable( $post->post_type );
	}

	/** Remember the live URL before an update changes or trashes it. */
	public function capture_old_permalink( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && $this->is_tracked( $post ) ) {
			$this->old_permalinks[ $post_id ] = get_permalink( $post );
		}
	}

	public function on_status_change( $new_status, $old_status, $post ) {
		if ( ! $this->is_tracked( $post ) ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			// New or updated content.
			$this->queue_url( get_permalink( $post ) );

			// Slug changed? Tell engines the old URL changed too.
			if ( isset( $this->old_permalinks[ $post->ID ] ) && $this->old_permalinks[ $post->ID ] !== get_permalink( $post ) ) {
				$this->queue_url( $this->old_permalinks[ $post->ID ] );
			}
		} elseif ( 'publish' === $old_status ) {
			// Unpublished or trashed: the old URL is now gone.
			if ( isset( $this->old_permalinks[ $post->ID ] ) ) {
				$this->queue_url( $this->old_permalinks[ $post->ID ] );
			}
		}
	}

	/** A published post deleted permanently without going through trash. */
	public function on_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && $this->is_tracked( $post ) ) {
			$this->queue_url( get_permalink( $post ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Queue: collects URLs and sends them in one batch a minute later.
	 * This avoids slowing down saving and stops duplicate pings
	 * (the block editor often saves a post twice).
	 * ------------------------------------------------------------------ */

	private function queue_url( $url ) {
		if ( empty( $url ) || self::is_local_site() ) {
			return;
		}
		$queue   = (array) get_option( self::QUEUE, array() );
		$queue[] = esc_url_raw( $url );
		update_option( self::QUEUE, array_values( array_unique( $queue ) ), false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}

	public function process_queue() {
		$queue = (array) get_option( self::QUEUE, array() );
		delete_option( self::QUEUE );
		if ( empty( $queue ) ) {
			return;
		}
		foreach ( array_chunk( $queue, self::MAX_URLS ) as $chunk ) {
			$this->submit( $chunk, 'auto' );
		}
	}

	public static function is_local_site() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(local|test|localhost|invalid|example)$/', $host );
	}

	/* ------------------------------------------------------------------
	 * Sending to IndexNow
	 * ------------------------------------------------------------------ */

	/**
	 * @param string[] $urls
	 * @param string   $source  auto|manual|bulk|retry
	 * @param int      $attempt How many times these URLs were already retried.
	 * @return array { code: int, message: string }
	 */
	public function submit( array $urls, $source = 'manual', $attempt = 0 ) {
		$settings = self::get_settings();
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );

		// Only URLs on this site's host are accepted by IndexNow.
		$urls = array_values(
			array_unique(
				array_filter(
					array_map( 'esc_url_raw', $urls ),
					function ( $url ) use ( $host ) {
						return $url && wp_parse_url( $url, PHP_URL_HOST ) === $host;
					}
				)
			)
		);

		if ( empty( $urls ) ) {
			$result = array( 'code' => 0, 'message' => __( 'No valid URLs for this site were found.', 'easy-indexnow' ) );
			$this->log( array(), $result, $source );
			return $result;
		}

		$response = wp_remote_post(
			$settings['endpoint'],
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $settings['key'],
						'keyLocation' => self::key_location( $settings['key'] ),
						'urlList'     => array_slice( $urls, 0, self::MAX_URLS ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$code   = 0;
			$result = array( 'code' => 0, 'message' => $response->get_error_message() );
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$result = array( 'code' => $code, 'message' => self::describe_code( $code ) );

			// Keep the search engine's own explanation for failed requests.
			$detail = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) );
			if ( $code >= 400 && '' !== $detail ) {
				$result['detail'] = substr( $detail, 0, 300 );
			}
		}

		// Temporary problems (key still being verified, rate limit, server or
		// network errors) are retried automatically with growing delays.
		$retryable = in_array( $code, array( 0, 403, 429, 500, 502, 503, 504 ), true );
		if ( $retryable && $attempt < count( self::RETRY_DELAYS ) ) {
			$minutes = self::RETRY_DELAYS[ $attempt ];
			$this->schedule_retry( $urls, $attempt + 1, $minutes );
			/* translators: %d: minutes */
			$result['message'] .= ' ' . sprintf( _n( 'Will retry automatically in %d minute.', 'Will retry automatically in %d minutes.', $minutes, 'easy-indexnow' ), $minutes );
		} elseif ( $retryable ) {
			$result['message'] .= ' ' . __( 'Automatic retries are used up; please check the key file and submit again.', 'easy-indexnow' );
		}

		$this->log( $urls, $result, $source );
		return $result;
	}

	private function schedule_retry( array $urls, $attempt, $minutes ) {
		$pending = (array) get_option( self::RETRY, array() );
		$merged  = array_values( array_unique( array_merge( isset( $pending['urls'] ) ? (array) $pending['urls'] : array(), $urls ) ) );
		update_option(
			self::RETRY,
			array(
				'urls'    => array_slice( $merged, 0, self::MAX_URLS ),
				'attempt' => $attempt,
			),
			false
		);
		wp_clear_scheduled_hook( self::RETRY_HOOK );
		wp_schedule_single_event( time() + $minutes * MINUTE_IN_SECONDS, self::RETRY_HOOK );
	}

	public function process_retry() {
		$pending = (array) get_option( self::RETRY, array() );
		delete_option( self::RETRY );
		if ( ! empty( $pending['urls'] ) ) {
			$this->submit( (array) $pending['urls'], 'retry', isset( $pending['attempt'] ) ? (int) $pending['attempt'] : 1 );
		}
	}

	public static function describe_code( $code ) {
		if ( 403 === $code && self::key_is_new() ) {
			return __( 'Forbidden: this key is new and search engines have not verified it yet. This is normal for up to an hour after generating a key.', 'easy-indexnow' );
		}
		$messages = array(
			200 => __( 'OK: URLs submitted successfully.', 'easy-indexnow' ),
			202 => __( 'Accepted: URLs received, key validation pending.', 'easy-indexnow' ),
			400 => __( 'Bad request: invalid format.', 'easy-indexnow' ),
			403 => __( 'Forbidden: key not valid (key file not found or does not contain the key). Click "Check key file" below. If you use Cloudflare or a security plugin, it may be blocking IndexNow from reading the key file.', 'easy-indexnow' ),
			422 => __( 'Unprocessable: URLs do not belong to this host, or the key does not match.', 'easy-indexnow' ),
			429 => __( 'Too many requests: you are submitting too often.', 'easy-indexnow' ),
		);
		/* translators: %d: HTTP status code */
		return isset( $messages[ $code ] ) ? $messages[ $code ] : sprintf( __( 'Unexpected response (HTTP %d).', 'easy-indexnow' ), $code );
	}

	private function log( array $urls, array $result, $source ) {
		$log = (array) get_option( self::LOG, array() );
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'source'  => $source,
				'code'    => $result['code'],
				'message' => $result['message'],
				'detail'  => isset( $result['detail'] ) ? $result['detail'] : '',
				'count'   => count( $urls ),
				'sample'  => array_slice( $urls, 0, 5 ),
			)
		);
		update_option( self::LOG, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/* ------------------------------------------------------------------
	 * Admin page: Settings → IndexNow
	 * ------------------------------------------------------------------ */

	public function settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=easy-indexnow' ) ) . '">' . esc_html__( 'Settings', 'easy-indexnow' ) . '</a>' );
		return $links;
	}

	public function admin_menu() {
		add_options_page( __( 'Easy IndexNow', 'easy-indexnow' ), __( 'IndexNow', 'easy-indexnow' ), 'manage_options', 'easy-indexnow', array( $this, 'render_page' ) );
	}

	public function register_settings() {
		register_setting( 'easy_indexnow', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$current = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$input   = (array) $input;

		$endpoint = isset( $input['endpoint'] ) ? $input['endpoint'] : '';
		$types    = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();

		// Internal saves pass known_types; a form save means the admin has now
		// seen every content type listed on the page.
		$known = isset( $input['known_types'] )
			? array_map( 'sanitize_key', (array) $input['known_types'] )
			: array_keys( self::viewable_post_types() );

		return array(
			// The settings form never sends a key, so it keeps the current one;
			// internal saves (activation, "Generate new key") pass a fresh valid key.
			'key'            => ( isset( $input['key'] ) && self::is_valid_key( $input['key'] ) ) ? $input['key'] : $current['key'],
			'key_created'    => isset( $input['key_created'] ) ? absint( $input['key_created'] ) : absint( $current['key_created'] ),
			'endpoint'       => array_key_exists( $endpoint, self::endpoints() ) ? $endpoint : self::defaults()['endpoint'],
			'post_types'     => array_values( array_filter( $types, 'is_post_type_viewable' ) ),
			'auto'           => empty( $input['auto'] ) ? 0 : 1,
			'auto_new_types' => empty( $input['auto_new_types'] ) ? 0 : 1,
			'known_types'    => array_values( array_unique( $known ) ),
		);
	}

	private function redirect_back( $notice = '' ) {
		if ( $notice ) {
			set_transient( 'easy_indexnow_notice_' . get_current_user_id(), $notice, 60 );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=easy-indexnow' ) );
		exit;
	}

	private function check_request( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'easy-indexnow' ) );
		}
		check_admin_referer( $action );
	}

	public function handle_manual() {
		$this->check_request( 'easy_indexnow_manual' );
		$raw    = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
		$urls   = array_filter( array_map( 'trim', preg_split( '/\R/', $raw ) ) );
		$result = $this->submit( $urls, 'manual' );
		$this->redirect_back( $result['message'] );
	}

	public function handle_bulk() {
		$this->check_request( 'easy_indexnow_bulk' );
		$settings = self::get_settings();
		$ids      = get_posts(
			array(
				'post_type'      => $settings['post_types'],
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_URLS - 1,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'has_password'   => false,
			)
		);
		$urls     = array_merge( array( home_url( '/' ) ), array_map( 'get_permalink', $ids ) );
		$result   = $this->submit( $urls, 'bulk' );
		/* translators: 1: number of URLs, 2: result message */
		$this->redirect_back( sprintf( __( '%1$d URLs sent. %2$s', 'easy-indexnow' ), count( $urls ), $result['message'] ) );
	}

	public function handle_regen() {
		$this->check_request( 'easy_indexnow_regen' );
		$settings = self::get_settings();
		self::delete_key_file( $settings['key'] );
		$settings['key']         = self::generate_key();
		$settings['key_created'] = time();
		update_option( self::OPTION, $settings );
		self::write_key_file( $settings['key'] );
		$this->redirect_back( __( 'A new key was generated. Search engines need to verify it, so submissions may return 403 for a while; the plugin retries automatically.', 'easy-indexnow' ) );
	}

	/**
	 * Fetches the key file over the internet, the way a search engine would,
	 * and explains what went wrong if it isn't readable.
	 */
	public function handle_check() {
		$this->check_request( 'easy_indexnow_check' );
		$settings = self::get_settings();
		self::write_key_file( $settings['key'] );

		$response = wp_remote_get(
			add_query_arg( 'nocache', time(), self::key_location( $settings['key'] ) ),
			array(
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (compatible; IndexNow key check; +https://www.indexnow.org)',
				'headers'    => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			$this->redirect_back( sprintf( __( 'Key file check failed: %s', 'easy-indexnow' ), $response->get_error_message() ) );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$body   = trim( (string) wp_remote_retrieve_body( $response ) );
		$server = strtolower( (string) wp_remote_retrieve_header( $response, 'server' ) );
		$cf_challenge = '' !== (string) wp_remote_retrieve_header( $response, 'cf-mitigated' );

		if ( $cf_challenge || ( 403 === $code && false !== strpos( $server, 'cloudflare' ) ) ) {
			$message = __( 'Cloudflare is blocking bots from reading your key file. In Cloudflare, turn off Bot Fight Mode or add a WAF custom rule that skips security for URI paths ending in .txt, then click "Submit all published content" again.', 'easy-indexnow' );
		} elseif ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			$message = sprintf( __( 'The key file returned HTTP %d instead of 200. Check security/firewall plugins and your caching plugin, then clear all caches.', 'easy-indexnow' ), $code );
		} elseif ( $body !== $settings['key'] ) {
			$message = __( 'The key file loads but does not contain only the key (a cache, security page or HTML is being served instead). Clear your caches and exclude .txt files from caching.', 'easy-indexnow' );
		} else {
			$message = __( 'Key file OK: it is publicly reachable and contains the key. If IndexNow still returns 403, a firewall may be blocking only the IndexNow servers. Wait 10 minutes and try "Submit all published content" again.', 'easy-indexnow' );
		}
		$this->redirect_back( $message );
	}

	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Shown on every admin screen until the admin reviews the settings page.
		$added = get_transient( 'easy_indexnow_types_added' );
		if ( $added ) {
			delete_transient( 'easy_indexnow_types_added' );
			/* translators: %s: comma-separated content type names */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Easy IndexNow found and enabled new content types: %s', 'easy-indexnow' ), implode( ', ', $added ) ) ) . '</p></div>';
		}
		if ( 'settings_page_easy-indexnow' !== $screen->id ) {
			$new = self::new_post_types();
			if ( $new ) {
				printf(
					'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
					/* translators: %s: comma-separated content type names */
					esc_html( sprintf( __( 'Easy IndexNow found new content types: %s.', 'easy-indexnow' ), implode( ', ', wp_list_pluck( $new, 'label' ) ) ) ),
					esc_url( admin_url( 'options-general.php?page=easy-indexnow' ) ),
					esc_html__( 'Choose which to submit', 'easy-indexnow' )
				);
			}
			return;
		}
		$transient = 'easy_indexnow_notice_' . get_current_user_id();
		$notice    = get_transient( $transient );
		if ( $notice ) {
			delete_transient( $transient );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( self::is_local_site() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This looks like a local site, so automatic submissions are paused. Search engines cannot reach local sites.', 'easy-indexnow' ) . '</p></div>';
		}
		if ( wp_next_scheduled( self::RETRY_HOOK ) ) {
			$pending = (array) get_option( self::RETRY, array() );
			/* translators: 1: number of URLs, 2: human time difference */
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( '%1$d URLs are waiting to be retried automatically in %2$s.', 'easy-indexnow' ), isset( $pending['urls'] ) ? count( (array) $pending['urls'] ) : 0, human_time_diff( time(), wp_next_scheduled( self::RETRY_HOOK ) ) ) ) . '</p></div>';
		}
	}

	private function action_form( $action, $label, $class = 'button', $confirm = '' ) {
		$onsubmit = $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px"' . $onsubmit . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		submit_button( $label, $class, 'submit', false );
		echo '</form>';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get_settings();
		$key_url  = self::key_location( $settings['key'] );
		$log      = (array) get_option( self::LOG, array() );
		$types    = self::viewable_post_types();
		$new      = self::new_post_types();
		$key_path = self::key_file_path( $settings['key'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Easy IndexNow', 'easy-indexnow' ); ?></h1>

			<h2><?php esc_html_e( 'Your IndexNow key', 'easy-indexnow' ); ?></h2>
			<p><code><?php echo esc_html( $settings['key'] ); ?></code></p>
			<p>
				<?php esc_html_e( 'Key file:', 'easy-indexnow' ); ?>
				<a href="<?php echo esc_url( $key_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $key_url ); ?></a>
				<br><span class="description"><?php esc_html_e( 'Open this link: it should show only your key. If it shows a 404, re-save Settings → Permalinks or check your caching plugin.', 'easy-indexnow' ); ?></span>
			</p>
			<p class="description">
				<?php
				if ( $key_path && file_exists( $key_path ) ) {
					esc_html_e( 'A real key file exists in your site root.', 'easy-indexnow' );
				} elseif ( $key_path ) {
					esc_html_e( 'Could not write a real key file to your site root (folder not writable), so it is served by WordPress instead.', 'easy-indexnow' );
				} else {
					esc_html_e( 'WordPress is in a subfolder, so the key file is served by WordPress.', 'easy-indexnow' );
				}
				?>
			</p>
			<?php
			$this->action_form( 'easy_indexnow_check', __( 'Check key file', 'easy-indexnow' ), 'primary' );
			$this->action_form( 'easy_indexnow_regen', __( 'Generate new key', 'easy-indexnow' ), 'secondary', __( 'Only generate a new key if your current one was exposed. Search engines must verify every new key, which causes temporary 403 errors. Continue?', 'easy-indexnow' ) );
			?>

			<hr>
			<form method="post" action="options.php">
				<?php settings_fields( 'easy_indexnow' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic submission', 'easy-indexnow' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto]" value="1" <?php checked( $settings['auto'], 1 ); ?>>
							<?php esc_html_e( 'Notify search engines when content is published, updated or removed', 'easy-indexnow' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content types', 'easy-indexnow' ); ?></th>
						<td>
							<?php foreach ( $types as $type ) : ?>
								<label style="display:block;margin-bottom:4px"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $settings['post_types'], true ) ); ?>>
									<?php echo esc_html( $type->labels->name ); ?>
									<code style="font-size:11px"><?php echo esc_html( $type->name ); ?></code>
									<?php if ( isset( $new[ $type->name ] ) ) : ?>
										<span style="background:#d63638;color:#fff;border-radius:9px;padding:1px 7px;font-size:11px"><?php esc_html_e( 'New', 'easy-indexnow' ); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'All content types with public URLs are listed, including ones added by other plugins and themes. Saving marks new ones as seen.', 'easy-indexnow' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New content types', 'easy-indexnow' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_new_types]" value="1" <?php checked( $settings['auto_new_types'], 1 ); ?>>
							<?php esc_html_e( 'Automatically enable content types added in the future', 'easy-indexnow' ); ?></label>
							<p class="description"><?php esc_html_e( 'When off, new types are listed with a "New" badge for you to review.', 'easy-indexnow' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ein-endpoint"><?php esc_html_e( 'Search engine endpoint', 'easy-indexnow' ); ?></label></th>
						<td>
							<select id="ein-endpoint" name="<?php echo esc_attr( self::OPTION ); ?>[endpoint]">
								<?php foreach ( self::endpoints() as $url => $label ) : ?>
									<option value="<?php echo esc_attr( $url ); ?>" <?php selected( $settings['endpoint'], $url ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Participating engines share submissions with each other, so one endpoint is enough.', 'easy-indexnow' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Submit URLs manually', 'easy-indexnow' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="easy_indexnow_manual">
				<?php wp_nonce_field( 'easy_indexnow_manual' ); ?>
				<textarea name="urls" rows="5" class="large-text code" placeholder="<?php echo esc_attr( home_url( '/sample-page/' ) ); ?>"></textarea>
				<p class="description"><?php esc_html_e( 'One URL per line. Only URLs on this site are accepted.', 'easy-indexnow' ); ?></p>
				<?php submit_button( __( 'Submit URLs', 'easy-indexnow' ), 'primary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Submit whole site', 'easy-indexnow' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Sends your homepage and all published content of the selected types. Use once after setup, not routinely.', 'easy-indexnow' ); ?></p>
			<?php $this->action_form( 'easy_indexnow_bulk', __( 'Submit all published content', 'easy-indexnow' ), 'secondary', __( 'Submit all published URLs now?', 'easy-indexnow' ) ); ?>

			<hr>
			<h2><?php esc_html_e( 'Recent submissions', 'easy-indexnow' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'Nothing submitted yet.', 'easy-indexnow' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Time', 'easy-indexnow' ); ?></th>
						<th><?php esc_html_e( 'Type', 'easy-indexnow' ); ?></th>
						<th><?php esc_html_e( 'Result', 'easy-indexnow' ); ?></th>
						<th><?php esc_html_e( 'URLs', 'easy-indexnow' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ); ?><br><small><?php echo esc_html( wp_timezone_string() ); ?></small></td>
							<td><?php echo esc_html( ucfirst( $entry['source'] ) ); ?></td>
							<td>
								<?php echo esc_html( ( $entry['code'] ? $entry['code'] . ' – ' : '' ) . $entry['message'] ); ?>
								<?php if ( ! empty( $entry['detail'] ) ) : ?>
									<br><small><?php esc_html_e( 'Search engine said:', 'easy-indexnow' ); ?> <code><?php echo esc_html( $entry['detail'] ); ?></code></small>
								<?php endif; ?>
							</td>
							<td>
								<?php
								foreach ( $entry['sample'] as $url ) {
									echo esc_html( $url ) . '<br>';
								}
								if ( $entry['count'] > count( $entry['sample'] ) ) {
									/* translators: %d: number of additional URLs */
									echo '<em>' . esc_html( sprintf( __( '…and %d more', 'easy-indexnow' ), $entry['count'] - count( $entry['sample'] ) ) ) . '</em>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'Easy_IndexNow', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Easy_IndexNow', 'deactivate' ) );
Easy_IndexNow::instance();
