<?php
/**
 * Plugin Name: Static Site Generator for WordPress — WPR Static
 * Description: Turn your WordPress site into a static HTML frontend on the same domain, with automatic rebuilds, validation and ZIP export.
 * Version: 1.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: Ворд-пресс.рф
 * License: GPL-2.0-or-later
 * Text Domain: wpr-static
 * Update URI: https://xn----ctbgf6biedka.xn--p1ai/plugins/wpr-static/
 */
if (!defined('ABSPATH')) exit;
define('WPR_STATIC_VERSION', '1.1.0');
define('WPR_STATIC_FILE', __FILE__);
require_once __DIR__.'/includes/wpr-updates-client.php';
WPR_Updates_Client_V2::register(__FILE__, ['slug'=>'wpr-static','server'=>'https://xn----ctbgf6biedka.xn--p1ai','telemetry'=>false]);
foreach (['urls','storage','serve','direct','frontend','engine','admin','cli'] as $part) require_once __DIR__.'/includes/'.$part.'.php';
register_activation_hook(__FILE__, ['WPR_Static\Engine','install']);
register_deactivation_hook(__FILE__, ['WPR_Static\Engine','deactivate']);
add_action('plugins_loaded', ['WPR_Static\Engine','upgrade']);
add_action('wpr_static_tick', ['WPR_Static\Engine','cron']);
add_filter('cron_schedules', static function($s){$s['wpr_static_minute']=['interval'=>60,'display'=>'WPR Static (1 min)'];return $s;});
WPR_Static\Frontend::boot();
WPR_Static\Admin::init();
if (defined('WP_CLI') && WP_CLI) WP_CLI::add_command('wpr-static', 'WPR_Static\CLI');
