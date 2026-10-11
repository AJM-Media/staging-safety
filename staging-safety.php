<?php
/**
 * Plugin Name:       Staging Safety
 * Plugin URI:        https://wordpress.org/plugins/staging-safety/
 * Description:       Shows which copy of your site you're on, holds emails on staging and local copies, and keeps them out of search engines.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Andrew Murray
 * Author URI:        https://ajmmedia.co.uk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       staging-safety
 *
 * @package StagingSafety
 */

defined( 'ABSPATH' ) || exit;

define( 'STSF_VERSION', '0.1.0' );
define( 'STSF_FILE', __FILE__ );
define( 'STSF_DIR', plugin_dir_path( __FILE__ ) );
define( 'STSF_URL', plugin_dir_url( __FILE__ ) );
define( 'STSF_DONATE_URL', 'https://ko-fi.com/ajmmedia' );

require_once STSF_DIR . 'includes/class-environment.php';
require_once STSF_DIR . 'includes/class-settings.php';
require_once STSF_DIR . 'includes/class-mail-guard.php';
require_once STSF_DIR . 'includes/class-search-guard.php';
require_once STSF_DIR . 'includes/class-badge.php';
require_once STSF_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', array( StagingSafety\Mail_Guard::class, 'init' ) );
add_action( 'plugins_loaded', array( StagingSafety\Search_Guard::class, 'init' ) );
add_action( 'plugins_loaded', array( StagingSafety\Badge::class, 'init' ) );
add_action( 'plugins_loaded', array( StagingSafety\Admin::class, 'init' ) );
