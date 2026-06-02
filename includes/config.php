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
 * Candidate request paths from server variables (rewrite stacks often use REDIRECT_URL).
 *
 * @return string[]
 */
function sfp_get_request_path_candidates(): array {
	$keys  = array(
		'REDIRECT_URL',
		'HTTP_X_ORIGINAL_URL',
		'HTTP_X_REWRITE_URL',
		'UNENCODED_URL',
		'REQUEST_URI',
		'DOCUMENT_URI',
		'PATH_INFO',
		'ORIG_PATH_INFO',
	);
	$paths = array();

	foreach ( $keys as $key ) {
		if ( empty( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			continue;
		}

		$path = wp_unslash( $_SERVER[ $key ] );
		if ( $path !== '' ) {
			$paths[] = $path;
		}
	}

	return $paths;
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

	$fallback = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';

	return apply_filters( 'sfp_request_path', $fallback );
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
