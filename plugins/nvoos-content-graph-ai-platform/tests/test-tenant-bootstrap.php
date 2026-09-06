<?php
/**
 * Tenant bootstrap port tests (Wave E4, sub-cluster 1).
 *
 * Characterization suite for the ported `TenantBootstrap` (the base
 * init.php wiring): the full hook surface (rest_api_init REST meta,
 * conditional admin columns, pre_get_posts read filters at 10/15,
 * save_post stamping at 20, the upgrade migration hook), the
 * filterable scoped-CPT registry, the migratable-table registry, and
 * the stamp/filter behavior contracts. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Tenant\TenantBootstrap;
use NvoosContentGraphAiPlatform\Tenant\TenantContext;
use NvoosContentGraphAiPlatform\Tenant\TenantDatabase;
use NvoosContentGraphAiPlatform\Tenant\TenantFeatureFlags;

/**
 * Tenant bootstrap (init.php) wiring characterization.
 */
class Test_Tenant_Bootstrap extends \WP_UnitTestCase {

	public function tearDown(): void {
		remove_action( 'rest_api_init', array( TenantBootstrap::class, 'register_tenant_rest_meta' ) );
		remove_action( 'pre_get_posts', array( TenantBootstrap::class, 'filter_query_by_tenant' ) );
		remove_action( 'pre_get_posts', array( TenantBootstrap::class, 'filter_query_scoped_types_only' ) );
		remove_action( 'save_post', array( TenantBootstrap::class, 'stamp_tenant_meta_on_save' ) );
		remove_action( 'wp_mcp_ai_after_plugin_upgrade', array( TenantBootstrap::class, 'migrate_all_custom_tables' ) );
		remove_action( 'admin_init', array( TenantDatabase::class, 'maybe_create_tables' ) );
		remove_action( 'wp_mcp_ai_activate', array( TenantDatabase::class, 'create_tables' ) );
		remove_filter( 'manage_posts_columns', array( TenantBootstrap::class, 'add_tenant_admin_columns' ) );
		remove_filter( 'manage_pages_columns', array( TenantBootstrap::class, 'add_tenant_admin_columns' ) );
		remove_action( 'manage_posts_custom_column', array( TenantBootstrap::class, 'render_tenant_admin_column' ) );
		remove_action( 'manage_pages_custom_column', array( TenantBootstrap::class, 'render_tenant_admin_column' ) );

		TenantFeatureFlags::disable();
		delete_option( 'wp_mcp_ai_settings' );
		TenantContext::reset();
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'] );

		parent::tearDown();
	}

