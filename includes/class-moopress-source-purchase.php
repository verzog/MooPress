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
 * Entitlement source: a buyer's paid WooCommerce orders grant their mapped courses.
 *
 * The courses an order granted are snapshotted on the order when it is first paid,
 * so a later change to a product's course mapping does not change what an existing
 * order granted. A refunded or cancelled order drops out of the paid-orders set and
 * so stops granting its courses.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grants the courses of a buyer's paid orders.
 */
class Moopress_Source_Purchase {

	/** Order meta key snapshotting the courses the order granted. */
	const SNAPSHOT_META = '_moopress_courses';

	/**
	 * Register the entitlement filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'moopress_entitled_courses', [ __CLASS__, 'add' ], 10, 2 );
	}

	/**
	 * Add the courses granted by a buyer's paid orders.
	 *
	 * @param array $courses    Course ids gathered so far.
	 * @param int   $wp_user_id WordPress user ID.
	 * @return array
	 */
	public static function add( array $courses, int $wp_user_id ): array {
		return array_merge( $courses, self::courses_for_user( $wp_user_id ) );
	}

	/**
	 * The distinct courses granted by a user's completed or processing orders.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return int[]
	 */
	public static function courses_for_user( int $wp_user_id ): array {
		if ( $wp_user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}
		$orders = wc_get_orders(
			[
				'customer_id' => $wp_user_id,
				'status'      => [ 'completed', 'processing' ],
				'limit'       => -1,
			]
		);
		$ids    = [];
		foreach ( $orders as $order ) {
			foreach ( self::order_courses( $order ) as $course_id ) {
				$ids[ $course_id ] = $course_id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * The courses an order granted: its snapshot, or the current product mapping.
	 *
	 * @param object $order A WooCommerce order.
	 * @return int[]
	 */
	public static function order_courses( $order ): array {
		if ( method_exists( $order, 'get_meta' ) ) {
			$snapshot = $order->get_meta( self::SNAPSHOT_META );
			if ( is_array( $snapshot ) && [] !== $snapshot ) {
				return array_map( 'intval', $snapshot );
			}
		}
		return Moopress_Enrolment::course_ids_for_order( $order );
	}

	/**
	 * Snapshot the granted course ids on an order the first time it is paid.
	 *
	 * @param object $order A WooCommerce order.
	 * @return void
	 */
	public static function snapshot_order( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$existing = $order->get_meta( self::SNAPSHOT_META );
		if ( is_array( $existing ) && [] !== $existing ) {
			return;
		}
		$order->update_meta_data( self::SNAPSHOT_META, Moopress_Enrolment::course_ids_for_order( $order ) );
		$order->save();
	}
}
