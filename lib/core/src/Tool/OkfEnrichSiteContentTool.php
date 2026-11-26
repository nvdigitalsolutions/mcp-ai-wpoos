<?php

/**
 * OKF enrichment — turn published content into a knowledge bundle.
 *
 * Proposal 164 §164.50. The WordPress agent crawls published posts and terms and
 * writes one OKF concept per item, deterministically, so a re-run refreshes rather
 * than duplicates. This port keeps that contract and changes only what the host
 * forces it to.
 *
 * ## The record has no URL here
 *
 * WordPress fills `resource` and `sources[].resource` from `get_permalink()`. A
 * `ContentItem` on this runtime carries a slug but no public URL, and inventing one
 * from a guessed permalink shape would put a wrong link in an authoritative
 * document. So the caller may pass a `resource_template` (`{type}`, `{slug}` and
 * `{id}` are substituted); when it does not, the `resource` key is **omitted** and
 * the summary says so. Related-concept links need the same URLs, so they are
 * omitted with it rather than written pointing at nothing.
 *
 * ## Idempotent by identity, not by comparison
 *
 * A concept's id is `{type}/{slug}`, so writing twice overwrites the same file.
 * That is the idempotence the reference promises; there is no diff, and a changed
 * description simply wins.
 *
 * ## Deterministic descriptions
 *
 * The description is the record's excerpt, or the first 40 words of its content.
 * The WordPress tool lets a filter replace it with a model summary; here that is a
 * deliberate absence rather than a missing feature — an enrichment run that calls a
 * model once per record is a different cost profile, and a caller that wants it can
 * summarise first and enrich after.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use InvalidArgumentException;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\OkfBundleLocatorInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\PostTypeSchema;
use Nvoos\Core\Domain\Okf\OkfWriter;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

class OkfEnrichSiteContentTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const DEFAULT_BUNDLE = 'site-content';

    private const MAX_ITEMS = 200;

    private const MAX_TERMS = 100;

    private const MAX_LINKS = 20;

    private const DESCRIPTION_WORDS = 40;

    private const BODY_WORD_CAP = 400;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly SchemaStoreInterface $schema,
        private readonly OkfBundleLocatorInterface $bundles,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'okf_enrich_site_content';
    }

    public function getName(): string
    {
        return 'OKF — Enrich from Site Content';
    }

    public function getDescription(): string
    {
        return 'Crawls published content (posts, pages, any registered type, optionally taxonomy terms) and generates OKF concepts with cross-links into a bundle. Deterministic and idempotent: a concept id is {type}/{slug}, so re-running refreshes the same files. Pass resource_template (e.g. "https://example.com/{type}/{slug}") to fill the resource links; without it they are omitted and reported.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'bundle' => array(
                    'type' => 'string',
                    'description' => 'Target bundle name, created on first run. Default: site-content.',
                    'default' => self::DEFAULT_BUNDLE,
                ),
                'post_types' => array(
                    'type' => 'array',
                    'description' => 'Content types to crawl. Default: post and page.',
                    'items' => array('type' => 'string'),
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Maximum content items. Default 50, hard cap 200.',
                    'minimum' => 1,
                    'maximum' => self::MAX_ITEMS,
                    'default' => 50,
                ),
                'include_terms' => array(
                    'type' => 'boolean',
                    'description' => 'Also generate concepts for taxonomy terms. Default: false.',
                    'default' => false,
                ),
                'include_content' => array(
                    'type' => 'boolean',
                    'description' => 'Include a trimmed copy of the content in the concept body. Default: true.',
                    'default' => true,
                ),
                'resource_template' => array(
                    'type' => 'string',
                    'description' => 'Public URL template for a record, e.g. "https://example.com/{type}/{slug}". {type}, {slug} and {id} are substituted. Omit when the host has no public URLs; the concept then carries no resource link.',
                ),
                'dry_run' => array(
                    'type' => 'boolean',
                    'description' => 'Report what would be written without touching the bundle. Default: false.',
                    'default' => false,
                ),
            ),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        // The reference requires administrator because it writes many files.
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $bundle = $this->stringParam($arguments, 'bundle', self::DEFAULT_BUNDLE);
        $template = $this->stringParam($arguments, 'resource_template');
        $limit = max(1, min(self::MAX_ITEMS, $this->intParam($arguments, 'limit', 50)));
        $includeTerms = $this->boolParam($arguments, 'include_terms');
        $includeContent = $this->boolParam($arguments, 'include_content', true);
        $dryRun = $this->isDryRun($arguments);

        $types = $this->stringListParam($arguments, 'post_types');

        if (array() === $types) {
            $types = array('post', 'page');
        }

        try {
            $root = $this->bundles->rootFor($bundle, ! $dryRun);
        } catch (InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage(), array('bundle' => array($e->getMessage())));
        }

        // Only a real run needs a root: a rehearsal computes the same concepts
        // without a writer, so a bundle that does not exist yet is a fact to
        // report rather than a reason to refuse.
        if (null === $root && ! $dryRun) {
            return $this->errors->create(
                'okf_bundle_missing',
                sprintf('Bundle "%s" does not exist. Re-run without dry_run to create it.', $bundle),
            );
        }

        try {
            $page = $this->content->query(new ContentQuery(
                types: $types,
                statuses: array('publish'),
                orderBy: 'id',
                order: 'ASC',
                page: 1,
                perPage: $limit,
            ));
        } catch (\Throwable $e) {
            return $this->errors->create('okf_content_query_failed', $e->getMessage());
        }

        if (! $page->hasItems()) {
            return $this->errors->create(
                'okf_no_content',
                sprintf('No published content matched: %s. Check post_types.', implode(', ', $types)),
            );
        }

        $items = array();

        foreach ($page->items as $item) {
            if ($item instanceof ContentItem) {
                $items[] = $item;
            }
        }

        $urlMap = array();

        foreach ($items as $item) {
            $url = $this->resourceUrl($template, $item);

            if (null !== $url) {
                $urlMap[$this->normalizeUrl($url)] = $this->conceptIdFor($item);
            }
        }

        $writer = null === $root ? null : new OkfWriter($root);
        $created = array();
        $errors = array();
        $skipped = 0;

        foreach ($items as $item) {
            $conceptId = $this->conceptIdFor($item);

            try {
                $concept = $this->buildConcept($item, $includeContent, $urlMap, $template);

                if (! $dryRun && null !== $writer) {
                    $writer->writeConcept($conceptId, $concept['frontmatter'], $concept['body']);
                }

                $created[] = $conceptId;
            } catch (\Throwable $e) {
                ++$skipped;
                $errors[] = $conceptId . ': ' . $e->getMessage();
            }
        }

        $terms = array();

        if ($includeTerms) {
            $terms = $this->enrichTerms($writer, $types, min($limit, self::MAX_TERMS), $template, $dryRun, $created, $errors);
        }

        if (! $dryRun && null !== $writer) {
            try {
                $writer->regenerateIndex('');

                foreach ($types as $type) {
                    $writer->regenerateIndex($type);
                }

                if ($includeTerms) {
                    $writer->regenerateIndex('terms');
                }

                $writer->appendLog(
                    '',
                    sprintf('Enrichment generated %d concept(s) from site content.', count($created)),
                    'Creation',
                );
            } catch (\Throwable $e) {
                $errors[] = 'index: ' . $e->getMessage();
            }
        }

        return $this->success(
            sprintf(
                '%s %d concept(s) in "%s" (%d skipped).',
                $dryRun ? 'Dry run:' : 'Wrote',
                count($created),
                $bundle,
                $skipped,
            ),
            array(
                'bundle' => $bundle,
                'dry_run' => $dryRun,
                'concepts' => count($created),
                'terms' => $terms,
                'skipped' => $skipped,
                'errors' => $errors,
                'bundle_exists' => null !== $root,
                'resource_links' => '' !== $template,
                'concept_ids' => $created,
            ),
        );
    }

    // ── Concept construction ─────────────────────────────────────────────────

    /**
     * A concept's identity: `{type}/{slug}`, which is what makes a re-run an
     * overwrite rather than a duplicate.
     */
    private function conceptIdFor(ContentItem $item): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) ($item->slug ?? '')));
        $slug = trim($slug, '-');

        if ('' === $slug) {
            $slug = 'item-' . $item->id;
        }

        return $item->type . '/' . $slug;
    }

    /**
     * @param array<string, string> $urlMap Normalised URL → concept id.
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    private function buildConcept(ContentItem $item, bool $includeContent, array $urlMap, string $template): array
    {
        $conceptId = $this->conceptIdFor($item);
        $modified = $item->updatedAt->format('c');

        $description = '' !== trim((string) ($item->excerpt ?? ''))
            ? $this->stripAndTrim((string) $item->excerpt, self::DESCRIPTION_WORDS)
            : $this->stripAndTrim($item->content, self::DESCRIPTION_WORDS);

        $url = $this->resourceUrl($template, $item);

        $frontmatter = array(
            'type' => $this->typeLabel($item->type),
            'title' => $item->title,
            'description' => $description,
            'tags' => $this->tagNames($item),
            'status' => 'stable',
            'generated' => array('by' => 'process:okf-enrichment', 'at' => $modified),
            'sources' => array(
                array(
                    'id' => 'content-' . $item->type . '-' . $item->id,
                    'title' => $item->title,
                    'last_modified' => $modified,
                ),
            ),
        );

        if (null !== $url) {
            $frontmatter['resource'] = $url;
            $frontmatter['sources'][0]['resource'] = $url;
        }

        $body = "# Summary\n\n" . $description . "\n";

        if ($includeContent && '' !== trim($item->content)) {
            $body .= "\n# Content\n\n" . $this->stripAndTrim($item->content, self::BODY_WORD_CAP) . "\n";
        }

        $related = $this->relatedLinks($item->content, $urlMap, $conceptId);

        if (array() !== $related) {
            $body .= "\n# Related\n\n";

            foreach ($related as $link) {
                $body .= '* [' . $link['title'] . '](/' . $link['concept_id'] . ".md)\n";
            }
        }

        return array('frontmatter' => $frontmatter, 'body' => trim($body));
    }

    /**
     * Internal links that point at another crawled record, as bundle-relative
     * paths (OKF v0.2 §6.1).
     *
     * @param array<string, string> $urlMap
     * @return list<array{title: string, concept_id: string}>
     */
    private function relatedLinks(string $content, array $urlMap, string $selfId): array
    {
        if (array() === $urlMap || '' === trim($content)) {
            return array();
        }

        if (! preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            return array();
        }

        $related = array();
        $seen = array();

        foreach ($matches as $match) {
            if (count($related) >= self::MAX_LINKS) {
                break;
            }

            $target = $urlMap[$this->normalizeUrl($match[1])] ?? null;

            if (null === $target || $target === $selfId || isset($seen[$target])) {
                continue;
            }

            $seen[$target] = true;
            $title = trim(strip_tags($match[2]));

            $related[] = array(
                'title' => '' !== $title ? $title : $target,
                'concept_id' => $target,
            );
        }

        return $related;
    }

    private function resourceUrl(string $template, ContentItem $item): ?string
    {
        return $this->fillTemplate($template, $item->type, (string) ($item->slug ?? ''), $item->id);
    }

    /**
     * Substitute `{type}`, `{slug}` and `{id}`; null when no template was given.
     */
    private function fillTemplate(string $template, string $type, string $slug, int $id): ?string
    {
        if ('' === trim($template)) {
            return null;
        }

        $url = str_replace(
            array('{type}', '{slug}', '{id}'),
            array($type, '' !== $slug ? $slug : (string) $id, (string) $id),
            $template,
        );

        return '' !== trim($url) ? trim($url) : null;
    }

    /**
     * Scheme-insensitive, fragment-free, no trailing slash, lowercased.
     */
    private function normalizeUrl(string $url): string
    {
        $url = (string) preg_replace('#^https?://#i', '', trim($url));
        $url = (string) preg_replace('/#.*$/', '', $url);

        return strtolower(rtrim($url, '/'));
    }

    /**
     * @return list<string>
     */
    private function tagNames(ContentItem $item): array
    {
        $names = array();

        foreach ($item->taxonomy as $terms) {
            if (! is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $name = '';

                if (is_string($term)) {
                    $name = $term;
                } elseif (is_array($term) && isset($term['name']) && is_string($term['name'])) {
                    $name = $term['name'];
                }

                if ('' !== $name) {
                    $names[$name] = true;
                }

                if (count($names) >= 10) {
                    break 2;
                }
            }
        }

        return array_keys($names);
    }

    private function typeLabel(string $type): string
    {
        $schema = $this->schema->getPostType($type);

        if ($schema instanceof PostTypeSchema && '' !== trim($schema->label)) {
            return $schema->label;
        }

        return ucfirst($type);
    }

    /**
     * Strip markup, collapse whitespace and cap the word count.
     */
    private function stripAndTrim(string $html, int $words): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = preg_split('/\s+/u', trim($text));

        if (! is_array($parts)) {
            return '';
        }

        $parts = array_values(array_filter($parts, static fn ($part): bool => '' !== $part));

        if (count($parts) <= $words) {
            return implode(' ', $parts);
        }

        return implode(' ', array_slice($parts, 0, $words)) . '…';
    }

    /**
     * Write one concept per taxonomy term.
     *
     * @param list<string>         $types
     * @param list<string>         $created      Appended to, by reference.
     * @param list<string>         $errors       Appended to, by reference.
     * @return list<string> Concept ids written for terms.
     */
    private function enrichTerms(
        ?OkfWriter $writer,
        array $types,
        int $limit,
        string $template,
        bool $dryRun,
        array &$created,
        array &$errors,
    ): array {
        $written = array();
        $seen = array();

        foreach ($types as $type) {
            foreach ($this->schema->listTaxonomies($type) as $taxonomy) {
                if (isset($seen[$taxonomy->slug])) {
                    continue;
                }

                $seen[$taxonomy->slug] = true;

                try {
                    $terms = $this->content->listTaxonomyTerms($taxonomy->slug, $limit);
                } catch (\Throwable $e) {
                    $errors[] = $taxonomy->slug . ': ' . $e->getMessage();
                    continue;
                }

                foreach ($terms as $term) {
                    $slug = (string) ($term['slug'] ?? '');
                    $id = (int) ($term['id'] ?? 0);

                    if ('' === $slug) {
                        continue;
                    }

                    $conceptId = 'terms/' . $taxonomy->slug . '/' . $slug;
                    $url = $this->fillTemplate($template, $taxonomy->slug, $slug, $id);

                    $frontmatter = array(
                        'type' => 'Term',
                        'title' => (string) ($term['name'] ?? $slug),
                        'description' => '',
                        'tags' => array($taxonomy->slug),
                        'status' => 'stable',
                        'generated' => array('by' => 'process:okf-enrichment', 'at' => gmdate('c')),
                        'sources' => array(
                            array(
                                'id' => 'term-' . $taxonomy->slug . '-' . $id,
                                'title' => (string) ($term['name'] ?? $slug) . ' (' . $taxonomy->label . ')',
                            ),
                        ),
                    );

                    if (null !== $url) {
                        $frontmatter['resource'] = $url;
                        $frontmatter['sources'][0]['resource'] = $url;
                    }

                    $body = "# Summary\n\n" . sprintf(
                        '%s (%s) — %d published item(s).',
                        (string) ($term['name'] ?? $slug),
                        $taxonomy->label,
                        (int) ($term['count'] ?? 0),
                    );

                    try {
                        if (! $dryRun && null !== $writer) {
                            $writer->writeConcept($conceptId, $frontmatter, $body);
                        }

                        $created[] = $conceptId;
                        $written[] = $conceptId;
                    } catch (\Throwable $e) {
                        $errors[] = $conceptId . ': ' . $e->getMessage();
                    }
                }
            }
        }

        return $written;
    }
}
