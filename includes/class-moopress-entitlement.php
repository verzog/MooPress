<?php
// This file is part of MooPress, a WordPress and Moodle bridge.
//
// MooPress is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// MooPress is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with MooPress.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Reconcile a user's Moodle enrolments to the courses they are entitled to.
 *
 * Entitlement sources (paid WooCommerce orders, membership) each add course ids
 * through the "moopress_entitled_courses" filter; the engine takes the union and
 * enrols the user in the courses they should have and suspends the ones MooPress
 * granted but they no longer qualify for. It tracks the courses it manages per
 * user so it never touches enrolments made by other means. A daily sweep catches
 * lapses, and a mass-change guard holds back a run that would suspend an unusually
 * large number of enrolments at once.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Computes entitlements and reconciles Moodle enrolments to match.
 */
class Moopress_Entitlement {

	/** User meta key listing the course ids MooPress has actively enrolled. */
	const MANAGED_META = 'moopress_managed_courses';

	/** Background hook: reconcile one user (arg: WordPress user ID). */
	const RECONCILE_USER_HOOK = 'moopress_reconcile_user';

	/** Daily hook: reconcile every candidate user. */
	const RECONCILE_ALL_HOOK = 'moopress_reconcile_all';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::RECONCILE_USER_HOOK, [ __CLASS__, 'reconcile_user' ], 10, 1 );
		add_action( self::RECONCILE_ALL_HOOK, [ __CLASS__, 'reconcile_all' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'register_woocommerce' ], 20 );
	}

	/**
	 * Register the WooCommerce order hooks, if WooCommerce is active.
	 *
	 * @return void
	 */
	public static function register_woocommerce(): void {
		if ( ! Moopress_Enrolment::woocommerce_active() ) {
			return;
		}
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_paid' ] );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'on_order_paid' ] );
		add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'on_order_changed' ] );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'on_order_changed' ] );
	}

	/**
	 * Ensure the daily reconcile is scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::RECONCILE_ALL_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RECONCILE_ALL_HOOK );
		}
	}

	/**
	 * Snapshot the order's courses and reconcile the buyer, when an order is paid.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function on_order_paid( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		Moopress_Source_Purchase::snapshot_order( $order );
		self::schedule_reconcile( (int) $order->get_user_id() );
	}

	/**
	 * Reconcile the buyer when an order is refunded or cancelled.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function on_order_changed( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::schedule_reconcile( (int) $order->get_user_id() );
	}

	/**
	 * Queue a background reconcile for a user.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return void
	 */
	public static function schedule_reconcile( int $wp_user_id ): void {
		if ( $wp_user_id <= 0 || ! Moopress_Settings::get( 'enrol_enabled' ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::RECONCILE_USER_HOOK, [ $wp_user_id ] );
	}

	/**
	 * The union of courses a user is entitled to, across all sources.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return int[]
	 */
	public static function desired_courses( int $wp_user_id ): array {
		$courses = apply_filters( 'moopress_entitled_courses', [], $wp_user_id );
		$courses = is_array( $courses ) ? array_map( 'intval', $courses ) : [];
		return array_values( array_unique( array_filter( $courses ) ) );
	}

	/**
	 * The course ids MooPress has actively enrolled the user in.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return int[]
	 */
	public static function managed_courses( int $wp_user_id ): array {
		$ids = get_user_meta( $wp_user_id, self::MANAGED_META, true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/**
	 * The enrol and suspend changes a user needs, without applying them.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array{enrol:int[], suspend:int[]}
	 */
	public static function plan_user( int $wp_user_id ): array {
		$desired = self::desired_courses( $wp_user_id );
		$managed = self::managed_courses( $wp_user_id );
		return [
			'enrol'   => array_values( array_diff( $desired, $managed ) ),
			'suspend' => array_values( array_diff( $managed, $desired ) ),
		];
	}

	/**
	 * Apply a plan: enrol and suspend, and track the managed courses.
	 *
	 * In a dry run the enrolments are logged but nothing is written to Moodle, so
	 * the managed list is left unchanged to avoid drifting from the real state.
	 *
	 * @param int   $wp_user_id WordPress user ID.
	 * @param array $plan       {enrol:int[], suspend:int[]}.
	 * @return void
	 */
	public static function apply_plan( int $wp_user_id, array $plan ): void {
		$dry           = (bool) Moopress_Settings::get( 'dry_run' );
		$allow_suspend = self::sources_available();
		$managed       = self::managed_courses( $wp_user_id );
		foreach ( $plan['enrol'] as $course_id ) {
			if ( Moopress_Enrolment::enrol( $wp_user_id, (int) $course_id ) && ! $dry ) {
				$managed[] = (int) $course_id;
			}
		}
		if ( $allow_suspend ) {
			foreach ( $plan['suspend'] as $course_id ) {
				if ( Moopress_Enrolment::suspend( $wp_user_id, (int) $course_id ) && ! $dry ) {
					$managed = array_diff( $managed, [ (int) $course_id ] );
				}
			}
		} elseif ( [] !== $plan['suspend'] ) {
			// A source is down, so an empty entitlement result may be wrong: keep access.
			Moopress_Logger::record_call(
				'reconcile',
				Moopress_Logger::STATUS_ERROR,
				__( 'Suspensions held back: an entitlement source (WooCommerce) is unavailable.', 'moopress' )
			);
		}
		if ( ! $dry ) {
			self::save_managed( $wp_user_id, $managed );
		}
	}

	/**
	 * Whether every entitlement source can currently be read.
	 *
	 * When a source is down, an empty result from it is not a true "no longer
	 * entitled", so the engine holds back suspensions until it is back.
	 *
	 * @return bool
	 */
	public static function sources_available(): bool {
		return Moopress_Source_Purchase::is_available();
	}

	/**
	 * Store the managed-course list (or clear it when empty).
	 *
	 * @param int   $wp_user_id WordPress user ID.
	 * @param array $managed    Course ids.
	 * @return void
	 */
	private static function save_managed( int $wp_user_id, array $managed ): void {
		$managed = array_values( array_unique( array_map( 'intval', $managed ) ) );
		if ( [] !== $managed ) {
			update_user_meta( $wp_user_id, self::MANAGED_META, $managed );
		} else {
			delete_user_meta( $wp_user_id, self::MANAGED_META );
		}
	}

	/**
	 * Reconcile one user (background).
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return void
	 */
	public static function reconcile_user( $wp_user_id ): void {
		$wp_user_id = (int) $wp_user_id;
		if ( $wp_user_id <= 0 || ! Moopress_Settings::get( 'enrol_enabled' ) ) {
			return;
		}
		self::apply_plan( $wp_user_id, self::plan_user( $wp_user_id ) );
	}

	/**
	 * Reconcile every candidate user (daily), unless the mass-change guard trips.
	 *
	 * @return void
	 */
	public static function reconcile_all(): void {
		if ( ! Moopress_Settings::get( 'enrol_enabled' ) ) {
			return;
		}
		$plans    = [];
		$suspends = 0;
		foreach ( self::candidate_users() as $wp_user_id ) {
			$plan = self::plan_user( $wp_user_id );
			if ( [] !== $plan['enrol'] || [] !== $plan['suspend'] ) {
				$plans[ $wp_user_id ] = $plan;
				$suspends            += count( $plan['suspend'] );
			}
		}
		$max = max( 0, (int) Moopress_Settings::get( 'reconcile_max_suspends', 50 ) );
		if ( $suspends > $max ) {
			Moopress_Logger::record_call(
				'reconcile',
				Moopress_Logger::STATUS_ERROR,
				sprintf(
					/* translators: 1: number of suspensions, 2: the configured limit. */
					__( 'Reconcile held back: it would suspend %1$d enrolments (limit %2$d). Raise the limit or fix the cause, then run it again.', 'moopress' ),
					$suspends,
					$max
				)
			);
			return;
		}
		foreach ( $plans as $wp_user_id => $plan ) {
			self::apply_plan( (int) $wp_user_id, $plan );
		}
	}

	/**
	 * The users a sweep considers: those MooPress manages, plus current members.
	 *
	 * @return int[]
	 */
	public static function candidate_users(): array {
		$ids = [];
		foreach ( get_users(
			[
				'meta_key' => self::MANAGED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Daily cron sweep over managed users.
				'fields'   => 'ID',
			]
		) as $id ) {
			$ids[ (int) $id ] = (int) $id;
		}
		if ( Moopress_Settings::get( 'membership_enabled' ) ) {
			$role = (string) Moopress_Settings::get( 'membership_role' );
			if ( '' !== $role ) {
				foreach ( get_users(
					[
						'role'   => $role,
						'fields' => 'ID',
					]
				) as $id ) {
					$ids[ (int) $id ] = (int) $id;
				}
			}
		}
		// Buyers with a paid order, so a purchase whose first reconcile was missed
		// (dry run, enrolment off, a Moodle outage) is still picked up and tracked.
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				[
					'status' => [ 'completed', 'processing' ],
					'limit'  => -1,
				]
			);
			foreach ( $orders as $order ) {
				$uid = is_object( $order ) && method_exists( $order, 'get_user_id' ) ? (int) $order->get_user_id() : 0;
				if ( $uid > 0 ) {
					$ids[ $uid ] = $uid;
				}
			}
		}
		return array_values( $ids );
	}
}
