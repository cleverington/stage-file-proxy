<?php
/*
	Plugin Name: Stage File Proxy
	Plugin URI: https://taoti.com
	Description: Get only the files you need from your production environment. Don't ever run this in production!
	Version: 0.1.2
	Author: Charles Leverington, Taoti Creative
	Author URI: https://taoti.com
*/

/**
 * The majority of this plugin is an upgraded and modernized version of the Stage
 * File Proxy plugin published, and deprecated, by Alley Interactive.
 */

namespace SFP;

// If this file is called directly or plugin is already defined, abort
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Get the minimum version of PHP required by this plugin.
 *
 * @since 0.0.1
 *
 * @return string Minimum version required.
 */
function minimum_php_requirement() {
	return '8.0.0';
}

/**
 * Whether PHP installation meets the minimum requirements
 *
 * @since 0.0.1
 *
 * @return bool True if meets minimum requirements, false otherwise.
 */
function site_meets_php_requirements() {
	return version_compare( phpversion(), minimum_php_requirement(), '>=' );
}

// Ensure PHP version is met first.
if ( ! site_meets_php_requirements() ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Minimum required PHP version */
							__( 'Stage File Proxy requires PHP version %s or later. Please upgrade PHP or disable the plugin.', 'stage-file-proxy' ),
							esc_html( minimum_php_requirement() )
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	);
	return;
}

/**
 * Whether PHP installation meets the minimum requirements
 *
 * @since 0.0.1
 *
 * @return bool True if meets minimum requirements, false otherwise.
 */
function site_in_development() {
	return 'production' !== wp_get_environment_type();
}

/**
 * Plugin version.
 *
 * Configured to allow for easy version checking and prevent 'update' notices
 * from published version of the plugin this version forked from.
 */
if ( ! defined( 'STAGE_FILE_PROXY_VERSION' ) ) {
	define( 'STAGE_FILE_PROXY_VERSION', '0.1.2' );
}

// Plugin Folder Path.
if ( ! defined( 'STAGE_FILE_PROXY_DIR' ) ) {
	define( 'STAGE_FILE_PROXY_DIR', plugin_dir_path( __FILE__ ) );
}

// Plugin Folder URL.
if ( ! defined( 'STAGE_FILE_PROXY_URL' ) ) {
	define( 'STAGE_FILE_PROXY_URL', plugin_dir_url( __FILE__ ) );
}

// Plugin Root File.
if ( ! defined( 'STAGE_FILE_PROXY_FILE' ) ) {
	define( 'STAGE_FILE_PROXY_FILE', __FILE__ );
}

require_once STAGE_FILE_PROXY_DIR . 'includes/admin.php';

// If this is a production environment, do not let the plugin load.
if ( site_in_development() ) {

	// Process images from Remote Server.
	require_once STAGE_FILE_PROXY_DIR . 'includes/image-processing.php';
} else {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							__( 'URGENT: Stage File Proxy Plugin should not be active on Production Environments.', 'stage-file-proxy' ),
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	);
}
