# 10T Schedule Post Updates

Version 1.0.1 provides scheduled unpublishing for standard WordPress posts. Requires WordPress 6.6+ and PHP 8.2+. No dependencies or updater code is bundled.

**No warranty:** This software is provided without any warranty, including the implied warranties of merchantability or fitness for a particular purpose. See [LICENSE](LICENSE) for the full terms.

## Usage

1. Install this directory under `wp-content/plugins/` and activate the plugin.
2. Open a post in the block or classic editor and find **Scheduled unpublish**.
3. Choose a future date and time, then save or update the post. Times use the WordPress site timezone under Settings → General.
4. WordPress moves the post to **Draft** when the scheduled cron event runs. Clear the field and save to cancel, or enter another time to reschedule.

Changing schedules requires permission to edit the post and publish posts. Scheduling is also allowed before publication; the post must be published when the event runs. Draft, pending, private, future, missing, and trashed posts are left alone. Expiration is one-shot; publishing the post again requires a new schedule. Trashing or permanently deleting a post clears its event.

WP-Cron runs on site traffic, so an expiration may run late. Configure a real system cron to invoke WordPress cron if timing matters. The plugin must remain active until execution: events that come due while inactive may be discarded by WordPress and are not restored on reactivation. Events still in the future survive deactivation. Uninstalling does not delete post content; remaining cron entries naturally expire. WP-Cron itself is the schedule store; there is no separate post metadata table or setting.

Nonexistent local times during a spring DST transition are rejected. For repeated local times during the autumn transition, PHP selects the offset. Avoid that repeated hour if a specific offset matters. Existing schedules represent fixed instants and do not shift if the site timezone changes. Scheduling writes use WordPress's cron storage and do not guarantee atomic behavior across concurrent edits; avoid editing the same post in multiple sessions.

Schedules are site-local on Multisite. Network activation and the supported version matrix have not been integration-tested.

Licensed under GPL-2.0-or-later, as declared in the plugin header.
