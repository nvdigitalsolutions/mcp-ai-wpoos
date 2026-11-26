<?php

/**
 * One vocabulary for task-template categories.
 *
 * Proposal 164 section 164.79. WordPress has two different lists: the
 * `create_template` validator accepts six categories, and the library that
 * `seed_template_library` ships declares fourteen. Eight of the library's own
 * templates therefore fail validation, and the seeder reports them as errors.
 *
 * The category is a descriptive label, and the library is the only producer of
 * the extra eight, so this class makes the library's list the one list. Create,
 * list and seed all read it, which is what keeps a seeded template valid in the
 * call that created it and visible in the filter that names it.
 *
 * `custom` stays, because it is the documented fallback for a hand-written
 * template and WordPress accepted it before the library existed.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final class TaskTemplateCategory
{
    public const RESEARCH          = 'research';
    public const CONTENT           = 'content';
    public const DATA_ANALYSIS     = 'data_analysis';
    public const DEVELOPMENT       = 'development';
    public const MARKETING         = 'marketing';
    public const CUSTOM            = 'custom';
    public const ECOMMERCE         = 'ecommerce';
    public const SOCIAL_MEDIA      = 'social_media';
    public const FINANCIAL_PLANNING = 'financial_planning';
    public const ANALYTICS         = 'analytics';
    public const VIDEO_PRODUCTION  = 'video_production';
    public const MULTILINGUAL      = 'multilingual';
    public const IMAGE_PRODUCTION  = 'image_production';
    public const CONTENT_MARKETING = 'content_marketing';

    /** The documented fallback when a caller names no category. */
    public const DEFAULT = self::CUSTOM;

    /**
     * Every category, in the WordPress schema's order followed by the toolkit
     * additions in the order the library declares them.
     *
     * @var list<string>
     */
    public const ALL = array(
        self::RESEARCH,
        self::CONTENT,
        self::DATA_ANALYSIS,
        self::DEVELOPMENT,
        self::MARKETING,
        self::CUSTOM,
        self::ECOMMERCE,
        self::SOCIAL_MEDIA,
        self::FINANCIAL_PLANNING,
        self::ANALYTICS,
        self::VIDEO_PRODUCTION,
        self::MULTILINGUAL,
        self::IMAGE_PRODUCTION,
        self::CONTENT_MARKETING,
    );

    public static function isValid(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }

    /**
     * The enum a schema or a validation message shows, with a leading `all`
     * for the listing filter.
     *
     * @return list<string>
     */
    public static function filterValues(): array
    {
        return array_merge(array('all'), self::ALL);
    }
}
