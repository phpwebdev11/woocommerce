<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\ShopperLists;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Orchestrates which shopper-list types are enabled and registers the
 * user-facing surfaces gated on each.
 *
 * @internal Just for internal use.
 */
final class ShopperListsController implements RegisterHooksInterface {

	/**
	 * Known list slugs and the feature flag that gates each.
	 */
	private const SUPPORTED_LISTS = array(
		'saved-for-later' => 'cart_save_for_later',
		'wishlist'        => 'product_wishlist',
	);

	private const WISHLIST_ENDPOINT = 'wishlist';

	/**
	 * Whether a specific list type is enabled, or whether any type is
	 * enabled when no slug is passed.
	 *
	 * @param string|null $list_slug List slug, or null to ask about any type.
	 */
	public function is_enabled( ?string $list_slug = null ): bool {
		if ( null === $list_slug ) {
			foreach ( self::SUPPORTED_LISTS as $feature ) {
				if ( FeaturesUtil::feature_is_enabled( $feature ) ) {
					return true;
				}
			}
			return false;
		}
		$feature = self::SUPPORTED_LISTS[ $list_slug ] ?? null;
		return null !== $feature && FeaturesUtil::feature_is_enabled( $feature );
	}

	/**
	 * Whether the slug is a known list type, regardless of feature state.
	 * Use this to validate a slug shape; use `is_enabled()` to gate behavior.
	 */
	public function is_supported( string $list_slug ): bool {
		return isset( self::SUPPORTED_LISTS[ $list_slug ] );
	}

	/**
	 * Slugs of all currently-enabled lists, in declaration order.
	 *
	 * @return string[]
	 */
	public function get_enabled_slugs(): array {
		return array_keys(
			array_filter(
				self::SUPPORTED_LISTS,
				static fn( string $feature ): bool => FeaturesUtil::feature_is_enabled( $feature )
			)
		);
	}

	/**
	 * Register hooks. The flush listener attaches regardless of feature
	 * state so off→on transitions still flush rewrite rules.
	 */
	public function register(): void {
		add_action( 'update_option_woocommerce_product_wishlist_enabled', 'flush_rewrite_rules' );

		if ( ! $this->is_enabled( 'wishlist' ) ) {
			return;
		}
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_wishlist_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_wishlist_menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::WISHLIST_ENDPOINT . '_title', array( $this, 'wishlist_endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::WISHLIST_ENDPOINT . '_endpoint', array( $this, 'render_wishlist_endpoint' ) );
	}

	/**
	 * Register the `wishlist` query var. `WC_Query` reads this filter from
	 * both `add_endpoints()` and `add_query_vars()`, so one entry covers
	 * both the rewrite endpoint and the recognized query var.
	 *
	 * @param array $vars Existing query vars keyed by slug.
	 */
	public function add_wishlist_query_var( $vars ): array {
		$vars[ self::WISHLIST_ENDPOINT ] = self::WISHLIST_ENDPOINT;
		return $vars;
	}

	/**
	 * Insert the Wishlist link just before the logout link.
	 *
	 * @param array $items Existing menu items keyed by slug.
	 */
	public function add_wishlist_menu_item( $items ): array {
		$new_items = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new_items[ self::WISHLIST_ENDPOINT ] = __( 'Wishlist', 'woocommerce' );
			}
			$new_items[ $key ] = $label;
		}
		if ( ! isset( $new_items[ self::WISHLIST_ENDPOINT ] ) ) {
			$new_items[ self::WISHLIST_ENDPOINT ] = __( 'Wishlist', 'woocommerce' );
		}
		return $new_items;
	}

	/**
	 * Wishlist endpoint page title.
	 *
	 * @param string $title Default title.
	 */
	public function wishlist_endpoint_title( $title ): string {
		return __( 'Wishlist', 'woocommerce' );
	}

	/**
	 * Placeholder rendered until the wishlist block variation lands.
	 */
	public function render_wishlist_endpoint(): void {
		echo '<p>' . esc_html__( 'Your wishlist is empty.', 'woocommerce' ) . '</p>';
	}
}
