<?php
/** Isolated behavioral checks; no WordPress database is loaded. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-plugin.php';

use TenT\SchedulePostUpdates\Plugin;

class WP_Error {}
$events = array();
$posts = array();
$notices = array();
$allowed = true;
$valid_nonce = true;
$fail_schedule = false;
$fail_clear = false;
$fail_update = false;
$updates = 0;
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = '' ) { return $value; }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function wp_verify_nonce( $nonce, $action ) { return $GLOBALS['valid_nonce']; }
function wp_unslash( $value ) { return stripslashes( $value ); }
function current_user_can( ...$args ) { return $GLOBALS['allowed']; }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function get_current_user_id() { return 1; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['notices'][ $key ] = $value; }
function wp_next_scheduled( $hook, $args ) { return $GLOBALS['events'][ $args[0] ] ?? false; }
function wp_clear_scheduled_hook( $hook, $args, $error = false ) {
	if ( $GLOBALS['fail_clear'] ) { return new WP_Error(); }
	unset( $GLOBALS['events'][ $args[0] ] );
	return 1;
}
function wp_schedule_single_event( $time, $hook, $args, $error = false ) {
	if ( $GLOBALS['fail_schedule'] ) { $GLOBALS['fail_schedule'] = false; return new WP_Error(); }
	$GLOBALS['events'][ $args[0] ] = $time;
	return true;
}
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function wp_update_post( $data, $error = false ) {
	if ( $GLOBALS['fail_update'] ) { return new WP_Error(); }
	++$GLOBALS['updates'];
	$GLOBALS['posts'][ $data['ID'] ]->post_status = $data['post_status'];
	return $data['ID'];
}
function check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: $message\n";
}
function save_time( $value ) {
	$_POST = array( 'tent_unpublish_nonce' => 'test', Plugin::FIELD => $value );
	$GLOBALS['plugin']->save( 1, $GLOBALS['posts'][1] );
}
$plugin = new Plugin();
$posts[1] = (object) array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish' );
$zone = wp_timezone();
check( false === Plugin::parse_date( '2030-02-30T12:00', $zone ), 'Impossible date rejected' );
check( false === Plugin::parse_date( '2030-03-10T02:30', $zone ), 'DST gap rejected' );
check( false === Plugin::parse_date( '2030-01-01T12:00junk', $zone ), 'Trailing input rejected' );
check( false === Plugin::parse_date( "2030-01-01T12:00\0", $zone ), 'Null byte input rejected without exception' );
check( strtotime( '2030-01-01T17:00:00Z' ) === Plugin::parse_date( '2030-01-01T12:00', $zone ), 'Site timezone converted to UTC' );
$future = ( new DateTimeImmutable( '+2 days', $zone ) )->format( 'Y-m-d\TH:i' );
$later = ( new DateTimeImmutable( '+3 days', $zone ) )->format( 'Y-m-d\TH:i' );
save_time( $future );
$original = $events[1];
check( $original === Plugin::parse_date( $future, $zone ), 'Future schedule saved' );
save_time( '2000-01-01T00:00' );
check( $original === $events[1] && count( $notices ) > 0, 'Past date preserves schedule and reports error' );
$allowed = false;
save_time( '' );
check( $original === $events[1], 'Unauthorized cancellation rejected' );
$allowed = true;
$valid_nonce = false;
save_time( '' );
check( $original === $events[1], 'Invalid nonce rejected' );
$valid_nonce = true;
$_POST = array();
$plugin->save( 1, $posts[1] );
check( $original === $events[1], 'REST or quick edit without fields preserves schedule' );
$fail_schedule = true;
save_time( $later );
check( $original === $events[1], 'Failed replacement restores previous schedule' );
$fail_clear = true;
save_time( $later );
check( $original === $events[1], 'Failed clearing leaves schedule untouched' );
$fail_clear = false;
save_time( $later );
check( $events[1] === Plugin::parse_date( $later, $zone ), 'Reschedule replaces timestamp' );
$plugin->unpublish( 1 );
check( 'publish' === $posts[1]->post_status, 'Queued stale callback respects newer future schedule' );
save_time( '' );
check( ! isset( $events[1] ), 'Blank field cancels schedule' );
save_time( $future );
// Core removes a single event before invoking its callback.
unset( $events[1] );
$plugin->unpublish( 1 );
check( 'draft' === $posts[1]->post_status && 1 === $updates, 'Due published post becomes draft' );
$plugin->unpublish( 1 );
check( 1 === $updates, 'Repeated callback leaves draft unchanged' );
foreach ( array( 'private', 'trash', 'future', 'pending' ) as $status ) {
	$posts[1]->post_status = $status;
	$plugin->unpublish( 1 );
	check( $status === $posts[1]->post_status, "$status post left unchanged" );
}
$posts[1]->post_type = 'page';
$posts[1]->post_status = 'publish';
$plugin->unpublish( 1 );
check( 'publish' === $posts[1]->post_status, 'Other post types left unchanged' );
$posts[1]->post_type = 'post';
$fail_update = true;
$plugin->unpublish( 1 );
check( abs( $events[1] - time() - 300 ) < 2, 'Failed status update schedules retry' );
$plugin->clear( 1 );
check( ! isset( $events[1] ), 'Deletion and trash cleanup removes event' );
$plugin->unpublish( 999 );
check( 1 === $updates, 'Deleted post callback is harmless' );
echo "All scheduling checks passed.\n";
