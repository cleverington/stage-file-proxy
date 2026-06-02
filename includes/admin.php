<?php
/**
 * Stage File Proxy: Admin Settings
 *
 * @package stage-file-proxy
 */

namespace SFP;

if ( ! class_exists( 'SFP_Admin' ) ) {

	/**
	 * The SFP Admin class
	 */
	class SFP_Admin {
		/**
		 * The single instance of the class.
		 *
		 * @since 0.0.1
		 *
		 * @var object $instance;
		 */
		private static $instance;

		/**
		 * Constructor.
		 *
		 * @since 0.0.1
		 */
		public function __construct() {
			add_action( 'after_setup_theme', array( $this, 'sfp_admin' ) );
		}

		/**
		 * Cloning is forbidden.
		 *
		 * @since 0.0.1
		 */
		public function __clone() {
			wp_die( "Please don't __clone SFP_Admin" );
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 *
		 * @since 0.0.1
		 */
		public function __wakeup() {
			wp_die( "Please don't __wakeup SFP_Admin" );
		}

		/**
		 * Main SFP_Admin Instance.
		 *
		 * @since 0.0.1
		 */
		public static function instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
				self::$instance->setup();
			}
			return self::$instance;
		}

		/**
		 * SFP: Setup
		 *
		 * @since 0.0.1
		 */
		public function setup() {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_post_sfp_settings', array( $this, 'save_settings' ) );
			add_action( 'admin_post_sfp_test_fetch', array( $this, 'test_fetch' ) );
			add_action( 'admin_post_sfp_install_htaccess', array( $this, 'install_htaccess' ) );
			add_action( 'admin_post_sfp_routing_probe', array( $this, 'routing_probe' ) );
		}

		/**
		 * SFP: Admin menu
		 *
		 * @since 0.0.1
		 */
		public function admin_menu() {
			add_options_page(
				__( 'Stage File Proxy', 'stage-file-proxy' ),
				__( 'Stage File Proxy', 'stage-file-proxy' ),
				'manage_options',
				'stage-file-proxy',
				array(
					$this,
					'settings_page',
				)
			);
		}

		/**
		 * SFP: Settings page
		 *
		 * @since 0.0.1
		 */
		public function settings_page() {
			/* Do not load the Admin options for unapproved users. */
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die(
					esc_html( __( 'You do not have sufficient permissions to access this page.', 'stage-file-proxy' ) )
				);
			}
			/* Do not load the Admin options for Production environments. */
			if ( ! sfp_environment_allows() ) {
				wp_die(
					esc_html( __( 'URGENT: This plugin is not meant to be run in production environments.', 'stage-file-proxy' ) )
				);
			}

			$env_configured = sfp_is_env_configured();
			$display_url    = $env_configured
				? sfp_get_base_url()
				: (string) get_option( 'sfp_url', '' );
			$diagnostics    = sfp_get_runtime_diagnostics();
			$test_fetch     = null;
			$routing_probe  = null;
			if ( isset( $_GET['sfp_routing_handler'] ) ) {
				$routing_probe = array(
					'handler' => sanitize_key( wp_unslash( (string) $_GET['sfp_routing_handler'] ) ),
					'code'    => isset( $_GET['sfp_routing_code'] ) ? (int) $_GET['sfp_routing_code'] : 0,
					'detail'  => isset( $_GET['sfp_routing_detail'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sfp_routing_detail'] ) ) : '',
					'url'     => isset( $_GET['sfp_routing_url'] ) ? esc_url_raw( wp_unslash( (string) $_GET['sfp_routing_url'] ) ) : '',
				);
			}
			if ( isset( $_GET['sfp_test_code'] ) ) {
				$test_fetch = array(
					'code'    => sanitize_text_field( wp_unslash( (string) $_GET['sfp_test_code'] ) ),
					'message' => isset( $_GET['sfp_test_message'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sfp_test_message'] ) ) : '',
					'url'     => isset( $_GET['sfp_test_url'] ) ? esc_url_raw( wp_unslash( (string) $_GET['sfp_test_url'] ) ) : '',
				);
			}
			?>
			<div class="wrap">
				<h2><?php esc_html_e( 'Stage File Proxy', 'stage-file-proxy' ); ?></h2>
	
				<?php if ( isset( $_GET['error'] ) ) : ?>
					<div class="error updated"><p><?php esc_html_e( 'There was an error updating the settings', 'stage-file-proxy' ); ?></p></div>
				<?php endif ?>
	
				<?php if ( isset( $_GET['success'] ) ) : ?>
					<div class="updated success"><p><?php esc_html_e( 'Settings updated!', 'stage-file-proxy' ); ?></p></div>
				<?php endif ?>

				<?php if ( isset( $_GET['sfp_htaccess'] ) && '1' === $_GET['sfp_htaccess'] ) : ?>
					<div class="updated success"><p><?php esc_html_e( 'Uploads .htaccess routing rules installed.', 'stage-file-proxy' ); ?></p></div>
				<?php elseif ( isset( $_GET['sfp_htaccess_error'] ) ) : ?>
					<div class="error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( (string) $_GET['sfp_htaccess_error'] ) ) ); ?></p></div>
				<?php endif; ?>
	
				<div id="sfp-settings">
					<?php if ( $env_configured ) : ?>
						<p class="description">
							<?php esc_html_e( 'Remote URL is configured via SFP_REMOTE_BASE_URL (environment). Settings below apply only when not using environment configuration.', 'stage-file-proxy' ); ?>
						</p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="sfp_settings" />
						<?php wp_nonce_field( 'sfp_settings', 'sfp_settings_nonce' ); ?>
						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><label for="sfp_mode"><?php esc_html_e( 'Mode', 'stage-file-proxy' ); ?></label></th>
									<td>
										<select name="sfp[mode]" id="sfp_mode">
											<option value="download"<?php selected( 'download', get_option( 'sfp_mode' ) ); ?>><?php esc_html_e( 'Download', 'stage-file-proxy' ); ?></option>
											<option value="header"<?php selected( 'header', get_option( 'sfp_mode' ) ); ?>><?php esc_html_e( 'Redirect', 'stage-file-proxy' ); ?></option>
										</select>
									</td>
								</tr>
	
								<tr>
									<th scope="row"><label for="sfp_url"><?php esc_html_e( 'URL', 'stage-file-proxy' ); ?></label></th>
									<td>
										<?php if ( $env_configured ) : ?>
											<input type="text" id="sfp_url" value="<?php echo esc_url( $display_url ); ?>" style="width:100%;max-width:500px" readonly />
										<?php else : ?>
											<input type="text" name="sfp[url]" id="sfp_url" value="<?php echo esc_url( $display_url ); ?>" style="width:100%;max-width:500px" />
										<?php endif; ?>
										<p class="description"><?php esc_html_e( "This should point to the site's uploads directory", 'stage-file-proxy' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>
	
						<?php submit_button( __( 'Save Settings', 'stage-file-proxy' ), 'primary' ); ?>
					</form>
				</div>

				<h2><?php esc_html_e( 'Diagnostics', 'stage-file-proxy' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Values below reflect this admin request. Uploads file requests may differ (especially REQUEST_URI on Plesk).', 'stage-file-proxy' ); ?>
				</p>

				<div class="notice notice-warning inline" style="margin:1em 0;padding:1em;max-width:800px">
					<p><strong><?php esc_html_e( 'Apache/server 404 on missing uploads files', 'stage-file-proxy' ); ?></strong></p>
					<p><?php esc_html_e( 'Stage File Proxy only runs when PHP loads WordPress. On Plesk, nginx often returns 404 for missing .png/.jpg before Apache or .htaccess run — uploads/.htaccess alone cannot fix that.', 'stage-file-proxy' ); ?></p>
					<ol style="list-style:decimal;margin-left:1.5em">
						<li><?php esc_html_e( 'Install Apache routing rules below (uploads + root .htaccess).', 'stage-file-proxy' ); ?></li>
						<li><?php esc_html_e( 'Plesk → Domains → your site → Apache & nginx Settings → add the nginx snippet under “Additional nginx directives”.', 'stage-file-proxy' ); ?></li>
						<li><?php esc_html_e( 'Or uncheck “Serve static files directly by nginx” so Apache/.htaccess handle missing files.', 'stage-file-proxy' ); ?></li>
						<li><?php esc_html_e( 'Run “Test uploads routing” to confirm WordPress or SFP handles the request.', 'stage-file-proxy' ); ?></li>
					</ol>
				</div>

				<h3><?php esc_html_e( 'Server routing', 'stage-file-proxy' ); ?></h3>
				<table class="widefat striped" style="max-width:800px">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Uploads .htaccess', 'stage-file-proxy' ); ?></th>
							<td>
								<code><?php echo esc_html( (string) $diagnostics['uploads_htaccess']['path'] ); ?></code><br />
								<?php if ( $diagnostics['uploads_htaccess']['installed'] ) : ?>
									<strong><?php esc_html_e( 'Installed', 'stage-file-proxy' ); ?></strong>
								<?php else : ?>
									<strong><?php esc_html_e( 'Not installed', 'stage-file-proxy' ); ?></strong>
									<?php if ( ! $diagnostics['uploads_htaccess']['writable'] ) : ?>
										— <?php esc_html_e( 'uploads directory is not writable', 'stage-file-proxy' ); ?>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Root .htaccess', 'stage-file-proxy' ); ?></th>
							<td>
								<code><?php echo esc_html( (string) $diagnostics['root_htaccess']['path'] ); ?></code><br />
								<?php if ( $diagnostics['root_htaccess']['installed'] ) : ?>
									<strong><?php esc_html_e( 'Installed', 'stage-file-proxy' ); ?></strong>
								<?php else : ?>
									<strong><?php esc_html_e( 'Not installed', 'stage-file-proxy' ); ?></strong>
									<?php if ( ! $diagnostics['root_htaccess']['writable'] ) : ?>
										— <?php esc_html_e( 'WordPress root is not writable', 'stage-file-proxy' ); ?>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'index.php target', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo esc_html( (string) $diagnostics['uploads_htaccess']['index_php'] ); ?></code></td>
						</tr>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;display:inline-block;margin-right:8px">
					<input type="hidden" name="action" value="sfp_install_htaccess" />
					<?php wp_nonce_field( 'sfp_install_htaccess', 'sfp_install_htaccess_nonce' ); ?>
					<?php
					submit_button(
						__( 'Install Apache routing rules', 'stage-file-proxy' ),
						'secondary',
						'sfp_install_htaccess',
						false
					);
					?>
				</form>

				<form method="post" action="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;display:inline-block">
					<input type="hidden" name="action" value="sfp_routing_probe" />
					<?php wp_nonce_field( 'sfp_routing_probe', 'sfp_routing_probe_nonce' ); ?>
					<?php
					submit_button(
						__( 'Test uploads routing', 'stage-file-proxy' ),
						'secondary',
						'sfp_routing_probe',
						false
					);
					?>
				</form>

				<?php if ( null !== $routing_probe ) : ?>
					<div class="<?php echo in_array( $routing_probe['handler'], array( 'sfp', 'wordpress' ), true ) ? 'updated' : 'error'; ?>" style="margin-top:1em;max-width:800px">
						<p>
							<?php
							printf(
								/* translators: 1: handler key, 2: HTTP code, 3: detail */
								esc_html__( 'Routing probe: %1$s — HTTP %2$d. %3$s', 'stage-file-proxy' ),
								esc_html( $routing_probe['handler'] ),
								(int) $routing_probe['code'],
								esc_html( $routing_probe['detail'] )
							);
							?>
						</p>
						<?php if ( $routing_probe['url'] ) : ?>
							<p><code><?php echo esc_html( $routing_probe['url'] ); ?></code></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<p class="description" style="max-width:800px;margin-top:1em">
					<strong><?php esc_html_e( 'Plesk — Additional nginx directives', 'stage-file-proxy' ); ?></strong>
					<?php esc_html_e( '(required when nginx serves static files; ^~ beats .png location blocks)', 'stage-file-proxy' ); ?>
				</p>
				<pre style="max-width:800px;background:#f6f7f7;padding:1em;overflow:auto"><code><?php echo esc_html( sfp_get_nginx_uploads_snippet() ); ?></code></pre>

				<?php if ( null !== $test_fetch ) : ?>
					<div class="<?php echo ( '' !== $test_fetch['code'] && (int) $test_fetch['code'] <= 400 ) ? 'updated' : 'error'; ?>">
						<p>
							<?php
							printf(
								/* translators: 1: HTTP status code, 2: message, 3: URL */
								esc_html__( 'Test fetch: HTTP %1$s — %2$s (%3$s)', 'stage-file-proxy' ),
								esc_html( $test_fetch['code'] ),
								esc_html( $test_fetch['message'] ),
								esc_html( $test_fetch['url'] )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<table class="widefat striped" style="max-width:800px">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Plugin version', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo esc_html( (string) $diagnostics['version'] ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'WP_ENVIRONMENT_TYPE', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo esc_html( (string) ( $diagnostics['environment_type'] ?? '' ) ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Should run', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo $diagnostics['should_run'] ? 'yes' : 'no'; ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Basic auth configured', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo $diagnostics['auth_configured'] ? 'yes' : 'no'; ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Mode', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo esc_html( (string) $diagnostics['mode'] ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Image processing loaded', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo $diagnostics['image_processing'] ? 'yes' : 'no'; ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Resolved request path', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo esc_html( (string) $diagnostics['resolved_path'] ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Uploads request', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo $diagnostics['is_uploads_request'] ? 'yes' : 'no'; ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'THE_REQUEST path', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo esc_html( (string) $diagnostics['the_request_path'] ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Server scan path', 'stage-file-proxy' ); ?></th>
							<td><code><?php echo esc_html( (string) $diagnostics['server_scan_path'] ); ?></code></td>
						</tr>
					</tbody>
				</table>

				<?php if ( ! empty( $diagnostics['server_candidates'] ) ) : ?>
					<h3><?php esc_html_e( 'Server path candidates', 'stage-file-proxy' ); ?></h3>
					<table class="widefat striped" style="max-width:800px">
						<tbody>
							<?php foreach ( $diagnostics['server_candidates'] as $key => $value ) : ?>
								<tr>
									<th scope="row"><code><?php echo esc_html( (string) $key ); ?></code></th>
									<td><code><?php echo esc_html( (string) $value ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
					<input type="hidden" name="action" value="sfp_test_fetch" />
					<?php wp_nonce_field( 'sfp_test_fetch', 'sfp_test_fetch_nonce' ); ?>
					<?php
					submit_button(
						__( 'Test fetch home-page-4.png from live', 'stage-file-proxy' ),
						'secondary',
						'sfp_test_fetch',
						false
					);
					?>
				</form>
	
			</div>
			<?php
		}

		/**
		 * SFP: Save settings
		 *
		 * @since 0.0.1
		 */
		public function save_settings() {
			if ( ! isset( $_POST['sfp_settings_nonce'] ) || ! wp_verify_nonce( $_POST['sfp_settings_nonce'], 'sfp_settings' ) ) {
				wp_die( esc_html__( 'You are not authorized to perform that action', 'stage-file-proxy' ) );
			}

			if ( ! sfp_environment_allows() ) {
				wp_die( esc_html__( 'Stage File Proxy cannot be configured in this environment.', 'stage-file-proxy' ) );
			}

			if ( sfp_is_env_configured() ) {
				wp_redirect( admin_url( 'options-general.php?page=stage-file-proxy&success=1' ) );
				exit;
			}

			if ( isset( $_POST['sfp']['url'], $_POST['sfp']['mode'] ) ) {
					update_option( 'sfp_url', sanitize_url( $_POST['sfp']['url'] ) );
					update_option( 'sfp_mode', 'header' == $_POST['sfp']['mode'] ? 'header' : 'download' );
				wp_redirect( admin_url( 'options-general.php?page=stage-file-proxy&success=1' ) );
			} else {
				wp_redirect( admin_url( 'options-general.php?page=stage-file-proxy&error=1' ) );
			}

			exit;
		}

		/**
		 * Run a remote test fetch and redirect back with results.
		 *
		 * @since 0.1.5
		 */
		public function test_fetch() {
			if ( ! isset( $_POST['sfp_test_fetch_nonce'] ) || ! wp_verify_nonce( $_POST['sfp_test_fetch_nonce'], 'sfp_test_fetch' ) ) {
				wp_die( esc_html__( 'You are not authorized to perform that action', 'stage-file-proxy' ) );
			}

			if ( ! current_user_can( 'manage_options' ) || ! sfp_environment_allows() ) {
				wp_die( esc_html__( 'Stage File Proxy cannot run tests in this environment.', 'stage-file-proxy' ) );
			}

			if ( ! sfp_should_run() || ! function_exists( 'sfp_run_test_fetch' ) ) {
				wp_redirect(
					add_query_arg(
						array(
							'page'             => 'stage-file-proxy',
							'sfp_test_code'    => '0',
							'sfp_test_message' => __( 'Proxy is not configured to run.', 'stage-file-proxy' ),
						),
						admin_url( 'options-general.php' )
					)
				);
				exit;
			}

			$result = sfp_run_test_fetch( '2018/10/home-page-4.png' );

			wp_redirect(
				add_query_arg(
					array(
						'page'             => 'stage-file-proxy',
						'sfp_test_code'    => (string) $result['code'],
						'sfp_test_message' => (string) $result['message'],
						'sfp_test_url'     => (string) $result['url'],
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		/**
		 * Install uploads/.htaccess routing rules.
		 *
		 * @since 0.1.5
		 */
		public function install_htaccess() {
			if ( ! isset( $_POST['sfp_install_htaccess_nonce'] ) || ! wp_verify_nonce( $_POST['sfp_install_htaccess_nonce'], 'sfp_install_htaccess' ) ) {
				wp_die( esc_html__( 'You are not authorized to perform that action', 'stage-file-proxy' ) );
			}

			if ( ! current_user_can( 'manage_options' ) || ! sfp_environment_allows() ) {
				wp_die( esc_html__( 'Stage File Proxy cannot be configured in this environment.', 'stage-file-proxy' ) );
			}

			$result = sfp_install_all_routing();

			if ( is_wp_error( $result ) ) {
				wp_redirect(
					add_query_arg(
						array(
							'page'                => 'stage-file-proxy',
							'sfp_htaccess_error'  => $result->get_error_message(),
						),
						admin_url( 'options-general.php' )
					)
				);
				exit;
			}

			wp_redirect(
				add_query_arg(
					array(
						'page'           => 'stage-file-proxy',
						'sfp_htaccess'   => '1',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		/**
		 * Probe how the host routes missing uploads URLs.
		 *
		 * @since 0.1.5
		 */
		public function routing_probe() {
			if ( ! isset( $_POST['sfp_routing_probe_nonce'] ) || ! wp_verify_nonce( $_POST['sfp_routing_probe_nonce'], 'sfp_routing_probe' ) ) {
				wp_die( esc_html__( 'You are not authorized to perform that action', 'stage-file-proxy' ) );
			}

			if ( ! current_user_can( 'manage_options' ) || ! sfp_environment_allows() ) {
				wp_die( esc_html__( 'Stage File Proxy cannot run tests in this environment.', 'stage-file-proxy' ) );
			}

			$result = sfp_run_routing_probe();

			wp_redirect(
				add_query_arg(
					array(
						'page'               => 'stage-file-proxy',
						'sfp_routing_handler' => $result['handler'],
						'sfp_routing_code'    => (string) $result['code'],
						'sfp_routing_detail'  => $result['detail'],
						'sfp_routing_url'     => $result['url'],
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		/**
		 * SFP: Trigger the instance of the SFP_Admin class
		 *
		 * @since 0.0.1
		 */
		public function sfp_admin() {
			return self::instance();
		}
	}

}

new SFP_Admin();