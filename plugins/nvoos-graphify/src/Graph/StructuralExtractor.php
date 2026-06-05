<?php
/**
 * Structural extractor — produces deterministic edges from WordPress relationships.
 *
 * No AI required. All edges are tagged provenance=EXTRACTED with confidence=1.0.
 *
 * Relationships produced:
 *   LINKS_TO            — internal hyperlink (href → post)
 *   CATEGORIZED_BY      — post → category term
 *   TAGGED_WITH         — post → tag / custom taxonomy term
 *   AUTHORED_BY         — post → author user
 *   HAS_FEATURED_IMAGE  — post → attachment
 *   (plus CCT edges emitted by the `nvoos_graphify_emit_cct_edges` filter
 *    and external-table FK edges)
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Graph\Detector;

/**
 * Extracts structural (deterministic) graph relationships from WordPress content.
 *
 * @since 1.0.0
 */
final class StructuralExtractor
{
    /**
     * Run structural extraction for a set of posts.
     *
     * Converts detected posts, terms, users, and media into a flat list of
     * node definitions and edge definitions ready for the Builder.
     *
     * @since 1.0.0
     * @param array<string,mixed> $detected Output of Detector::detect().
     * @return array{nodes: array<int,array<string,mixed>>, edges: array<int,array<string,mixed>>}
     */
    public static function extract(array $detected): array
    {
        $nodes = array();
        $edges = array();

        // Build post nodes.
        foreach ($detected['posts'] as $post) {
            $nodeId = Detector::postNodeId($post->ID, $post->post_type);

            /**
             * Filter the content used for hashing / semantic extraction for a post.
             *
             * @since 0.8.0
             * @param string  $content Current post content.
             * @param WP_Post $post    The post.
             */
            $postContent = (string) apply_filters('nvoos_graphify_post_content_resolver', $post->post_content, $post);

            $nodes[] = array(
                'node_id'      => $nodeId,
                'label'        => $post->post_title,
                'type'         => $post->post_type,
                'post_id'      => $post->ID,
                'url'          => get_permalink($post->ID),
                'properties'   => array(
                    'post_status' => $post->post_status,
                    'post_date'   => $post->post_date,
                    'modified'    => $post->post_modified,
                ),
                'content_hash' => hash('sha256', $postContent . $post->post_title),
            );

            // --- AUTHORED_BY ---
            if ($post->post_author) {
                $authorNodeId = Detector::userNodeId($post->post_author);
                $edges[]      = array(
                    'source_node_id' => $nodeId,
                    'target_node_id' => $authorNodeId,
                    'relation'       => 'AUTHORED_BY',
                    'confidence'     => 1.0,
                    'provenance'     => 'EXTRACTED',
                );
            }

            // --- HAS_FEATURED_IMAGE ---
            $thumbId = (int) get_post_thumbnail_id($post->ID);
            if ($thumbId > 0) {
                $mediaNodeId = Detector::mediaNodeId($thumbId);
                $edges[]     = array(
                    'source_node_id' => $nodeId,
                    'target_node_id' => $mediaNodeId,
                    'relation'       => 'HAS_FEATURED_IMAGE',
                    'confidence'     => 1.0,
                    'provenance'     => 'EXTRACTED',
                );
            }

            // --- Taxonomy edges ---
            $taxonomies = get_post_taxonomies($post);
            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post->ID, $taxonomy);
                if (! $terms || is_wp_error($terms)) {
                    continue;
                }
                foreach ($terms as $term) {
                    $termNodeId = Detector::termNodeId($term->term_id, $taxonomy);
                    $relation   = ('category' === $taxonomy) ? 'CATEGORIZED_BY' : 'TAGGED_WITH';
                    $edges[]    = array(
                        'source_node_id' => $nodeId,
                        'target_node_id' => $termNodeId,
                        'relation'       => $relation,
                        'confidence'     => 1.0,
                        'provenance'     => 'EXTRACTED',
                    );
                }
            }

