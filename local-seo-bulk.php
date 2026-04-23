<?php
/**
 * Plugin Name:       Local SEO Bulk Editor
 * Plugin URI:        https://example.com/local-seo-bulk
 * Description:       Édition en masse des H1, meta titles et meta descriptions par entité (page, article, CPT, taxonomie), avec tokens géolocalisés (ville, code postal, adresse) utilisables comme shortcodes ou variables Yoast. Support multisite avec scopes et patterns réseau.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            64pixels
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       local-seo-bulk
 * Domain Path:       /languages
 * Network:           true
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LSB_VERSION', '0.2.0' );
define( 'LSB_FILE', __FILE__ );
define( 'LSB_PATH', plugin_dir_path( __FILE__ ) );
define( 'LSB_URL', plugin_dir_url( __FILE__ ) );
define( 'LSB_BASENAME', plugin_basename( __FILE__ ) );

require_once LSB_PATH . 'includes/class-lsb-plugin.php';

register_activation_hook( __FILE__, [ 'LSB_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'LSB_Plugin', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'LSB_Plugin', 'instance' ] );
