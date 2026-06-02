<?php
/**
 * Stage File Proxy: environment gates and remote configuration.
 *
 * @package stage-file-proxy
 */

/**
 * Read WP_ENVIRONMENT_TYPE as explicitly set (constant or env var), not WordPress defaults.
 *
 * @return string|null Environment type, or null when unset.
 */
function sfp_get_explicit_environment_type(): ?string {
	if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE !== '' ) {
		return (string) WP_ENVIRONMENT_TYPE;
	}

	if ( isset( $_ENV['WP_ENVIRONMENT_TYPE'] ) && is_string( $_ENV['WP_ENVIRONMENT_TYPE'] ) && $_ENV['WP_ENVIRONMENT_TYPE'] !== '' ) {
		return $_ENV['WP_ENVIRONMENT_TYPE'];
	}

	if ( function_exists( 'getenv' ) ) {
		$env = getenv( 'WP_ENVIRONMENT_TYPE' );
		if ( false !== $env && $env !== '' ) {
			return (string) $env;
		}
	}

	return null;
}

/**
 * Whether the current environment may use Stage File Proxy.
 *
 * @return bool
 */
function sfp_environment_allows(): bool {
	$raw = sfp_get_explicit_environment_type();
	if ( $raw === null || $raw === '' ) {
		return false;
	}

	return in_array( $raw, array( 'local', 'development', 'staging' ), true );
}

/**
 * Whether a remote uploads base URL is configured.
 *
 * @return bool
 */
function sfp_has_remote_config(): bool {
	if ( defined( 'SFP_REMOTE_BASE_URL' ) && SFP_REMOTE_BASE_URL !== '' ) {
		return true;
	}

	return (string) get_option( 'sfp_url', '' ) !== '';
}

/**
 * Whether proxying should run (environment + remote config).
 *
 * @return bool
 */
function sfp_should_run(): bool {
	return sfp_environment_allows() && sfp_has_remote_config();
}

/**
 * Read a non-empty string from the environment.
 *
 * @param string $name Environment variable name.
 * @return string
 */
function sfp_get_env_string( string $name ): string {
	if ( isset( $_ENV[ $name ] ) && is_string( $_ENV[ $name ] ) && $_ENV[ $name ] !== '' ) {
		return $_ENV[ $name ];
	}

	if ( function_exists( 'getenv' ) ) {
		$value = getenv( $name );
		if ( false !== $value && $value !== '' ) {
			return (string) $value;
		}
	}

	return '';
}

/**
 * Extract an uploads path from Apache THE_REQUEST (original request line).
 *
 * @return string
 */
function sfp_parse_the_request(): string {
	if ( empty( $_SERVER['THE_REQUEST'] ) || ! is_string( $_SERVER['THE_REQUEST'] ) ) {
		return '';
	}

	if ( preg_match( '#\s(/wp-content/uploads/\S+?)\s+HTTP/#i', $_SERVER['THE_REQUEST'], $matches ) ) {
		return wp_unslash( $matches[1] );
	}

	return '';
}

/**
 * Scan all $_SERVER values for an uploads path (Plesk/proxy fallback).
 *
 * @return string
 */
function sfp_scan_server_for_uploads_path(): string {
	foreach ( $_SERVER as $value ) {
		if ( ! is_string( $value ) || strlen( $value ) < 20 ) {
			continue;
		}

		if ( preg_match( '~(/wp-content/uploads/[^\s"\'?#]+)~i', $value, $matches ) ) {
			return wp_unslash( $matches[1] );
		}
	}

	return '';
}

/**
 * Named server variables checked for the request path (diagnostics + candidates).
 *
 * @return string[]
 */
function sfp_get_request_path_server_keys(): array {
	return array(
		'THE_REQUEST',
		'REDIRECT_URL',
		'HTTP_X_ORIGINAL_URL',
		'HTTP_X_REWRITE_URL',
		'HTTP_X_FORWARDED_URI',
		'UNENCODED_URL',
		'REQUEST_URI',
		'DOCUMENT_URI',
		'PATH_INFO',
		'ORIG_PATH_INFO',
	);
}

/**
 * Candidate request paths from server variables (rewrite stacks often use REDIRECT_URL).
 *
 * @return string[]
 */
