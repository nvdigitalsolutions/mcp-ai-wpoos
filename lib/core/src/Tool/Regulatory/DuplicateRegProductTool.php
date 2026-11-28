<?php

/**
 * Duplicate a regulatory product — `mcp_ai_reg_product`.
 *
 * Port of `WP_MCP_AI_Tool_Duplicate_Reg_Product` (Proposal 164 section 164.145).
 * The copy starts as a draft, keeps the source content, and copies metadata and
 * taxonomies by default while leaving registrations and documents behind unless
 * asked for — because a duplicated *registration* is a regulatory claim, and
 * copying one by default would invent an approval nobody applied for.
 *
 * ## The declared defaults are applied here, not assumed upstream
 *
 * WordPress declared `copy_meta` and `copy_taxonomies` as defaulting to `true`
 * and then read them with `! empty( $arguments[ ... ] )`. A caller that omitted
 * the flag therefore got `false` behaviour from a schema that promised `true` —
 * the declared contract and the actual one disagreed. The port applies the
 * documented defaults; only an explicit `false` turns a copy off.
 *
 * ## Registrations copy the reference, never the evidence
 *
 * A copied registration carries `country`, `authority` and
 * `registration_type`, and deliberately not the COS number, submission date or
 * approval date: those belong to the original application. The copy is a draft,
 * which is what makes it a starting point rather than a false record.
 *
 * ## The capability check is added
 *
 * The WordPress handler performed no capability check at all before creating
 * content — the only write tool in the family without one. The port requires
 * `edit_posts` like its siblings.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;

class DuplicateRegProductTool extends RegulatoryTool
{
    /** The most satellite records one call will copy, as WordPress capped it. */
    private const MAX_COPIES = 1000;

    /** The store's per-page ceiling. */
    private const COPY_PAGE = 200;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'duplicate_reg_product';
    }

    public function getName(): string
    {
        return 'Duplicate Regulatory Product';
    }

    public function getDescription(): string
    {
        return 'Copies a product into a new draft, optionally with its metadata, taxonomies, registrations (reference fields only) and documents. Useful for variants.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'         => array(
                    'type'        => 'integer',
                    'description' => 'The product id to duplicate.',
                    'minimum'     => 1,
                ),
                'new_title'          => array(
                    'type'        => 'string',
                    'description' => 'Title for the copy. Defaults to "[Copy] " plus the original title.',
                    'maxLength'   => 200,
                ),
                'copy_meta'          => array(
                    'type'        => 'boolean',
                    'description' => 'Copy the product metadata. Default true.',
                    'default'     => true,
                ),
                'copy_taxonomies'    => array(
                    'type'        => 'boolean',
                    'description' => 'Copy the category and brand terms. Default true.',
                    'default'     => true,
                ),
                'copy_registrations' => array(
                    'type'        => 'boolean',
                    'description' => 'Copy registrations as drafts, without COS number or dates. Default false.',
                    'default'     => false,
                ),
                'copy_documents'     => array(
                    'type'        => 'boolean',
                    'description' => 'Copy document records. Default false.',
                    'default'     => false,
                ),
            ),
            'required'             => array('product_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id   = (int) ($context['user_id'] ?? 0);
        $productId = max(0, $this->intParam($arguments, 'product_id'));

        if ($productId <= 0) {
            return $this->errors->validationFailed(
                'A product id is required.',
                array('product_id' => array('The id shown by list_reg_products.'))
            );
        }

        $source = $this->content->find($productId);

        if (null === $source || self::PRODUCT_TYPE !== $source->type) {
            return $this->errors->notFound('Source product not found.');
        }

        $title = trim($this->stringParam($arguments, 'new_title'));

        if ('' === $title) {
            $title = sprintf('[Copy] %s', $source->title);
        }

        $meta = array();

        if ($this->boolParam($arguments, 'copy_meta', true)) {
            foreach (self::PRODUCT_FIELDS as $key) {
                $value = $this->text($source->meta[ $key ] ?? null);

                if ('' !== $value) {
                    $meta[ $key ] = $value;
                }
            }
        }

        $taxonomy = array();

        if ($this->boolParam($arguments, 'copy_taxonomies', true)) {
            $terms = $this->terms($source);

            foreach (array(self::CATEGORY_TAX, self::BRAND_TAX) as $tax) {
                $names = $this->termNames($terms, $tax);

                if (array() !== $names) {
                    $taxonomy[ $tax ] = $names;
                }
            }
        }

        try {
            $copy = $this->content->create(
                new CreateContentCommand(
                    title: $title,
                    type: self::PRODUCT_TYPE,
                    authorId: $user_id,
                    status: 'draft',
                    content: $source->content,
                    excerpt: $source->excerpt,
                    meta: $meta,
                    taxonomyInput: $taxonomy,
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('duplicate_reg_product_failed', $e->getMessage());
        }

        $copied = array('product' => true);

        if (array() !== $meta) {
            $copied['metadata'] = true;
        }

        if (array() !== $taxonomy) {
            $copied['taxonomies'] = true;
        }

        if ($this->boolParam($arguments, 'copy_registrations')) {
            $copied['registrations'] = $this->copyRegistrations($productId, $copy->id, $user_id);
        }

        if ($this->boolParam($arguments, 'copy_documents')) {
            $copied['documents'] = $this->copyDocuments($productId, $copy->id, $user_id);
        }

        return $this->success(
            sprintf('Product duplicated successfully: %s', $title),
            array(
                'new_product_id'    => $copy->id,
                'new_title'         => $title,
                'source_product_id' => $productId,
                'copied_items'      => $copied,
            )
        );
    }

    /**
     * Copy the registrations that point at one product, as drafts.
     *
     * @return int The number copied.
     */
    private function copyRegistrations(int $sourceId, int $destId, int $userId): int
    {
        $count = 0;

        foreach ($this->related(self::REGISTRATION_TYPE, $sourceId) as $registration) {
            $meta = $registration->meta;

            try {
                $this->content->create(
                    new CreateContentCommand(
                        title: $registration->title,
                        type: self::REGISTRATION_TYPE,
                        authorId: $userId,
                        status: 'draft',
                        content: $registration->content,
                        meta: array(
                            'product_id'        => $destId,
                            'country'           => $this->text($meta['country'] ?? null),
                            'authority'         => $this->text($meta['authority'] ?? null),
                            'registration_type' => $this->text($meta['registration_type'] ?? null),
                        ),
                    )
                );
            } catch (\Throwable $e) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * Copy the document records that point at one product.
     *
     * @return int The number copied.
     */
    private function copyDocuments(int $sourceId, int $destId, int $userId): int
    {
        $count = 0;

        foreach ($this->related(self::DOCUMENT_TYPE, $sourceId) as $document) {
            $meta = $document->meta;

            try {
                $this->content->create(
                    new CreateContentCommand(
                        title: $document->title,
                        type: self::DOCUMENT_TYPE,
                        authorId: $userId,
                        status: 'publish',
                        content: $document->content,
                        meta: array(
                            'product_id'    => $destId,
                            'document_type' => $this->text($meta['document_type'] ?? null),
                            'file_url'      => $this->text($meta['file_url'] ?? null),
                            'version'       => $this->text($meta['version'] ?? null),
                            'issue_date'    => $this->text($meta['issue_date'] ?? null),
                            'expiry_date'   => $this->text($meta['expiry_date'] ?? null),
                        ),
                    )
                );
            } catch (\Throwable $e) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * The records of one type that point at a product.
     *
     * Read in pages of the store's ceiling until the set is exhausted or the
     * WordPress cap is reached.
     *
     * @return list<ContentItem>
     */
    private function related(string $type, int $productId): array
    {
        $rows = array();
        $page = 1;

        while (count($rows) < self::MAX_COPIES) {
            try {
                $result = $this->content->query(
                    new ContentQuery(
                        types: array($type),
                        statuses: array('publish'),
                        metaQuery: array(
                            array('key' => 'product_id', 'value' => $productId, 'compare' => '='),
                        ),
                        orderBy: 'date',
                        order: 'DESC',
                        page: $page,
                        perPage: self::COPY_PAGE,
                    )
                );
            } catch (\Throwable $e) {
                break;
            }

            $fetched = 0;

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || $type !== $item->type) {
                    continue;
                }

                $rows[] = $item;
                ++$fetched;

                if (count($rows) >= self::MAX_COPIES) {
                    break;
                }
            }

            if ($fetched < self::COPY_PAGE) {
                break;
            }

            ++$page;
        }

        return $rows;
    }

    /**
     * The terms attached to one item, read defensively.
     *
     * @return array<string, array<int, string>>
     */
    private function terms(ContentItem $item): array
    {
        try {
            return $this->content->getTaxonomyTerms($item->id);
        } catch (\Throwable $e) {
            return array();
        }
    }
}
