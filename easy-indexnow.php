<?php
/**
 * Plugin Name:       Easy IndexNow
 * Description:       Automatically notifies search engines (Bing, Yandex, Seznam, Naver and others) through the IndexNow protocol when content is published, updated or removed.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
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

		// Admin.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_easy_indexnow_manual', array( $this, 'handle_manual' ) );
		add_action( 'admin_post_easy_indexnow_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_easy_indexnow_regen', array( $this, 'handle_regen' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings & key
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'key'        => '',
			'endpoint'   => 'https://api.indexnow.org/indexnow',
			'post_types' => array( 'post', 'page' ),
			'auto'       => 1,
		);
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
			$settings['key'] = self::generate_key();
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
		self::get_settings(); // Creates the key on first activation.
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
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
	 * @param string   $source auto|manual|bulk
	 * @return array { code: int, message: string }
	 */
	public function submit( array $urls, $source = 'manual' ) {
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
			$result = array( 'code' => 0, 'message' => $response->get_error_message() );
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$result = array( 'code' => $code, 'message' => self::describe_code( $code ) );
		}

		$this->log( $urls, $result, $source );
		return $result;
	}

	public static function describe_code( $code ) {
		$messages = array(
			200 => __( 'OK: URLs submitted successfully.', 'easy-indexnow' ),
			202 => __( 'Accepted: URLs received, key validation pending.', 'easy-indexnow' ),
			400 => __( 'Bad request: invalid format.', 'easy-indexnow' ),
			403 => __( 'Forbidden: key not valid (key file not found or does not contain the key).', 'easy-indexnow' ),
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

		return array(
			// The settings form never sends a key, so it keeps the current one;
			// internal saves (activation, "Generate new key") pass a fresh valid key.
			'key'        => ( isset( $input['key'] ) && self::is_valid_key( $input['key'] ) ) ? $input['key'] : $current['key'],
			'endpoint'   => array_key_exists( $endpoint, self::endpoints() ) ? $endpoint : self::defaults()['endpoint'],
			'post_types' => array_values( array_filter( $types, 'is_post_type_viewable' ) ),
			'auto'       => empty( $input['auto'] ) ? 0 : 1,
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
		$settings        = self::get_settings();
		$settings['key'] = self::generate_key();
		update_option( self::OPTION, $settings );
		$this->redirect_back( __( 'A new key was generated.', 'easy-indexnow' ) );
	}

	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_easy-indexnow' !== $screen->id ) {
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
		$types    = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
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
			<?php $this->action_form( 'easy_indexnow_regen', __( 'Generate new key', 'easy-indexnow' ), 'secondary', __( 'Replace the current key?', 'easy-indexnow' ) ); ?>

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
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $settings['post_types'], true ) ); ?>>
									<?php echo esc_html( $type->labels->name ); ?></label>
							<?php endforeach; ?>
						</td>
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
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ); ?></td>
							<td><?php echo esc_html( ucfirst( $entry['source'] ) ); ?></td>
							<td><?php echo esc_html( ( $entry['code'] ? $entry['code'] . ' – ' : '' ) . $entry['message'] ); ?></td>
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