function sfp_get_request_path_candidates(): array {
	$paths = array();

	$the_request = sfp_parse_the_request();
	if ( $the_request !== '' ) {
		$paths[] = $the_request;
	}

	foreach ( sfp_get_request_path_server_keys() as $key ) {
		if ( empty( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			continue;
		}

		$path = wp_unslash( $_SERVER[ $key ] );
		if ( $path !== '' ) {
			$paths[] = $path;
		}
	}

	return array_values( array_unique( $paths ) );
}

/**
 * Best-effort request path for the current HTTP request.
 *
 * Prefers any candidate containing /wp-content/uploads/. Falls back to the first
 * non-root path because some hosts rewrite uploads requests with REQUEST_URI=/.
 *
 * @return string
 */
function sfp_get_request_path(): string {
	$paths = sfp_get_request_path_candidates();

	foreach ( $paths as $path ) {
		if ( stripos( $path, '/wp-content/uploads/' ) !== false ) {
			/**
			 * Filters the resolved request path used by Stage File Proxy.
			 *
			 * @param string $path Request path.
			 */
			return apply_filters( 'sfp_request_path', $path );
		}
	}

	foreach ( $paths as $path ) {
		if ( $path !== '/' ) {
			return apply_filters( 'sfp_request_path', $path );
		}
	}

	$scanned = sfp_scan_server_for_uploads_path();
	if ( $scanned !== '' ) {
		return apply_filters( 'sfp_request_path', $scanned );
	}

	$fallback = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';

	return apply_filters( 'sfp_request_path', $fallback );
}

/**
 * Runtime diagnostics for the settings screen.
 *
 * @return array<string, mixed>
 */
function sfp_get_runtime_diagnostics(): array {
	$candidates = array();
	foreach ( sfp_get_request_path_server_keys() as $key ) {
		if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && $_SERVER[ $key ] !== '' ) {
			$candidates[ $key ] = wp_unslash( $_SERVER[ $key ] );
		}
	}

	return array(
		'version'              => defined( 'STAGE_FILE_PROXY_VERSION' ) ? STAGE_FILE_PROXY_VERSION : '',
		'environment_type'     => sfp_get_explicit_environment_type(),
		'should_run'           => sfp_should_run(),
		'env_configured'       => sfp_is_env_configured(),
		'auth_configured'      => null !== sfp_get_remote_auth(),
		'mode'                 => function_exists( 'sfp_get_mode' ) ? sfp_get_mode() : '',
		'resolved_path'        => sfp_get_request_path(),
		'is_uploads_request'   => sfp_is_uploads_request(),
		'the_request_path'     => sfp_parse_the_request(),
		'server_scan_path'     => sfp_scan_server_for_uploads_path(),
		'server_candidates'    => $candidates,
		'image_processing'     => defined( 'SFP_IMAGE_PROCESSING_LOADED' ) && SFP_IMAGE_PROCESSING_LOADED,
		'uploads_htaccess'     => sfp_get_uploads_htaccess_status(),
		'root_htaccess'        => sfp_get_root_htaccess_status(),
	);
}

/**
 * Test remote fetch for diagnostics (does not save the file locally).
 *
 * @param string $relative_path Path relative to uploads base, e.g. 2018/10/home-page-4.png.
 * @return array{ok: bool, url: string, code: int|string, message: string}
 */
function sfp_run_test_fetch( string $relative_path = '2018/10/home-page-4.png' ): array {
	if ( ! function_exists( 'sfp_get_base_url' ) ) {
		return array(
			'ok'      => false,
			'url'     => '',
			'code'    => '',
			'message' => __( 'Image processing module is not loaded.', 'stage-file-proxy' ),
		);
	}

	$relative_path = ltrim( $relative_path, '/' );
	$remote_url    = trailingslashit( sfp_get_base_url() ) . $relative_path;
	$args          = apply_filters( 'sfp_http_remote_args', array( 'timeout' => 30 ) );
	$response      = wp_remote_get( $remote_url, $args );

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'      => false,
			'url'     => $remote_url,
			'code'    => '',
			'message' => $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	return array(
		'ok'      => $code > 0 && $code <= 400,
		'url'     => $remote_url,
		'code'    => $code,
		'message' => $code > 400 ? sprintf( 'HTTP %d', $code ) : __( 'OK', 'stage-file-proxy' ),
	);
}