	public function test_register_wires_hooks(): void {
		TenantBootstrap::register();

		$this->assertSame( 10, has_action( 'rest_api_init', array( TenantBootstrap::class, 'register_tenant_rest_meta' ) ) );
		$this->assertSame( 10, has_action( 'pre_get_posts', array( TenantBootstrap::class, 'filter_query_by_tenant' ) ) );
		$this->assertSame( 15, has_action( 'pre_get_posts', array( TenantBootstrap::class, 'filter_query_scoped_types_only' ) ) );
		$this->assertSame( 20, has_action( 'save_post', array( TenantBootstrap::class, 'stamp_tenant_meta_on_save' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_after_plugin_upgrade', array( TenantBootstrap::class, 'migrate_all_custom_tables' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( TenantDatabase::class, 'maybe_create_tables' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_activate', array( TenantDatabase::class, 'create_tables' ) ) );
	}

	public function test_register_skips_admin_columns_when_disabled(): void {
		TenantFeatureFlags::disable();
		TenantBootstrap::register();

		$this->assertFalse( has_filter( 'manage_posts_columns', array( TenantBootstrap::class, 'add_tenant_admin_columns' ) ) );
	}

	public function test_register_adds_admin_columns_when_enabled(): void {
		TenantFeatureFlags::enable();
		TenantBootstrap::register();

		$this->assertNotFalse( has_filter( 'manage_posts_columns', array( TenantBootstrap::class, 'add_tenant_admin_columns' ) ) );
		$this->assertNotFalse( has_filter( 'manage_pages_columns', array( TenantBootstrap::class, 'add_tenant_admin_columns' ) ) );
	}

	public function test_admin_columns_inserts_tenant_after_title(): void {
		$columns = TenantBootstrap::add_tenant_admin_columns(
			array(
				'cb'    => 'cb',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'title', 'tenant', 'date' ), array_keys( $columns ) );
	}

	public function test_render_tenant_admin_column_with_and_without_meta(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_tenant_type', 'school' );
		update_post_meta( $post_id, '_tenant_id', 42 );

		ob_start();
		TenantBootstrap::render_tenant_admin_column( 'tenant', $post_id );
		$this->assertSame( 'school:42', ob_get_clean() );

		// Wrong column — no output.
		ob_start();
		TenantBootstrap::render_tenant_admin_column( 'title', $post_id );
		$this->assertSame( '', ob_get_clean() );

		// Missing meta — em-dash placeholder.
		$bare = self::factory()->post->create();
		ob_start();
		TenantBootstrap::render_tenant_admin_column( 'tenant', $bare );
		$this->assertSame( '<span aria-hidden="true">—</span>', ob_get_clean() );
	}

	public function test_register_tenant_rest_meta_registers_keys(): void {
		TenantBootstrap::register_tenant_rest_meta();

		// register_post_meta stores under the post-type (object_subtype)
		// key — WP 6.9's registered_meta_key_exists() takes the subtype.
		$this->assertTrue( registered_meta_key_exists( 'post', '_tenant_type', 'post' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', '_tenant_id', 'post' ) );

		// The registration is per-CPT-subtype, not a global 'post' key.
		$this->assertFalse( registered_meta_key_exists( 'post', '_tenant_type' ) );
	}

	public function test_filter_query_by_tenant_appends_meta_query(): void {
		TenantFeatureFlags::enable();
		TenantContext::instance()->set( 'school', 42 );

		$query = new \WP_Query( array( 'post_type' => 'post' ) );
		TenantBootstrap::filter_query_by_tenant( $query );

		$meta_query = $query->get( 'meta_query' );
		$this->assertSame( 'AND', $meta_query['relation'] );
		$this->assertSame( 42, $meta_query[0]['value'] );
		$this->assertSame( '_tenant_id', $meta_query[0]['key'] );
		$this->assertSame( 'school', $meta_query[1]['value'] );
		$this->assertSame( '_tenant_type', $meta_query[1]['key'] );
	}

	public function test_filter_query_by_tenant_skips_when_disabled(): void {
		TenantFeatureFlags::disable();
		TenantContext::instance()->set( 'school', 42 );

		$query = new \WP_Query( array( 'post_type' => 'post' ) );
		TenantBootstrap::filter_query_by_tenant( $query );

		$this->assertSame( array(), $query->get( 'meta_query', array() ) );
	}

	public function test_filter_query_by_tenant_skips_without_context(): void {
		TenantFeatureFlags::enable();
		wp_set_current_user( 0 );

		$query = new \WP_Query( array( 'post_type' => 'post' ) );
		TenantBootstrap::filter_query_by_tenant( $query );

		$this->assertSame( array(), $query->get( 'meta_query', array() ) );
	}

	public function test_filter_query_scoped_types_only_filters_scoped_cpt(): void {
		TenantFeatureFlags::enable();
		TenantContext::instance()->set( 'school', 42 );

		$query = new \WP_Query( array( 'post_type' => 'mcp_ai_lead' ) );
		TenantBootstrap::filter_query_scoped_types_only( $query );

		$meta_query = $query->get( 'meta_query' );
		$this->assertSame( 'AND', $meta_query['relation'] );
	}

	public function test_filter_query_scoped_types_only_skips_core_posts(): void {
		TenantFeatureFlags::enable();
		TenantContext::instance()->set( 'school', 42 );

		// Core 'post' is not tenant-scoped.
		$query = new \WP_Query( array( 'post_type' => 'post' ) );
		TenantBootstrap::filter_query_scoped_types_only( $query );
		$this->assertSame( array(), $query->get( 'meta_query', array() ) );

		// Default (empty) post_type resolves to 'post' — also skipped.
		$default_query = new \WP_Query();
		TenantBootstrap::filter_query_scoped_types_only( $default_query );
		$this->assertSame( array(), $default_query->get( 'meta_query', array() ) );
	}

	public function test_get_tenant_scoped_post_types_is_filterable(): void {
		$defaults = TenantBootstrap::get_tenant_scoped_post_types();

		$this->assertContains( 'mcp_ai_lead', $defaults );
		$this->assertContains( 'mcp_ai_audit', $defaults );

		add_filter(
			'wp_mcp_ai_tenant_scoped_post_types',
			static function ( array $types ): array {
				$types[] = 'custom_scoped_cpt';
				return $types;
			}
		);

		$this->assertContains( 'custom_scoped_cpt', TenantBootstrap::get_tenant_scoped_post_types() );

		remove_all_filters( 'wp_mcp_ai_tenant_scoped_post_types' );
	}

	public function test_get_tenant_migratable_tables(): void {
		global $wpdb;

		$tables = TenantBootstrap::get_tenant_migratable_tables();

		$this->assertCount( 18, $tables );
		foreach ( $tables as $table ) {
			$this->assertStringStartsWith( $wpdb->prefix, $table );
		}
		$this->assertContains( $wpdb->prefix . 'mcp_ai_audit_trail', $tables );
		$this->assertContains( $wpdb->prefix . 'nvoos_graph_node_embeddings', $tables );
	}

	public function test_stamp_tenant_meta_on_save_stamps_scoped_cpt(): void {
		TenantFeatureFlags::enable();
		TenantContext::instance()->set( 'school', 42 );

		$post_id = self::factory()->post->create();
		$post    = new \WP_Post(
			(object) array(
				'ID'        => $post_id,
				'post_type' => 'mcp_ai_lead',
			)
		);

		TenantBootstrap::stamp_tenant_meta_on_save( $post_id, $post, false );

		$this->assertEquals( 'school', get_post_meta( $post_id, '_tenant_type', true ) );
		$this->assertEquals( 42, (int) get_post_meta( $post_id, '_tenant_id', true ) );
	}

	public function test_stamp_skips_when_disabled_or_unscoped_or_existing(): void {
		// Disabled.
		TenantFeatureFlags::disable();
		TenantContext::instance()->set( 'school', 42 );
		$post_id = self::factory()->post->create();
		$post    = new \WP_Post(
			(object) array(
				'ID'        => $post_id,
				'post_type' => 'mcp_ai_lead',
			)
		);
		TenantBootstrap::stamp_tenant_meta_on_save( $post_id, $post, false );
		$this->assertEquals( '', get_post_meta( $post_id, '_tenant_type', true ) );

		// Enabled but not a scoped post type.
		TenantFeatureFlags::enable();
		$post_id = self::factory()->post->create();
		$post    = new \WP_Post(
			(object) array(
				'ID'        => $post_id,
				'post_type' => 'post',
			)
		);
		TenantBootstrap::stamp_tenant_meta_on_save( $post_id, $post, false );
		$this->assertEquals( '', get_post_meta( $post_id, '_tenant_type', true ) );

		// Existing assignment preserved.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_tenant_id', 7 );
		$post = new \WP_Post(
			(object) array(
				'ID'        => $post_id,
				'post_type' => 'mcp_ai_lead',
			)
		);
		TenantBootstrap::stamp_tenant_meta_on_save( $post_id, $post, false );
		$this->assertEquals( 7, (int) get_post_meta( $post_id, '_tenant_id', true ) );
		$this->assertEquals( '', get_post_meta( $post_id, '_tenant_type', true ) );
	}

	public function test_stamp_skips_revisions(): void {
		TenantFeatureFlags::enable();
		TenantContext::instance()->set( 'school', 42 );

		$post_id     = self::factory()->post->create();
		$revision_id = wp_save_post_revision( $post_id );
		$revision    = new \WP_Post(
			(object) array(
				'ID'        => $revision_id,
				'post_type' => 'mcp_ai_lead',
			)
		);

		TenantBootstrap::stamp_tenant_meta_on_save( $revision_id, $revision, true );

		$this->assertEquals( '', get_post_meta( $revision_id, '_tenant_type', true ) );
	}

	public function test_stamp_skips_without_context(): void {
		TenantFeatureFlags::enable();
		wp_set_current_user( 0 );

		$post_id = self::factory()->post->create();
		$post    = new \WP_Post(
			(object) array(
				'ID'        => $post_id,
				'post_type' => 'mcp_ai_lead',
			)
		);

		TenantBootstrap::stamp_tenant_meta_on_save( $post_id, $post, false );

		$this->assertEquals( '', get_post_meta( $post_id, '_tenant_type', true ) );
	}
}
