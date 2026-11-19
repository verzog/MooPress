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
 * Enrol WooCommerce buyers into the Moodle courses their purchase grants.
 *
 * A WooCommerce product is mapped to a Moodle course (the product's
 * "_moopress_course_id" meta). When an order is paid, the buyer is enrolled in
 * each mapped course in the background; when it is refunded or cancelled, they
 * are suspended (not unenrolled, so grades and completion survive). A buyer with
 * no matched Moodle user is provisioned first when that is switched on.
 *
 * Nothing is written to Moodle unless enrolment is enabled and dry run is off.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps products to courses and enrols or suspends buyers in Moodle.
 */
class Moopress_Enrolment {

	/** The product meta key holding the mapped Moodle course id. */
	const COURSE_META = '_moopress_course_id';

	/** Background hook that processes one order (args: order id, suspend flag). */
	const PROCESS_HOOK = 'moopress_process_order';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::PROCESS_HOOK, [ __CLASS__, 'process_order' ], 10, 2 );
		// WooCommerce may load after MooPress, so register its hooks once every
		// plugin is loaded rather than during this plugin's own include.
		add_action( 'plugins_loaded', [ __CLASS__, 'register_woocommerce' ], 20 );
	}

	/**
	 * Register the WooCommerce order and product hooks, if WooCommerce is active.
	 *
	 * @return void
	 */
	public static function register_woocommerce(): void {
		if ( ! self::woocommerce_active() ) {
			return;
		}
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'schedule_enrol' ] );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'schedule_enrol' ] );
		add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'schedule_suspend' ] );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'schedule_suspend' ] );
		add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'product_field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_product_field' ] );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Queue a background enrolment for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function schedule_enrol( $order_id ): void {
		if ( ! Moopress_Settings::get( 'enrol_enabled' ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::PROCESS_HOOK, [ (int) $order_id, false ] );
	}

	/**
	 * Queue a background suspension for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function schedule_suspend( $order_id ): void {
		if ( ! Moopress_Settings::get( 'enrol_enabled' ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::PROCESS_HOOK, [ (int) $order_id, true ] );
	}

	/**
	 * Enrol or suspend the order's buyer in each mapped course (background).
	 *
	 * @param int  $order_id WooCommerce order ID.
	 * @param bool $suspend  Suspend rather than enrol.
	 * @return void
	 */
	public static function process_order( int $order_id, bool $suspend = false ): void {
		// Re-check at execution: the toggle may have been turned off since the job was queued.
		if ( ! Moopress_Settings::get( 'enrol_enabled' ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$wp_user_id = (int) $order->get_user_id();
		if ( $wp_user_id <= 0 ) {
			Moopress_Logger::record_call( 'order', Moopress_Logger::STATUS_SUCCESS, 'Guest order skipped: no WordPress user.' );
			return;
		}
		foreach ( self::course_ids_for_order( $order ) as $course_id ) {
			if ( $suspend && self::course_granted_by_other_order( $wp_user_id, $course_id, $order_id ) ) {
				Moopress_Logger::record_call(
					'suspend',
					Moopress_Logger::STATUS_SUCCESS,
					sprintf(
						/* translators: %d: Moodle course id. */
						__( 'Kept enrolment in course %d: another paid order still grants it.', 'moopress' ),
						$course_id
					)
				);
				continue;
			}
			self::write_enrolment( $wp_user_id, $course_id, $suspend );
		}
	}

	/**
	 * Whether a paid order other than the given one still grants the course.
	 *
	 * @param int $wp_user_id       WordPress user ID.
	 * @param int $course_id        Moodle course id.
	 * @param int $exclude_order_id The order being refunded or cancelled.
	 * @return bool
	 */
	private static function course_granted_by_other_order( int $wp_user_id, int $course_id, int $exclude_order_id ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$orders = wc_get_orders(
			[
				'customer_id' => $wp_user_id,
				'status'      => [ 'completed', 'processing' ],
				'exclude'     => [ $exclude_order_id ],
				'limit'       => -1,
			]
		);
		foreach ( $orders as $order ) {
			if ( in_array( $course_id, self::course_ids_for_order( $order ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The distinct Moodle course ids mapped to an order's line items.
	 *
	 * @param object $order A WooCommerce order (anything with get_items()).
	 * @return int[]
	 */
	public static function course_ids_for_order( $order ): array {
		$ids = [];
		foreach ( $order->get_items() as $item ) {
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$course_id    = self::course_for_product( $variation_id > 0 ? $variation_id : $product_id );
			if ( $course_id > 0 ) {
				$ids[ $course_id ] = $course_id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * The Moodle course id a product grants, falling back to its parent product.
	 *
	 * @param int $product_id WooCommerce product or variation ID.
	 * @return int The course id, or 0 when the product is not mapped.
	 */
	public static function course_for_product( int $product_id ): int {
		$value = get_post_meta( $product_id, self::COURSE_META, true );
		if ( '' === (string) $value ) {
			$parent = (int) wp_get_post_parent_id( $product_id );
			if ( $parent > 0 ) {
				$value = get_post_meta( $parent, self::COURSE_META, true );
			}
		}
		return max( 0, (int) $value );
	}

	/**
	 * Enrol a WordPress user in a Moodle course.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @param int $course_id  Moodle course id.
	 * @return bool Whether the enrolment was made (or would be, in a dry run).
	 */
	public static function enrol( int $wp_user_id, int $course_id ): bool {
		return self::write_enrolment( $wp_user_id, $course_id, false );
	}

	/**
	 * Suspend a WordPress user's enrolment in a Moodle course.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @param int $course_id  Moodle course id.
	 * @return bool Whether the suspension was made (or would be, in a dry run).
	 */
	public static function suspend( int $wp_user_id, int $course_id ): bool {
		return self::write_enrolment( $wp_user_id, $course_id, true );
	}

	/**
	 * Enrol or suspend a user in a course, provisioning the Moodle user if needed.
	 *
	 * @param int  $wp_user_id WordPress user ID.
	 * @param int  $course_id  Moodle course id.
	 * @param bool $suspend    Suspend rather than enrol.
	 * @return bool
	 */
	private static function write_enrolment( int $wp_user_id, int $course_id, bool $suspend ): bool {
		$dry       = (bool) Moopress_Settings::get( 'dry_run' );
		$action    = $suspend ? 'suspend' : 'enrol';
		$lookup    = Moopress_User_Match::lookup( $wp_user_id );
		$moodle_id = $lookup['id'];
		if ( null === $moodle_id ) {
			if ( ! Moopress_Settings::get( 'provision_users' ) ) {
				Moopress_Logger::record_call(
					$action,
					Moopress_Logger::STATUS_ERROR,
					sprintf(
						/* translators: %d: WordPress user ID. */
						__( 'No Moodle user for WordPress user %d and provisioning is off; skipped.', 'moopress' ),
						$wp_user_id
					)
				);
				return false;
			}
			if ( Moopress_User_Match::OUTCOME_NONE !== $lookup['outcome'] ) {
				// Only a definitive "no such user" is safe to provision: an ambiguous
				// match or a failed lookup must not create a (possibly duplicate) account.
				Moopress_Logger::record_call(
					$action,
					Moopress_Logger::STATUS_ERROR,
					sprintf(
						/* translators: 1: WordPress user ID, 2: the lookup outcome. */
						__( 'Not provisioning WordPress user %1$d: the Moodle lookup was "%2$s".', 'moopress' ),
						$wp_user_id,
						$lookup['outcome']
					)
				);
				return false;
			}
			if ( $dry ) {
				Moopress_Logger::record_call(
					$action,
					Moopress_Logger::STATUS_SUCCESS,
					sprintf(
						/* translators: 1: WordPress user ID, 2: Moodle course id. */
						__( 'Dry run: would create a Moodle user for WordPress user %1$d and enrol them in course %2$d.', 'moopress' ),
						$wp_user_id,
						$course_id
					)
				);
				return true;
			}
			$moodle_id = self::provision( $wp_user_id );
			if ( null === $moodle_id ) {
				return false;
			}
		}
		if ( $dry ) {
			Moopress_Logger::record_call(
				$action,
				Moopress_Logger::STATUS_SUCCESS,
				sprintf(
					/* translators: 1: enrol or suspend, 2: Moodle user id, 3: Moodle course id. */
					__( 'Dry run: would %1$s Moodle user %2$d in course %3$d.', 'moopress' ),
					$action,
					$moodle_id,
					$course_id
				)
			);
			return true;
		}
		return self::send_enrolment( $moodle_id, $course_id, $suspend );
	}

	/**
	 * Call Moodle's manual enrolment, enrolling (suspend 0) or suspending (suspend 1).
	 *
	 * @param int  $moodle_id Moodle user id.
	 * @param int  $course_id Moodle course id.
	 * @param bool $suspend   Suspend rather than enrol.
	 * @return bool
	 */
	private static function send_enrolment( int $moodle_id, int $course_id, bool $suspend ): bool {
		$roleid = max( 1, (int) Moopress_Settings::get( 'student_roleid', 5 ) );
		try {
			( new Moopress_Rest_Client() )->call(
				'enrol_manual_enrol_users',
				[
					'enrolments' => [
						[
							'roleid'   => $roleid,
							'userid'   => $moodle_id,
							'courseid' => $course_id,
							'suspend'  => $suspend ? 1 : 0,
						],
					],
				]
			);
		} catch ( Moopress_Exception $e ) {
			unset( $e );
			return false;
		}
		return true;
	}

	/**
	 * Create a Moodle user from a WordPress account and cache the new id.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return int|null The new Moodle user id, or null on failure.
	 */
	private static function provision( int $wp_user_id ): ?int {
		$user = get_userdata( $wp_user_id );
		if ( ! $user ) {
			return null;
		}
		$first   = '' !== $user->first_name ? $user->first_name : $user->display_name;
		$last    = '' !== $user->last_name ? $user->last_name : $user->display_name;
		$newuser = [
			'username'  => strtolower( $user->user_login ),
			'email'     => $user->user_email,
			'firstname' => '' !== $first ? $first : $user->user_login,
			'lastname'  => '' !== $last ? $last : $user->user_login,
			'auth'      => (string) Moopress_Settings::get( 'provision_auth', 'manual' ),
			'password'  => wp_generate_password( 24, true, true ),
		];
		if ( 'idnumber' === Moopress_Settings::get( 'match_by' ) ) {
			$key      = (string) Moopress_Settings::get( 'match_meta_key', 'moopress_idnumber' );
			$idnumber = '' !== $key ? (string) get_user_meta( $wp_user_id, $key, true ) : '';
			if ( '' !== $idnumber ) {
				$newuser['idnumber'] = $idnumber;
			}
		}
		try {
			$created = ( new Moopress_Rest_Client() )->call( 'core_user_create_users', [ 'users' => [ $newuser ] ] );
		} catch ( Moopress_Exception $e ) {
			unset( $e );
			return null;
		}
		if ( is_array( $created ) && isset( $created[0]['id'] ) ) {
			$moodle_id = (int) $created[0]['id'];
			update_user_meta( $wp_user_id, Moopress_User_Match::META_MOODLE_ID, $moodle_id );
			return $moodle_id;
		}
		return null;
	}

	/**
	 * Render the "Moodle course" field on the WooCommerce product edit screen.
	 *
	 * @return void
	 */
	public static function product_field(): void {
		woocommerce_wp_text_input(
			[
				'id'                => self::COURSE_META,
				'label'             => __( 'Moodle course id', 'moopress' ),
				'description'       => __( 'Buyers of this product are enrolled in the Moodle course with this id.', 'moopress' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => [ 'min' => '0' ],
			]
		);
	}

	/**
	 * Save the "Moodle course" product field (WooCommerce verifies the nonce).
	 *
	 * @param int $post_id Product post ID.
	 * @return void
	 */
	public static function save_product_field( $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-save nonce before this fires.
		$value = isset( $_POST[ self::COURSE_META ] ) ? absint( wp_unslash( $_POST[ self::COURSE_META ] ) ) : 0;
		update_post_meta( (int) $post_id, self::COURSE_META, $value );
	}
}