/**
 * Whether the current request targets the uploads directory.
 *
 * @param string|null $path Optional path; defaults to sfp_get_request_path().
 * @return bool
 */
function sfp_is_uploads_request( ?string $path = null ): bool {
	$path = null !== $path ? $path : sfp_get_request_path();

	return stripos( $path, '/wp-content/uploads/' ) !== false;
}

/**
 * Parse basic-auth credentials from a URL and return a userinfo-free URL.
 *
 * @param string $url Remote URL, optionally with embedded user:pass.
 * @return array{url: string, user: ?string, pass: ?string}
 */
function sfp_parse_remote_auth( string $url ): array {
	$parts = wp_parse_url( $url );
	if ( false === $parts || empty( $parts['host'] ) ) {
		return array(
			'url'  => $url,
			'user' => null,
			'pass' => null,
		);
	}

	$user = isset( $parts['user'] ) ? rawurldecode( $parts['user'] ) : null;
	$pass = isset( $parts['pass'] ) ? rawurldecode( (string) $parts['pass'] ) : null;

	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
	$host   = $parts['host'];
	$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
	$path   = $parts['path'] ?? '';
	$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
	$frag   = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

	return array(
		'url'  => $scheme . $host . $port . $path . $query . $frag,
		'user' => $user,
		'pass' => $pass,
	);
}

/**
 * Raw remote uploads base URL from constant or option.
 *
 * @return string
 */
function sfp_get_raw_remote_base_url(): string {
	if ( defined( 'SFP_REMOTE_BASE_URL' ) && SFP_REMOTE_BASE_URL !== '' ) {
		return (string) SFP_REMOTE_BASE_URL;
	}

	return (string) get_option( 'sfp_url', '' );
}

/**
 * Basic-auth credentials parsed from the configured remote URL.
 *
 * @return array{user: string, pass: string}|null
 */
function sfp_get_remote_auth(): ?array {
	static $auth    = null;
	static $checked = false;

	if ( $checked ) {
		return $auth;
	}

	$checked = true;
	$parsed  = sfp_parse_remote_auth( sfp_get_raw_remote_base_url() );

	if ( $parsed['user'] === null || $parsed['user'] === '' ) {
		return null;
	}

	$auth = array(
		'user' => $parsed['user'],
		'pass' => $parsed['pass'] ?? '',
	);

	return $auth;
}

/**
 * Whether remote URL is configured via wp-config constant / environment.
 *
 * @return bool
 */
function sfp_is_env_configured(): bool {
	return defined( 'SFP_REMOTE_BASE_URL' ) && SFP_REMOTE_BASE_URL !== '';
}

/**
 * Add Authorization header for server-side remote requests when credentials are embedded in the URL.
 *
 * @param array<string, mixed> $args wp_remote_get() arguments.
 * @return array<string, mixed>
 */
function sfp_add_remote_auth_to_request( array $args ): array {
	$auth = sfp_get_remote_auth();
	if ( null === $auth ) {
		return $args;
	}

	if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
		$args['headers'] = array();
	}

	$args['headers']['Authorization'] = 'Basic ' . base64_encode( $auth['user'] . ':' . $auth['pass'] );

	return $args;
}

add_filter( 'sfp_http_remote_args', 'sfp_add_remote_auth_to_request' );

/**
 * Marker string written into uploads/.htaccess.
 *
 * @return string
 */
function sfp_get_uploads_htaccess_marker(): string {
	return 'Stage File Proxy';
}

/**
 * Web path to index.php (supports subdirectory installs).
 *
 * @return string
 */
function sfp_get_index_php_path(): string {
	if ( function_exists( 'home_url' ) ) {
		$path = (string) parse_url( home_url( '/index.php' ), PHP_URL_PATH );
		if ( $path !== '' ) {
			return $path;
		}
	}

	return '/index.php';
}

/**
 * RewriteBase for uploads/.htaccess (subdirectory installs).
 *
 * @return string
 */
function sfp_get_uploads_rewrite_base(): string {
	if ( function_exists( 'content_url' ) ) {
		$path = (string) parse_url( content_url( '/uploads/' ), PHP_URL_PATH );
		if ( $path !== '' ) {
			return trailingslashit( $path );
		}
	}

	return '/wp-content/uploads/';
}

