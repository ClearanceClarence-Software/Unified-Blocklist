<?php
/**
 * Sync scheduling.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist;

defined( 'ABSPATH' ) || exit;

/**
 * Uses only the schedules WordPress ships with, so nothing is redefined.
 */
final class Schedule {

	/**
	 * Replaces the recurring sync event with one at the configured interval.
	 */
	public static function reschedule() {
		$interval = (string) Settings::get( 'interval' );

		// Clear only recurring events; a pending one-off first sync is kept.
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( empty( $hooks[ Plugin::SYNC_HOOK ] ) ) {
				continue;
			}
			foreach ( $hooks[ Plugin::SYNC_HOOK ] as $event ) {
				if ( ! empty( $event['schedule'] ) ) {
					wp_unschedule_event( (int) $timestamp, Plugin::SYNC_HOOK, (array) $event['args'] );
				}
			}
		}

		$schedules = wp_get_schedules();
		$delay     = isset( $schedules[ $interval ] ) ? (int) $schedules[ $interval ]['interval'] : DAY_IN_SECONDS;
		wp_schedule_event( time() + $delay, $interval, Plugin::SYNC_HOOK );
	}

	/**
	 * Makes sure the recurring events exist. Runs on every load (cheap: the cron array is
	 * autoloaded), so network-activated sites and sites with a lost cron array heal themselves.
	 */
	public static function ensure() {
		$has_recurring = false;
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( empty( $hooks[ Plugin::SYNC_HOOK ] ) ) {
				continue;
			}
			foreach ( $hooks[ Plugin::SYNC_HOOK ] as $event ) {
				if ( ! empty( $event['schedule'] ) ) {
					$has_recurring = true;
					break 2;
				}
			}
		}

		if ( ! $has_recurring ) {
			self::reschedule();
			if ( ! get_option( 'unified_blocklist_last_sync' ) ) {
				// Never synced: run the first sync on the next cron tick instead of after a full interval.
				wp_schedule_single_event( time(), Plugin::SYNC_HOOK );
			}
		}

		if ( ! wp_next_scheduled( Plugin::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Plugin::PRUNE_HOOK );
		}
	}

	/**
	 * Timestamp of the next recurring sync.
	 *
	 * @return int|false
	 */
	public static function next() {
		$crons = _get_cron_array();
		$found = false;
		foreach ( (array) $crons as $timestamp => $hooks ) {
			if ( empty( $hooks[ Plugin::SYNC_HOOK ] ) ) {
				continue;
			}
			if ( false === $found || $timestamp < $found ) {
				$found = (int) $timestamp;
			}
		}
		return $found;
	}
}
