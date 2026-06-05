<?php
/**
 * Content detector — inventories WordPress content for graph indexing.
 *
 * Supports incremental detection by comparing post_modified against
 * the last-indexed timestamp. Handles posts, terms, users, media,
 * JetEngine CCTs, and external $wpdb tables.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Settings;
use NvoosGraphify\Graph\Db;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * Detects WordPress content that needs to be included in the knowledge graph.
 *
 * @since 1.0.0
 */
final class Detector
{
    /**
     * Default per-type cap on CCT items pulled into the graph.
     *
     * @since 0.7.0
     * @var int
     */
    public const DEFAULT_CCT_ITEMS_LIMIT = 1000;

    /**
     * Reason the last CCT detection pass returned no rows, if any.
     *
     * @var string
     */
    private static string $lastCctsSkipReason = '';

    /**
     * Reason the last external-row detection pass returned no rows.
     *
     * @var string
     */
    private static string $lastExternalSkipReason = '';

    // ─── Public API ─────────────────────────────────────────────

    /**
     * Return the reason CCT detection was skipped on the most recent call.
     *
     * @since 0.7.x
     * @return string Empty string when CCT detection ran normally.
     */
    public static function getLastCctsSkipReason(): string
    {
        return self::$lastCctsSkipReason;
    }

    /**
     * Return the reason external-row detection was skipped on the most recent call.
     *
     * @since 0.8.0
     * @return string Empty string when detection ran normally.
     */
    public static function getLastExternalSkipReason(): string
    {
        return self::$lastExternalSkipReason;
    }

    /**
     * Collect all content items that should be represented as nodes.
     *
     * @since 1.0.0
     * @param bool   $incremental When true, only return items newer than last build.
     * @param string $since       ISO-8601 datetime string (overrides incremental flag).
     * @return array{posts: WP_Post[], ccts: array[], terms: WP_Term[], users: WP_User[], media: WP_Post[], external: array[]}
     */
    public static function detect(bool $incremental = false, string $since = ''): array
    {
        if ($incremental && ! $since) {
            $since = (string) Db::getMeta('last_build_completed', '');
        }

        $posts    = self::detectPosts($since);
        $ccts     = self::detectCcts($since);
        $terms    = self::detectTerms($posts);
        $users    = self::detectUsers($posts, $ccts);
        $media    = self::detectMedia($posts);
        $external = self::isBridgeAvailable()
            ? self::detectExternalRows($since)
            : array();

        return compact('posts', 'ccts', 'terms', 'users', 'media', 'external');
    }

    // ─── Post detection ─────────────────────────────────────────

    /**
     * Return published posts across all configured post types.
     *
     * @since 1.0.0
     * @param string $since Optional datetime filter.
     * @return WP_Post[]
     */
    public static function detectPosts(string $since = ''): array
    {
        $pluginSettings = Settings::all();
        $postTypes      = isset($pluginSettings['post_types']) && is_array($pluginSettings['post_types'])
            ? $pluginSettings['post_types']
            : self::getDefaultPostTypes();

        $args = array(
            'post_type'      => array_map('sanitize_key', $postTypes),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'all',
            'no_found_rows'  => true,
        );

        if ($since) {
            $args['date_query'] = array(
                array(
                    'column' => 'post_modified_gmt',
                    'after'  => sanitize_text_field($since),
                ),
            );
        }

        return get_posts($args);
    }

    /**
     * Return the default public post types to index.
     *
     * Includes post types registered with `public => true` OR `show_in_rest => true`.
     *
     * @since 1.0.0
     * @return string[]
     */
    public static function getDefaultPostTypes(): array
    {
        $candidates = array_unique(
            array_merge(
                array_keys(get_post_types(array('public' => true), 'names')),
                array_keys(get_post_types(array('show_in_rest' => true), 'names'))
            )
        );

        $systemBlacklist = array(
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
        );

        $postTypes = array_values(array_diff($candidates, $systemBlacklist));

        /**
         * Filter the list of post types indexed by the knowledge graph.
         *
         * @since 0.7.0
         * @param string[] $postTypes Sanitised post type slugs.
         */
        $postTypes = apply_filters('nvoos_graphify_indexed_post_types', $postTypes);

        return array_values(array_filter(array_map('sanitize_key', (array) $postTypes)));
    }

