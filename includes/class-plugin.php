<?php
/** Post expiration controls and WP-Cron callbacks. */

namespace TenT\SchedulePostUpdates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	const HOOK = 'tent_scheduled_post_unpublish';
	const FIELD = 'tent_unpublish_at';

	public function register_hooks() {
		add_action( 'add_meta_boxes_post', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_post', array( $this, 'save' ), 10, 2 );
		add_action( self::HOOK, array( $this, 'unpublish' ) );
		add_action( 'before_delete_post', array( $this, 'clear' ) );
		add_action( 'trashed_post', array( $this, 'clear' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function add_meta_box( $post ) {
		if ( current_user_can( 'publish_posts' ) && current_user_can( 'edit_post', $post->ID ) ) {
			add_meta_box( 'tent-unpublish', __( 'Scheduled unpublish', 'wordpress-schedule-post-updates' ), array( $this, 'render' ), 'post', 'side' );
		}
	}

	public function render( $post ) {
		$timestamp = wp_next_scheduled( self::HOOK, array( (int) $post->ID ) );
		$value = $timestamp ? wp_date( 'Y-m-d\TH:i', $timestamp, wp_timezone() ) : '';
		wp_nonce_field( 'tent_unpublish_' . $post->ID, 'tent_unpublish_nonce' );
		?>
		<p><label for="tent-unpublish-at"><?php esc_html_e( 'Move to Draft at:', 'wordpress-schedule-post-updates' ); ?></label></p>
		<input type="datetime-local" id="tent-unpublish-at" name="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( $value ); ?>" style="max-width:100%" />
		<p><?php echo esc_html( sprintf( __( 'Timezone: %s. Leave blank to cancel. Save or update the post to apply.', 'wordpress-schedule-post-updates' ), wp_timezone_string() ) ); ?></p>
		<p><?php esc_html_e( 'Only published posts are moved to Draft. Execution depends on WordPress cron running after the selected time.', 'wordpress-schedule-post-updates' ); ?></p>
		<?php
	}

	/** Reject invalid dates and DST gaps rather than silently changing the requested time. */
	public static function parse_date( $value, $timezone ) {
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, $timezone );
		return $date && $date->format( 'Y-m-d\TH:i' ) === $value ? $date->getTimestamp() : false;
	}

	public function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['tent_unpublish_nonce'], $_POST[ self::FIELD ] ) || ! is_string( $_POST['tent_unpublish_nonce'] ) || ! is_string( $_POST[ self::FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( wp_unslash( $_POST['tent_unpublish_nonce'] ), 'tent_unpublish_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'publish_posts' ) ) {
			return;
		}
		$value = trim( wp_unslash( $_POST[ self::FIELD ] ) );
		if ( '' === $value ) {
			if ( is_wp_error( $this->clear( $post_id ) ) ) {
				$this->error( __( 'Unable to cancel the unpublish schedule. Please try again.', 'wordpress-schedule-post-updates' ) );
			}
			return;
		}
		$timestamp = self::parse_date( $value, wp_timezone() );
		$old = wp_next_scheduled( self::HOOK, array( (int) $post_id ) );
		// An unchanged overdue schedule must still be allowed to run.
		if ( $old && $old === $timestamp ) {
			return;
		}
		if ( false === $timestamp || $timestamp <= time() || 'trash' === $post->post_status ) {
			$this->error( __( 'Choose a valid future unpublish time. The previous schedule was kept.', 'wordpress-schedule-post-updates' ) );
			return;
		}
		// Remove first: WordPress rejects nearby events with identical arguments.
		if ( is_wp_error( $this->clear( $post_id ) ) ) {
			$this->error( __( 'Unable to change the unpublish schedule. Please try again.', 'wordpress-schedule-post-updates' ) );
			return;
		}
		$result = wp_schedule_single_event( $timestamp, self::HOOK, array( (int) $post_id ), true );
		if ( is_wp_error( $result ) || ! $result ) {
			$restored = $old ? wp_schedule_single_event( $old, self::HOOK, array( (int) $post_id ), true ) : false;
			$this->error( $restored === true
				? __( 'Unable to save the new time. The previous schedule was restored.', 'wordpress-schedule-post-updates' )
				: __( 'Unable to save an unpublish schedule. Please try again.', 'wordpress-schedule-post-updates' ) );
		}
	}

	public function clear( $post_id ) {
		return wp_clear_scheduled_hook( self::HOOK, array( (int) $post_id ), true );
	}

	public function unpublish( $post_id ) {
		// A replacement scheduled while a previous callback was queued takes priority.
		$next = wp_next_scheduled( self::HOOK, array( (int) $post_id ) );
		if ( $next && $next > time() ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $result ) ) {
			wp_schedule_single_event( time() + 300, self::HOOK, array( (int) $post_id ), true );
		}
	}

	private function error( $message ) {
		set_transient( 'tent_unpublish_error_' . get_current_user_id(), $message, 120 );
	}

	public function notice() {
		$key = 'tent_unpublish_error_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}
