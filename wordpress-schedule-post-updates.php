<?php
/**
 * Plugin Name: 10T Schedule Post Updates
 * Description: Schedule a future date and time to move published posts to Draft.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Plugin URI: https://github.com/brock10t/wordpress-schedule-post-updates
 * Update URI: https://github.com/brock10t/wordpress-schedule-post-updates
 * Text Domain: wordpress-schedule-post-updates
 * License: GPL-2.0-or-later
 */

namespace TenT\SchedulePostUpdates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.2', '<' ) || version_compare( $GLOBALS['wp_version'], '6.6', '<' ) ) {
	return;
}

require_once __DIR__ . '/includes/class-plugin.php';
( new Plugin() )->register_hooks();