    // ─── Term detection ─────────────────────────────────────────

    /**
     * Return terms used by at least one post in the supplied array.
     *
     * @since 1.0.0
     * @param WP_Post[] $posts Posts to inspect.
     * @return WP_Term[]
     */
    public static function detectTerms(array $posts): array
    {
        if (empty($posts)) {
            return array();
        }

        $postIds    = wp_list_pluck($posts, 'ID');
        $taxonomies = get_taxonomies(array('public' => true), 'names');

        $terms = wp_get_object_terms(
            $postIds,
            array_values($taxonomies),
            array('fields' => 'all')
        );

        if (is_wp_error($terms)) {
            return array();
        }

        // Deduplicate by term ID.
        $unique = array();
        foreach ($terms as $term) {
            $unique[$term->term_id] = $term;
        }
        return array_values($unique);
    }

    // ─── User/author detection ──────────────────────────────────

    /**
     * Return author user objects for the supplied posts and CCT items.
     *
     * @since 1.0.0
     * @param WP_Post[] $posts Posts to inspect.
     * @param array[]   $ccts  Optional CCT item rows.
     * @return WP_User[]
     */
    public static function detectUsers(array $posts, array $ccts = array()): array
    {
        $authorIds = array();

        if (! empty($posts)) {
            $authorIds = array_merge(
                $authorIds,
                array_map('absint', wp_list_pluck($posts, 'post_author'))
            );
        }

        foreach ($ccts as $row) {
            if (! empty($row['item']['cct_author_id'])) {
                $authorIds[] = absint($row['item']['cct_author_id']);
            }
        }

        $authorIds = array_unique(array_filter($authorIds));

        $users = array();
        foreach ($authorIds as $uid) {
            $user = get_userdata($uid);
            if ($user instanceof WP_User) {
                $users[] = $user;
            }
        }
        return $users;
    }

    // ─── Media detection ────────────────────────────────────────

    /**
     * Return attachment post objects for featured images used by the supplied posts.
     *
     * @since 1.0.0
     * @param WP_Post[] $posts Posts to inspect.
     * @return WP_Post[]
     */
    public static function detectMedia(array $posts): array
    {
        if (empty($posts)) {
            return array();
        }

        $attachmentIds = array();
        foreach ($posts as $post) {
            $thumb = (int) get_post_thumbnail_id($post->ID);
            if ($thumb > 0) {
                $attachmentIds[] = $thumb;
            }
        }
        $attachmentIds = array_unique($attachmentIds);

        if (empty($attachmentIds)) {
            return array();
        }

        $media = get_posts(
            array(
                'post_type'      => 'attachment',
                'post__in'       => $attachmentIds,
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            )
        );

        return is_array($media) ? $media : array();
    }

    // ─── JetEngine CCT detection ────────────────────────────────

