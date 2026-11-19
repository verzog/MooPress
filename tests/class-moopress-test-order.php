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
 * A stand-in for a WooCommerce order, used in tests.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Carries a customer id and line items, like a WooCommerce order.
 */
class Moopress_Test_Order {

	/**
	 * Customer WordPress user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Line items.
	 *
	 * @var Moopress_Test_Order_Item[]
	 */
	private array $items;

	/**
	 * Build an order.
	 *
	 * @param int                        $user_id Customer WordPress user ID.
	 * @param Moopress_Test_Order_Item[] $items   Line items.
	 */
	public function __construct( int $user_id, array $items ) {
		$this->user_id = $user_id;
		$this->items   = $items;
	}

	/**
	 * The customer's WordPress user ID.
	 *
	 * @return int
	 */
	public function get_user_id(): int {
		return $this->user_id;
	}

	/**
	 * The order's line items.
	 *
	 * @return Moopress_Test_Order_Item[]
	 */
	public function get_items(): array {
		return $this->items;
	}
}
