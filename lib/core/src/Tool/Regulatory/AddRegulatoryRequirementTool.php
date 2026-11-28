<?php

/**
 * Create a regulatory requirement.
 *
 * Port of `WP_MCP_AI_Tool_Add_Regulatory_Requirement` (Proposal 164 section
 * 164.162). A requirement is a `mcp_ai_requirement` content item that
 * `check_product_compliance` reads when deciding whether a product may be
 * registered in a country.
 *
 * ## The `is_mandatory` bug this slice exists to fix
 *
 * The handler wrote:
 *
 * ```php
 * update_post_meta( $requirement_id, 'is_mandatory', ! empty( $arguments['is_mandatory'] ) );
 * ```
 *
 * and the schema declares `is_mandatory` with `'default' => true`. So the one
 * caller who follows the schema — omitting the field because the default is
 * documented — stored `false`, and `check_product_compliance` then hit
 * `if ( ! $requirement['is_mandatory'] ) { continue; }` and skipped the
 * requirement entirely. The requirement looked configured and checked nothing.
 *
 * §164.152 recorded this when the compliance reader was ported; this is the
 * writer, and the port reads the flag through `boolParam()` with a `true`
 * default. The regression test creates a requirement without the field and then
 * runs the compliance check against a product that lacks the document.
 *
 * ## The requirement type is validated
 *
 * WordPress accepted any string: the enum was in the schema and nowhere in the
 * handler, so `requirement_type: "loa"` — a document type mistaken for a
 * requirement type — stored a requirement no compliance branch reads. The port
 * requires one of the five declared kinds.
 *
 * ## Dates and URLs are validated
 *
 * `effective_date` was `sanitize_text_field`, which stores any string, and
 * `reference_url` was `esc_url_raw`, which stores an **empty string** for
 * anything it does not recognise — so a malformed citation vanished rather than
 * being refused. The port validates both and names the field.
 *
 * ## Gating
 *
 * The handler read no capability at all, like `get_regulatory_updates` in
 * §164.160. The declaration says `edit_posts`, and for a writer that is the
 * family's rule, so the port enforces it.
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
use Nvoos\Core\Domain\Entity\CreateContentCommand;

class AddRegulatoryRequirementTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'add_regulatory_requirement';
    }

    public function getName(): string
    {
        return 'Add Regulatory Requirement';
    }

    public function getDescription(): string
    {
        return 'Stores a regulatory requirement for a country and authority: its kind, whether it is mandatory, the product category it applies to, an effective date and a reference URL. Mandatory defaults to true, which is what the schema documents and what the compliance check reads.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'title'            => array(
                    'type'        => 'string',
                    'description' => 'Requirement title, as the compliance check matches it.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'country'          => array(
                    'type'        => 'string',
                    'description' => 'Country code, e.g. LK, AE, SA.',
                    'minLength'   => 1,
                    'maxLength'   => 10,
                ),
                'authority'        => array(
                    'type'        => 'string',
                    'description' => 'Regulatory authority, e.g. NMRA, MOHAP, SFDA.',
                    'minLength'   => 1,
                    'maxLength'   => 100,
                ),
                'requirement_type' => array(
                    'type'        => 'string',
                    'description' => 'What kind of requirement this is.',
                    'enum'        => self::REQUIREMENT_TYPES,
                ),
                'description'      => array(
                    'type'        => 'string',
                    'description' => 'What the requirement asks for.',
                    'maxLength'   => 4000,
                ),
                'is_mandatory'     => array(
                    'type'        => 'boolean',
                    'description' => 'Whether the compliance check enforces it. Default true.',
                    'default'     => true,
                ),
                'product_category' => array(
                    'type'        => 'string',
                    'description' => 'The only product category it applies to. Omit for all.',
                    'maxLength'   => 100,
                ),
                'effective_date'   => array(
                    'type'        => 'string',
                    'description' => 'The date the requirement takes effect (YYYY-MM-DD).',
                    'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
                ),
                'reference_url'    => array(
                    'type'        => 'string',
                    'description' => 'A URL to the official text.',
                ),
            ),
            'required'             => array('title', 'country', 'authority', 'requirement_type'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId = (int) ( $context['user_id'] ?? 0 );

        $this->authorize($context, 'edit_posts');

        $title      = trim($this->stringParam($arguments, 'title'));
        $country    = trim($this->stringParam($arguments, 'country'));
        $authority  = trim($this->stringParam($arguments, 'authority'));
        $type       = trim($this->stringParam($arguments, 'requirement_type'));
        $category   = trim($this->stringParam($arguments, 'product_category'));
        $effective  = trim($this->stringParam($arguments, 'effective_date'));
        $reference  = trim($this->stringParam($arguments, 'reference_url'));

        $fields = array();

        if ('' === $title) {
            $fields['title'] = array('A requirement title.');
        }

        if ('' === $country) {
            $fields['country'] = array('A country code.');
        }

        if ('' === $authority) {
            $fields['authority'] = array('An authority name.');
        }

        if ('' === $type) {
            $fields['requirement_type'] = array('A requirement type.');
        } elseif (! in_array($type, self::REQUIREMENT_TYPES, true)) {
            $fields['requirement_type'] = array(
                'One of: ' . implode(', ', self::REQUIREMENT_TYPES) . '.',
            );
        }

        if ('' !== $effective && ! $this->isIsoDate($effective)) {
            $fields['effective_date'] = array('A date in YYYY-MM-DD form.');
        }

        if ('' !== $reference && false === filter_var($reference, FILTER_VALIDATE_URL)) {
            $fields['reference_url'] = array('A URL with a scheme and a host.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The requirement cannot be created.', $fields);
        }

        // The bug this slice fixes: omitted means true, as the schema documents.
        $mandatory = $this->boolParam($arguments, 'is_mandatory', true);

        $meta = array(
            'country'          => $country,
            'authority'        => $authority,
            'requirement_type' => $type,
            'is_mandatory'     => $mandatory,
            'created_at'       => $this->nowStamp(),
            'created_by'       => $userId,
        );

        if ('' !== $category) {
            $meta['product_category'] = $category;
        }

        if ('' !== $effective) {
            $meta['effective_date'] = $effective;
        }

        if ('' !== $reference) {
            $meta['reference_url'] = $reference;
        }

        try {
            $item = $this->content->create(
                new CreateContentCommand(
                    title: $title,
                    type: self::REQUIREMENT_TYPE,
                    authorId: $userId,
                    status: 'publish',
                    content: trim($this->stringParam($arguments, 'description')),
                    meta: $meta,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('add_regulatory_requirement_failed', $e->getMessage());
        }

        return $this->success(
            'Regulatory requirement created successfully.',
            array(
                'requirement_id'   => $item->id,
                'title'            => $title,
                'country'          => $country,
                'authority'        => $authority,
                'requirement_type' => $type,
                'is_mandatory'     => $mandatory,
                'product_category' => $category,
                'effective_date'   => $effective,
                'reference_url'    => $reference,
                'created_at'       => $meta['created_at'],
            )
        );
    }
}
