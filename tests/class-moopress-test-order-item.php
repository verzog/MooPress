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
 * A stand-in for a WooCommerce order line item, used in tests.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Carries a product and variation id, like a WooCommerce order item.
 */
class Moopress_Test_Order_Item {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private int $product_id;

	/**
	 * Variation ID (0 for none).
	 *
	 * @var int
	 */
	private int $variation_id;

	/**
	 * Build an order item.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 */
	public function __construct( int $product_id, int $variation_id = 0 ) {
		$this->product_id   = $product_id;
		$this->variation_id = $variation_id;
	}

	/**
	 * The product ID.
	 *
	 * @return int
	 */
	public function get_product_id(): int {
		return $this->product_id;
	}

	/**
	 * The variation ID (0 for none).
	 *
	 * @return int
	 */
	public function get_variation_id(): int {
		return $this->variation_id;
	}
}