/**
 * Apache rewrite rules for missing uploads files.
 *
 * @return string
 */
function sfp_get_uploads_htaccess_rules(): string {
	$index        = sfp_get_index_php_path();
	$rewrite_base = sfp_get_uploads_rewrite_base();
	$marker       = sfp_get_uploads_htaccess_marker();

	return <<<HTACCESS
# BEGIN {$marker}
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase {$rewrite_base}
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . {$index} [L]
</IfModule>
<IfModule mod_dir.c>
DirectoryIndex disabled
FallbackResource {$index}
</IfModule>
# END {$marker}
HTACCESS;
}

/**
 * Rewrite rules for the site root .htaccess (Apache handles request, not nginx static).
 *
 * @return string[]
 */
function sfp_get_root_htaccess_rules(): array {
	$index = sfp_get_index_php_path();

	return array(
		'RewriteEngine On',
		'RewriteCond %{REQUEST_URI} /wp-content/uploads/ [NC]',
		'RewriteCond %{REQUEST_FILENAME} !-f',
		'RewriteCond %{REQUEST_FILENAME} !-d',
		'RewriteRule .* ' . $index . ' [L]',
	);
}

/**
 * Absolute path to the WordPress root .htaccess file.
 *
 * @return string
 */
function sfp_get_root_htaccess_file(): string {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	return get_home_path() . '.htaccess';
}

/**
 * Status of root .htaccess routing rules.
 *
 * @return array{path: string, installed: bool, writable: bool}
 */
function sfp_get_root_htaccess_status(): array {
	$file    = sfp_get_root_htaccess_file();
	$marker  = sfp_get_uploads_htaccess_marker();
	$installed = false;

	if ( is_readable( $file ) ) {
		$content   = (string) file_get_contents( $file );
		$installed = strpos( $content, $marker ) !== false;
	}

	$dir = dirname( $file );

	return array(
		'path'      => $file,
		'installed' => $installed,
		'writable'  => ( is_dir( $dir ) && is_writable( $dir ) ) || ( file_exists( $file ) && is_writable( $file ) ),
	);
}

/**
 * Insert routing rules into the WordPress root .htaccess.
 *
 * @return true|\WP_Error
 */
function sfp_install_root_htaccess() {
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	$status = sfp_get_root_htaccess_status();
	if ( ! $status['writable'] && ! $status['installed'] ) {
		return new \WP_Error( 'sfp_root_htaccess_not_writable', __( 'WordPress root directory is not writable.', 'stage-file-proxy' ) );
	}

	$result = insert_with_markers( $status['path'], sfp_get_uploads_htaccess_marker(), sfp_get_root_htaccess_rules() );

	if ( false === $result ) {
		return new \WP_Error( 'sfp_root_htaccess_write_failed', __( 'Could not write WordPress root .htaccess.', 'stage-file-proxy' ) );
	}

	return true;
}

/**
 * Install all Apache routing rules (uploads + root).
 *
 * @return true|\WP_Error
 */
function sfp_install_all_routing() {
	$uploads = sfp_install_uploads_htaccess();
	if ( is_wp_error( $uploads ) ) {
		return $uploads;
	}

	$root = sfp_install_root_htaccess();
	if ( is_wp_error( $root ) ) {
		return $root;
	}

	return true;
}

/**
 * Absolute path to the uploads directory.
 *
 * @return string
 */
function sfp_get_uploads_directory(): string {
	if ( function_exists( 'wp_upload_dir' ) ) {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			return $upload_dir['basedir'];
		}
	}

	return WP_CONTENT_DIR . '/uploads';
}

/**
 * Status of the uploads/.htaccess routing rules.
 *
 * @return array{path: string, installed: bool, writable: bool, index_php: string}
 */
function sfp_get_uploads_htaccess_status(): array {
	$dir  = sfp_get_uploads_directory();
	$file = trailingslashit( $dir ) . '.htaccess';
	$marker = sfp_get_uploads_htaccess_marker();
	$installed = false;

	if ( is_readable( $file ) ) {
		$content = (string) file_get_contents( $file );
		$installed = strpos( $content, $marker ) !== false;
	}

	return array(
		'path'       => $file,
		'installed'  => $installed,
		'writable'   => is_dir( $dir ) && is_writable( $dir ),
		'index_php'  => sfp_get_index_php_path(),
	);
}