    /**
     * Return JetEngine Custom Content Type items that should be indexed.
     *
     * @since 1.0.0
     * @param string $since Optional ISO-8601 datetime for incremental builds.
     * @return array[]
     */
    public static function detectCcts(string $since = ''): array
    {
        self::$lastCctsSkipReason = '';

        if (! function_exists('jet_engine')) {
            self::$lastCctsSkipReason = 'jetengine_not_active';
            return array();
        }

        $engine = jet_engine();
        if (empty($engine->modules) || ! method_exists($engine->modules, 'get_module')) {
            self::$lastCctsSkipReason = 'jetengine_modules_unavailable';
            return array();
        }

        $moduleWrapper = $engine->modules->get_module('custom-content-types');
        if (empty($moduleWrapper) || empty($moduleWrapper->instance)) {
            self::$lastCctsSkipReason = 'cct_module_inactive';
            return array();
        }

        $module = $moduleWrapper->instance;
        if (empty($module->manager) || ! method_exists($module->manager, 'get_content_types')) {
            self::$lastCctsSkipReason = 'cct_manager_unavailable';
            return array();
        }

        $types = $module->manager->get_content_types();
        if (empty($types) || ! is_array($types)) {
            self::$lastCctsSkipReason = 'no_content_types_registered';
            return array();
        }

        /**
         * Filter the maximum number of items pulled from each CCT type.
         *
         * @since 0.7.0
         * @param int $limit Maximum items per CCT type.
         */
        $perTypeLimit = (int) apply_filters('nvoos_graphify_cct_items_limit', self::DEFAULT_CCT_ITEMS_LIMIT);
        if ($perTypeLimit <= 0) {
            $perTypeLimit = self::DEFAULT_CCT_ITEMS_LIMIT;
        }

        // Build the indexed-slug allowlist.
        $defaultSlugs = array();
        foreach ($types as $typeKey => $type) {
            $slug = self::resolveCctSlug($type, (string) $typeKey);
            if ('' !== $slug) {
                $defaultSlugs[] = $slug;
            }
        }
        $defaultSlugs = array_values(array_unique($defaultSlugs));

        /**
         * Filter the list of CCT slugs indexed by the knowledge graph.
         *
         * @since 0.7.0
         * @param string[] $slugs Sanitised CCT slugs.
         */
        $indexedSlugs = apply_filters('nvoos_graphify_indexed_cct_slugs', $defaultSlugs);
        $indexedSlugs = array_map('sanitize_key', (array) $indexedSlugs);

        $rows = array();

        foreach ($types as $typeKey => $type) {
            $slug = self::resolveCctSlug($type, (string) $typeKey);
            if ('' === $slug) {
                continue;
            }

            if (! in_array($slug, $indexedSlugs, true)) {
                continue;
            }

            // Resolve human-readable name.
            $name = self::resolveTypeName($type, $slug);

            $db = is_object($type) && ! empty($type->db) ? $type->db : null;
            if (null === $db || ! method_exists($db, 'query')) {
                continue;
            }

            if (method_exists($db, 'set_format_flag')) {
                $db->set_format_flag(ARRAY_A);
            }

            $filterArgs = array();
            if ($since) {
                $filterArgs[] = array(
                    'key'     => 'cct_modified',
                    'value'   => sanitize_text_field($since),
                    'compare' => '>',
                );
            }

            $items = $db->query($filterArgs, $perTypeLimit, 0);
            if (! is_array($items) || empty($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (is_object($item)) {
                    $item = (array) $item;
                }
                if (! is_array($item) || empty($item['_ID'])) {
                    continue;
                }
                $rows[] = array(
                    'type' => $slug,
                    'name' => $name,
                    'item' => $item,
                );
            }
        }

        if (empty($rows) && '' === self::$lastCctsSkipReason) {
            self::$lastCctsSkipReason = 'all_content_types_empty_or_unindexed';
        }

        return $rows;
    }

    // ─── External table row detection ───────────────────────────

    /**
     * Collect rows from registered external $wpdb tables.
     *
     * @since 1.0.0
     * @param string $since Optional ISO-8601 datetime for incremental builds.
     * @return array[]
     */
    public static function detectExternalRows(string $since = ''): array
    {
        global $wpdb;

        self::$lastExternalSkipReason = '';

        /**
         * Filter the list of external table descriptors to index.
         *
         * @since 0.8.0
         * @param array[] $tables Empty array; bridges append descriptors.
         */
        $tableDescriptors = apply_filters('nvoos_graphify_external_tables', array());

        if (empty($tableDescriptors) || ! is_array($tableDescriptors)) {
            self::$lastExternalSkipReason = 'no_external_tables_registered';
            return array();
        }

        /**
         * Filter the maximum number of rows pulled per external table.
         *
         * @since 0.8.0
         * @param int $limit Maximum rows per table (default 1000).
         */
        $perTableLimit = (int) apply_filters('nvoos_graphify_external_table_limit', 1000);
        if ($perTableLimit <= 0) {
            $perTableLimit = 1000;
        }

        $rows = array();

        foreach ($tableDescriptors as $descriptor) {
            if (empty($descriptor['table']) || empty($descriptor['primary_key'])) {
                continue;
            }

            $table      = $wpdb->prefix . sanitize_key($descriptor['table']);
            $primaryKey = sanitize_key($descriptor['primary_key']);
            $nodeType   = sanitize_key($descriptor['node_type'] ?? 'ext_' . sanitize_key($descriptor['table']));
            $labelField = isset($descriptor['label_field']) ? sanitize_key((string) $descriptor['label_field']) : '';
            $contentFld = isset($descriptor['content_field']) ? sanitize_key((string) $descriptor['content_field']) : '';
            $modField   = isset($descriptor['modified_field']) ? sanitize_key((string) $descriptor['modified_field']) : '';
            $fkDefs     = isset($descriptor['foreign_keys']) && is_array($descriptor['foreign_keys'])
                ? $descriptor['foreign_keys']
                : array();

            // Verify the table exists.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($table !== $exists) {
                continue;
            }

            // Build WHERE clause for incremental builds.
            $whereClause = '';
            if ($since && '' !== $modField) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $whereClause = $wpdb->prepare(
                    " WHERE `{$modField}` > %s",
                    sanitize_text_field($since)
                );
            }

            // Determine columns to fetch.
            $columns = array('`' . $primaryKey . '`');
            if ('' !== $labelField) {
                $columns[] = '`' . $labelField . '`';
            }
            if ('' !== $contentFld) {
                $columns[] = '`' . $contentFld . '`';
            }
            foreach ($fkDefs as $fk) {
                if (! empty($fk['local_column'])) {
                    $col = '`' . sanitize_key($fk['local_column']) . '`';
                    if (! in_array($col, $columns, true)) {
                        $columns[] = $col;
                    }
                }
            }
            $columnsSql = implode(', ', $columns);

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $dbRows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$columnsSql} FROM `{$table}`{$whereClause} ORDER BY `{$primaryKey}` DESC LIMIT %d",
                    $perTableLimit
                ),
                ARRAY_A
            );
            // phpcs:enable

            if (empty($dbRows) || ! is_array($dbRows)) {
                continue;
            }

            foreach ($dbRows as $dbRow) {
                $pkValue = isset($dbRow[$primaryKey]) ? absint($dbRow[$primaryKey]) : 0;
                if (0 === $pkValue) {
                    continue;
                }

                $nodeId = self::externalNodeId($nodeType, $pkValue);

                // Resolve label.
                $label = '';
                if (! empty($descriptor['label_callback']) && is_callable($descriptor['label_callback'])) {
                    $label = (string) call_user_func($descriptor['label_callback'], $dbRow);
                } elseif ('' !== $labelField && ! empty($dbRow[$labelField]) && is_scalar($dbRow[$labelField])) {
                    $label = (string) $dbRow[$labelField];
                }
                if ('' === $label) {
                    /* translators: 1: node type, 2: numeric ID */
                    $label = sprintf(__('%1$s #%2$d', 'nvoos-graphify'), $nodeType, $pkValue);
                }

                // Resolve content.
                $content = '';
                if (! empty($descriptor['content_callback']) && is_callable($descriptor['content_callback'])) {
                    $content = (string) call_user_func($descriptor['content_callback'], $dbRow);
                } elseif ('' !== $contentFld && ! empty($dbRow[$contentFld]) && is_scalar($dbRow[$contentFld])) {
                    $content = (string) $dbRow[$contentFld];
                }

                // Build FK edges.
                $fkEdges = array();
                foreach ($fkDefs as $fk) {
                    if (empty($fk['local_column']) || empty($fk['target_type']) || empty($fk['relation'])) {
                        continue;
                    }
                    $localCol = sanitize_key($fk['local_column']);
                    if (empty($dbRow[$localCol])) {
                        continue;
                    }
                    $targetPk      = absint($dbRow[$localCol]);
                    $targetNodeId  = self::externalNodeId(sanitize_key($fk['target_type']), $targetPk);
                    $fkEdges[]     = array(
                        'source_node_id' => $nodeId,
                        'target_node_id' => $targetNodeId,
                        'relation'       => sanitize_text_field($fk['relation']),
                        'confidence'     => 1.0,
                        'provenance'     => 'EXTRACTED',
                    );
                }

                // Build node properties from scalar columns.
                $properties = array('table' => $descriptor['table']);
                foreach ($dbRow as $col => $val) {
                    if (is_scalar($val) && '' !== (string) $val) {
                        $properties[sanitize_key($col)] = (string) $val;
                    }
                }

                $rows[] = array(
                    'node_id'    => $nodeId,
                    'node_type'  => $nodeType,
                    'label'      => $label,
                    'content'    => $content,
                    'properties' => $properties,
                    'fk_edges'   => $fkEdges,
                );
            }
        }

        return $rows;
    }

    // ─── Node ID helpers ────────────────────────────────────────

    /**
     * Generate a stable node_id for a post.
     *
     * @since 1.0.0
     * @param int    $postId   WordPress post ID.
     * @param string $postType Post type slug.
     * @return string
     */
    public static function postNodeId(int $postId, string $postType = 'post'): string
    {
        return 'post_' . absint($postId);
    }

    /**
     * Generate a stable node_id for a term.
     *
     * @since 1.0.0
     * @param int    $termId   Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @return string
     */
    public static function termNodeId(int $termId, string $taxonomy): string
    {
        return 'term_' . absint($termId) . '_' . sanitize_key($taxonomy);
    }

    /**
     * Generate a stable node_id for a user.
     *
     * @since 1.0.0
     * @param int $userId WordPress user ID.
     * @return string
     */
    public static function userNodeId(int $userId): string
    {
        return 'user_' . absint($userId);
    }

    /**
     * Generate a stable node_id for an attachment.
     *
     * @since 1.0.0
     * @param int $attachmentId WordPress attachment post ID.
     * @return string
     */
    public static function mediaNodeId(int $attachmentId): string
    {
        return 'media_' . absint($attachmentId);
    }

    /**
     * Generate a stable node_id for a JetEngine CCT item.
     *
     * @since 1.0.0
     * @param string $slug   CCT slug.
     * @param int    $itemId Item ID (the `_ID` column).
     * @return string
     */
    public static function cctNodeId(string $slug, int $itemId): string
    {
        return 'cct_' . sanitize_key($slug) . '_' . absint($itemId);
    }

    /**
     * Generate a stable node_id for a custom $wpdb table row.
     *
     * @since 1.0.0
     * @param string $nodeType        Node type string (e.g. `ext_slash_cmd_audit`).
     * @param int    $primaryKeyValue Integer primary-key value.
     * @return string
     */
    public static function externalNodeId(string $nodeType, int $primaryKeyValue): string
    {
        return sanitize_key($nodeType) . '_' . absint($primaryKeyValue);
    }

    /**
     * Generate a stable node_id for a named entity or topic string.
     *
     * @since 1.0.0
     * @param string $label Entity/topic label.
     * @param string $type  Entity type (entity|topic).
     * @return string
     */
    public static function entityNodeId(string $label, string $type = 'entity'): string
    {
        return $type . '_' . substr(hash('sha256', strtolower(trim($label))), 0, 16);
    }

    /**
     * Fetch a single JetEngine CCT item by slug + numeric ID.
     *
     * @since 1.0.0
     * @param string $slug   CCT slug.
     * @param int    $itemId Item `_ID` column value.
     * @return array{type: string, name: string, item: array}|null
     */
    public static function getCctItem(string $slug, int $itemId): ?array
    {
        $slug   = sanitize_key($slug);
        $itemId = absint($itemId);
        if ('' === $slug || 0 === $itemId) {
            return null;
        }
        if (! function_exists('jet_engine')) {
            return null;
        }

        $engine = jet_engine();
        if (empty($engine->modules) || ! method_exists($engine->modules, 'get_module')) {
            return null;
        }

        $moduleWrapper = $engine->modules->get_module('custom-content-types');
        if (empty($moduleWrapper) || empty($moduleWrapper->instance)) {
            return null;
        }

        $module = $moduleWrapper->instance;
        if (empty($module->manager) || ! method_exists($module->manager, 'get_content_types')) {
            return null;
        }

        $types = $module->manager->get_content_types();
        if (empty($types) || ! is_array($types)) {
            return null;
        }

        foreach ($types as $type) {
            $typeSlug = self::resolveCctSlug($type, '');
            if ($typeSlug !== $slug) {
                continue;
            }

            if (empty($type->db) || ! method_exists($type->db, 'query')) {
                return null;
            }

            if (method_exists($type->db, 'set_format_flag')) {
                $type->db->set_format_flag(ARRAY_A);
            }

            $rows = $type->db->query(
                array(
                    array(
                        'key'   => '_ID',
                        'value' => $itemId,
                    ),
                ),
                1,
                0
            );

            if (! is_array($rows) || empty($rows)) {
                return null;
            }

            $item = $rows[0];
            if (is_object($item)) {
                $item = (array) $item;
            }
            if (! is_array($item) || empty($item['_ID'])) {
                return null;
            }

            $name = self::resolveTypeName($type, $slug);

            return array(
                'type' => $slug,
                'name' => $name,
                'item' => $item,
            );
        }

        return null;
    }

    // ─── Internal helpers ───────────────────────────────────────

    /**
     * Resolve a sanitised CCT slug from a JetEngine content-type entry.
     *
     * @since 1.0.0
     * @param object|array $type    JetEngine content-type entry.
     * @param string       $typeKey Associative key from the content-types map.
     * @return string Sanitised slug, or empty string.
     */
    private static function resolveCctSlug(object|array $type, string $typeKey = ''): string
    {
        $slug = '';
        if (is_object($type) && ! empty($type->slug)) {
            $slug = $type->slug;
        } elseif (is_object($type) && ! empty($type->args) && ! empty($type->args['slug'])) {
            $slug = $type->args['slug'];
        } elseif (is_array($type) && ! empty($type['slug'])) {
            $slug = $type['slug'];
        } elseif (is_array($type) && ! empty($type['args']['slug'])) {
            $slug = $type['args']['slug'];
        } elseif ('' !== $typeKey) {
            $slug = $typeKey;
        }

        return sanitize_key($slug);
    }

    /**
     * Resolve a human-readable name from a JetEngine content type.
     *
     * @since 1.0.0
     * @param object|array $type JetEngine content-type entry.
     * @param string       $slug Fallback slug.
     * @return string
     */
    private static function resolveTypeName(object|array $type, string $slug): string
    {
        if (is_object($type) && ! empty($type->name)) {
            return $type->name;
        }
        if (is_object($type) && ! empty($type->args) && ! empty($type->args['name'])) {
            return $type->args['name'];
        }
        if (is_array($type) && ! empty($type['name'])) {
            return $type['name'];
        }
        if (is_array($type) && ! empty($type['args']['name'])) {
            return $type['args']['name'];
        }
        return $slug;
    }

    /**
     * Check if the NV oOS bridge is available for external table detection.
     *
     * Tries the new namespace first, falls back to the legacy addon class.
     *
     * @since 1.0.0
     * @return bool
     */
    private static function isBridgeAvailable(): bool
    {
        return class_exists('NV_oOS_Graphify_NV_oOS_Bridge')
            || class_exists('NvoosGraphify\\Memory\\Bridge');
    }
}