            // --- LINKS_TO (internal links) ---
            $linkEdges = self::extractInternalLinks($post->ID, $post->post_content, $nodeId);
            foreach ($linkEdges as $linkEdge) {
                $edges[] = $linkEdge;
            }
        }

        // Build term nodes.
        foreach ($detected['terms'] as $term) {
            $termNodeId = Detector::termNodeId($term->term_id, $term->taxonomy);
            $termLink   = get_term_link($term);
            $nodes[]    = array(
                'node_id'    => $termNodeId,
                'label'      => $term->name,
                'type'       => 'term',
                'post_id'    => 0,
                'url'        => is_wp_error($termLink) ? '' : $termLink,
                'properties' => array(
                    'taxonomy'    => $term->taxonomy,
                    'slug'        => $term->slug,
                    'count'       => $term->count,
                    'description' => $term->description,
                ),
            );
        }

        // Build user nodes.
        foreach ($detected['users'] as $user) {
            $userNodeId = Detector::userNodeId($user->ID);
            $nodes[]    = array(
                'node_id'    => $userNodeId,
                'label'      => $user->display_name,
                'type'       => 'user',
                'post_id'    => 0,
                'url'        => get_author_posts_url($user->ID),
                'properties' => array(
                    'user_login' => $user->user_login,
                ),
            );
        }

        // Build media nodes.
        foreach ($detected['media'] as $attachment) {
            $mediaNodeId = Detector::mediaNodeId($attachment->ID);
            $nodes[]     = array(
                'node_id'    => $mediaNodeId,
                'label'      => $attachment->post_title ? $attachment->post_title : basename((string) get_attached_file($attachment->ID)),
                'type'       => 'media',
                'post_id'    => $attachment->ID,
                'url'        => wp_get_attachment_url($attachment->ID),
                'properties' => array(
                    'mime_type' => $attachment->post_mime_type,
                ),
            );
        }

        // Build JetEngine CCT nodes.
        if (! empty($detected['ccts']) && is_array($detected['ccts'])) {
            foreach ($detected['ccts'] as $row) {
                if (empty($row['item']['_ID']) || empty($row['type'])) {
                    continue;
                }

                $slug    = sanitize_key($row['type']);
                $item    = $row['item'];
                $itemId  = absint($item['_ID']);
                $nodeId  = Detector::cctNodeId($slug, $itemId);
                $typeName = isset($row['name']) ? (string) $row['name'] : '';
                $label   = self::resolveCctLabel($slug, $item, $typeName);

                $properties = array(
                    'cct_slug' => $slug,
                    'cct_name' => '' !== $typeName ? $typeName : $slug,
                );
                foreach (array('cct_status', 'cct_created', 'cct_modified') as $metaKey) {
                    if (isset($item[$metaKey])) {
                        $properties[$metaKey] = is_scalar($item[$metaKey])
                            ? (string) $item[$metaKey]
                            : '';
                    }
                }

                $contentSource = self::resolveCctContent($item, $slug);

                $nodes[] = array(
                    'node_id'      => $nodeId,
                    'label'        => $label,
                    'type'         => 'cct_' . $slug,
                    'post_id'      => 0,
                    'url'          => '',
                    'properties'   => $properties,
                    'content_hash' => hash('sha256', $label . '|' . $contentSource),
                );

                // AUTHORED_BY edge when the CCT carries an author column.
                if (! empty($item['cct_author_id'])) {
                    $authorNodeId = Detector::userNodeId($item['cct_author_id']);
                    $edges[]      = array(
                        'source_node_id' => $nodeId,
                        'target_node_id' => $authorNodeId,
                        'relation'       => 'AUTHORED_BY',
                        'confidence'     => 1.0,
                        'provenance'     => 'EXTRACTED',
                    );
                }

                /**
                 * Filter per-CCT-row to allow bridges to emit extra structural edges.
                 *
                 * @since 0.8.0
                 * @param array[] $extraEdges Edges to merge; initially empty.
                 * @param string  $slug       CCT slug.
                 * @param array   $item       CCT row (associative array).
                 * @param string  $nodeId     Node ID for this CCT item.
                 */
                $extraEdges = apply_filters('nvoos_graphify_emit_cct_edges', array(), $slug, $item, $nodeId);
                if (is_array($extraEdges) && ! empty($extraEdges)) {
                    foreach ($extraEdges as $extraEdge) {
                        if (is_array($extraEdge)
                            && ! empty($extraEdge['source_node_id'])
                            && ! empty($extraEdge['target_node_id'])
                        ) {
                            $edges[] = $extraEdge;
                        }
                    }
                }
            }
        }

        // Build external $wpdb table nodes.
        if (! empty($detected['external']) && is_array($detected['external'])) {
            foreach ($detected['external'] as $extRow) {
                if (empty($extRow['node_id']) || empty($extRow['node_type'])) {
                    continue;
                }
                $nodes[] = array(
                    'node_id'      => $extRow['node_id'],
                    'label'        => isset($extRow['label']) ? (string) $extRow['label'] : $extRow['node_id'],
                    'type'         => $extRow['node_type'],
                    'post_id'      => 0,
                    'url'          => '',
                    'properties'   => isset($extRow['properties']) ? (array) $extRow['properties'] : array(),
                    'content_hash' => hash('sha256', isset($extRow['label']) ? (string) $extRow['label'] : $extRow['node_id']),
                );

                // Emit FK edges.
                if (! empty($extRow['fk_edges']) && is_array($extRow['fk_edges'])) {
                    foreach ($extRow['fk_edges'] as $fkEdge) {
                        if (is_array($fkEdge) && ! empty($fkEdge['source_node_id']) && ! empty($fkEdge['target_node_id'])) {
                            $edges[] = $fkEdge;
                        }
                    }
                }
            }
        }

        return compact('nodes', 'edges');
    }

    // ─── CCT field resolvers ────────────────────────────────────

    /**
     * Resolve a human-readable label for a JetEngine CCT item.
     *
     * @since 1.0.0
     * @param string $slug     CCT slug (sanitised).
     * @param array  $item     CCT item row (associative array).
     * @param string $typeName Optional human-readable type name.
     * @return string
     */
    public static function resolveCctLabel(string $slug, array $item, string $typeName = ''): string
    {
        $slug = sanitize_key($slug);

        /**
         * Short-circuit: override label resolution entirely for a CCT slug.
         *
         * @since 0.8.0
         * @param string $label '' on first invocation.
         * @param string $slug  CCT slug.
         * @param array  $item  CCT item row.
         */
        $resolvedEarly = (string) apply_filters('nvoos_graphify_cct_resolve_label', '', $slug, $item);
        if ('' !== $resolvedEarly) {
            return $resolvedEarly;
        }

        $labelFields = array('_title', 'title', 'name', 'cct_name', 'label');
        /**
         * Filter the ordered list of CCT item fields checked when resolving a node label.
         *
         * @since 0.7.0
         * @param string[] $labelFields Field names checked in order.
         * @param string   $slug        CCT slug.
         * @param array    $item        CCT item row.
         */
        $labelFields = apply_filters('nvoos_graphify_cct_label_fields', $labelFields, $slug, $item);

        $label = '';
        foreach ((array) $labelFields as $field) {
            $field = (string) $field;
            if ('' === $field) {
                continue;
            }
            if (! empty($item[$field]) && is_scalar($item[$field])) {
                $label = (string) $item[$field];
                break;
            }
        }

        if ('' === $label) {
            $itemId   = isset($item['_ID']) ? absint($item['_ID']) : 0;
            $typeName = '' !== $typeName ? $typeName : $slug;
            /* translators: 1: CCT type name, 2: numeric item ID. */
            $label = sprintf(__('%1$s #%2$d', 'nvoos-graphify'), $typeName, $itemId);
        }

        return $label;
    }

    /**
     * Resolve the primary content field for a JetEngine CCT item.
     *
     * @since 1.0.0
     * @param array  $item CCT item row.
     * @param string $slug Optional CCT slug (sanitised).
     * @return string Content text, or '' when no content-like column is populated.
     */
    public static function resolveCctContent(array $item, string $slug = ''): string
    {
        $slug = sanitize_key($slug);

        /**
         * Short-circuit: override content resolution entirely for a CCT item.
         *
         * @since 0.8.0
         * @param string $content '' on first invocation.
         * @param string $slug    CCT slug.
         * @param array  $item    CCT item row.
         */
        $resolvedEarly = (string) apply_filters('nvoos_graphify_cct_resolve_content', '', $slug, $item);
        if ('' !== $resolvedEarly) {
            return $resolvedEarly;
        }

        $contentFields = array('content', 'description', 'body', 'message', 'text');
        /**
         * Filter the ordered list of CCT item fields checked for body text.
         *
         * @since 0.7.1
         * @param string[] $contentFields Field names checked in order.
         * @param array    $item          CCT item row.
         */
        $contentFields = apply_filters('nvoos_graphify_cct_content_fields', $contentFields, $item);

        foreach ((array) $contentFields as $field) {
            $field = (string) $field;
            if ('' === $field) {
                continue;
            }
            if (! empty($item[$field]) && is_scalar($item[$field])) {
                return (string) $item[$field];
            }
        }

        return '';
    }

    // ─── Internal-link extraction ───────────────────────────────

    /**
     * Parse the post content for internal hrefs and emit LINKS_TO edges.
     *
     * @since 1.0.0
     * @param int    $postId     Source post ID.
     * @param string $content    Raw post content.
     * @param string $sourceNode Source node ID.
     * @return array<int,array<string,mixed>> Edge definition arrays.
     */
    private static function extractInternalLinks(int $postId, string $content, string $sourceNode): array
    {
        $edges   = array();
        $home    = trailingslashit(home_url());
        $matches = array();

        // Match all href attributes.
        if (! preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            return $edges;
        }

        $seen = array();
        foreach ($matches[1] as $href) {
            $href = trim($href);

            // Skip anchors, mailto, tel, external.
            if (empty($href)
                || '#' === $href[0]
                || 0 === strpos($href, 'mailto:')
                || 0 === strpos($href, 'tel:')
                || (0 !== strpos($href, $home) && 0 === strpos($href, 'http'))
            ) {
                continue;
            }

            // Resolve relative URLs.
            if (0 !== strpos($href, 'http')) {
                $href = home_url('/' . ltrim($href, '/'));
            }

            // Deduplicate within this post.
            if (isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;

            $linkedId = url_to_postid($href);
            if ($linkedId && $linkedId !== $postId) {
                $targetNode = Detector::postNodeId($linkedId);
                $edges[]    = array(
                    'source_node_id' => $sourceNode,
                    'target_node_id' => $targetNode,
                    'relation'       => 'LINKS_TO',
                    'confidence'     => 1.0,
                    'provenance'     => 'EXTRACTED',
                    'properties'     => array('href' => esc_url_raw($href)),
                );
            }
        }

        return $edges;
    }
}
