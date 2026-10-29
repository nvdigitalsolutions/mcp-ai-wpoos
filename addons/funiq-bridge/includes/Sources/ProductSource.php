<?php
/**
 * Product source contract.
 *
 * The ProductsController delegates every product operation to the active
 * sources; each source owns one backing store (the built-in CPTs or
 * WooCommerce) and emits the same Payload-shaped documents, so the
 * storefront contract never changes.
 *
 * @package FuniqBridge\Sources
 */

namespace FuniqBridge\Sources;

use WP_Error;

/**
 * A backing store for the /products contract collection.
 */
interface ProductSource {

	/**
	 * Source name — the value of the document `source` field.
	 *
	 * @return string Schema::SOURCE_NATIVE or Schema::SOURCE_WOO.
	 */
	public function name(): string;

	/**
	 * Whether the backing store is available in this environment.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * List published products (one page).
	 *
	 * @param int                                 $page  1-based page.
	 * @param int                                 $limit Docs per page.
	 * @param array<string, array<string, mixed>> $where Normalized where clause.
	 * @param string|null                         $sort  Raw sort param.
	 * @return array{docs: array<int, array<string, mixed>>, total: int}
	 */
	public function list_products( int $page, int $limit, array $where, ?string $sort ): array;

	/**
	 * Fetch one product document by ID, or null when missing/not owned.
	 *
	 * @param int $id Product ID.
	 * @return array<string, mixed>|null
	 */
	public function get_product( int $id ): ?array;

	/**
	 * Create a product from contract-shaped data.
	 *
	 * @param array<string, mixed> $data Request body fields.
	 * @return array<string, mixed>|WP_Error The created document, or an error.
	 */
	public function create_product( array $data );

	/**
	 * Update a product from contract-shaped data (only provided fields).
	 *
	 * @param int                  $id   Product ID.
	 * @param array<string, mixed> $data Request body fields.
	 * @return array<string, mixed>|WP_Error The updated document, or an error.
	 */
	public function update_product( int $id, array $data );

	/**
	 * Delete a product permanently.
	 *
	 * @param int $id Product ID.
	 * @return true|WP_Error
	 */
	public function delete_product( int $id );
}
