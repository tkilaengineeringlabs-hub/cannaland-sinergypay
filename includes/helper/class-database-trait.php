<?php
/**
 * Class DatabaseTrait
 *
 * @package  Conexa\DappWoo\Helper\DatabaseTrait
 */

namespace Conexa\DappWoo\Includes\Helper;

/**
 * Database Trait
 */
trait DatabaseTrait {

	/**
	 * Find an order id by itemmeta value
	 *
	 * @param string $meta_key Defines Key to looking for orders.
	 * @param string $meta_value Defines Values to looking for orders.
	 *
	 * @return int|false
	 */
	public static function find_order_by_itemmeta_value(
		string $meta_key,
		string $meta_value
	) {
		global $wpdb;

		$order_meta = $wpdb->prefix . 'postmeta';
		$query      = "SELECT post_id as order_id
        FROM {$order_meta}
        WHERE  meta_key = '%s'
        AND meta_value = '%s';";
		$row        = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( $query, $meta_key, $meta_value ),
			ARRAY_A
		);
		if ( ! empty( $row ) ) {
			return (int) $row['order_id'];
		}
		return false;
	}
}