/**
 * Write or append Stage File Proxy rules to uploads/.htaccess.
 *
 * @return true|\WP_Error
 */
function sfp_install_uploads_htaccess() {
	$dir = sfp_get_uploads_directory();

	if ( ! is_dir( $dir ) ) {
		return new \WP_Error( 'sfp_no_uploads_dir', __( 'Uploads directory does not exist.', 'stage-file-proxy' ) );
	}

	if ( ! is_writable( $dir ) ) {
		return new \WP_Error( 'sfp_uploads_not_writable', __( 'Uploads directory is not writable.', 'stage-file-proxy' ) );
	}

	$file  = trailingslashit( $dir ) . '.htaccess';
	$rules = sfp_get_uploads_htaccess_rules();
	$marker = sfp_get_uploads_htaccess_marker();

	if ( is_readable( $file ) ) {
		$content = (string) file_get_contents( $file );
		if ( strpos( $content, $marker ) !== false ) {
			return true;
		}
		$content = rtrim( $content ) . "\n\n" . $rules . "\n";
	} else {
		$content = $rules . "\n";
	}

	if ( false === file_put_contents( $file, $content ) ) {
		return new \WP_Error( 'sfp_htaccess_write_failed', __( 'Could not write uploads/.htaccess.', 'stage-file-proxy' ) );
	}

	return true;
}

/**
 * nginx snippet for Plesk (must beat static extension locations — use ^~).
 *
 * @return string
 */
function sfp_get_nginx_uploads_snippet(): string {
	return 'location ^~ /wp-content/uploads/ {
    try_files $uri $uri/ /index.php?$args;
}';
}

/**
 * Classify how the host handles a missing uploads file request.
 *
 * @return array{url: string, code: int, handler: string, detail: string}
 */
function sfp_run_routing_probe(): array {
	$probe_file = 'sfp-routing-probe-' . wp_generate_password( 12, false, false ) . '.png';
	$url        = home_url( '/wp-content/uploads/' . $probe_file );
	$response   = wp_remote_get(
		$url,
		array(
			'timeout'   => 15,
			'sslverify' => false,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'url'     => $url,
			'code'    => 0,
			'handler' => 'error',
			'detail'  => $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	$server = (string) wp_remote_retrieve_header( $response, 'server' );
	$x_powered = (string) wp_remote_retrieve_header( $response, 'x-powered-by' );

	if ( str_contains( $body, 'SFP tried to load but encountered an error' ) ) {
		return array(
			'url'     => $url,
			'code'    => $code,
			'handler' => 'sfp',
			'detail'  => __( 'WordPress and Stage File Proxy ran. Remote fetch failed or file could not be saved.', 'stage-file-proxy' ),
		);
	}

	if ( $code >= 200 && $code < 300 ) {
		return array(
			'url'     => $url,
			'code'    => $code,
			'handler' => 'success',
			'detail'  => __( 'Unexpected success (probe file should not exist locally).', 'stage-file-proxy' ),
		);
	}

	if ( str_contains( $body, 'wp-content' ) || str_contains( $body, 'WordPress' ) || str_contains( $x_powered, 'PHP' ) ) {
		return array(
			'url'     => $url,
			'code'    => $code,
			'handler' => 'wordpress',
			'detail'  => __( 'WordPress handled the request (routing works; SFP did not dispatch).', 'stage-file-proxy' ),
		);
	}

	if ( str_contains( $body, 'Apache' ) || str_contains( $server, 'Apache' ) || str_contains( $body, 'nginx' ) || str_contains( $server, 'nginx' ) ) {
		return array(
			'url'     => $url,
			'code'    => $code,
			'handler' => 'server',
			'detail'  => __( 'Web server 404 before WordPress. On Plesk: add the nginx snippet (Additional nginx directives) or disable “Serve static files directly by nginx”.', 'stage-file-proxy' ),
		);
	}

	return array(
		'url'     => $url,
		'code'    => $code,
		'handler' => 'unknown',
		'detail'  => __( 'Could not classify the response. See URL and status code.', 'stage-file-proxy' ),
	);
}
