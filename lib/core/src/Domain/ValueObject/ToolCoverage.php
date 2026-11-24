<?php

/**
 * What this host can actually do, not what its worker can.
 *
 * `MediaWorkerCapabilityReport` answers "can the worker render a PDF?" — a
 * capability question. `DiagnosticsPage` rendered that answer under a label an
 * operator reads as "can this host render a PDF?", and for social the two
 * answers were opposite: the worker declared `social_publishing` whenever a
 * platform key was set, while the runtime served **zero** social tools. The page
 * said `Social: worker` and meant "the worker could, if anything here asked it
 * to".
 *
 * That is the failure this class exists to prevent. It intersects the worker's
 * matrix with the manifest's servable set and reports a fourth tier:
 *
 *   - **`served`** — the worker declares the domain *and* this host has at
 *     least one servable tool that reaches it.
 *   - **`worker_only`** — the worker declares it and no tool here does. An
 *     operator reading this has found either missing work or a stale console
 *     control, and both are actionable.
 *   - **`unavailable`** — the worker said it cannot (no key, no binary).
 *   - **`unknown`** — the worker is configured but unreachable.
 *
 * ## The toolkit map is declared, not inferred
 *
 * A tool's domain cannot be derived from its slug: `send_group_email` is email,
 * `generate_email_template` is a document, and `generate_music` is neither. A
 * prefix rule would put them in the same bucket and be subtly wrong forever.
 * The map is therefore data, and `ToolCoverageTest` fails when it names a slug
 * the manifest does not serve.
 *
 * A domain with an **empty** list is deliberate and meaningful: `video` and
 * `browser` are declared by the worker with no tool on this host driving them,
 * which is exactly the state an operator needs to see.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final class ToolCoverage
{
    /** The worker declares the domain and a servable tool reaches it. */
    public const TIER_SERVED = 'served';

    /** The worker declares the domain and nothing on this host reaches it. */
    public const TIER_WORKER_ONLY = 'worker_only';

    /** The worker said it cannot serve the domain. */
    public const TIER_UNAVAILABLE = 'unavailable';

    /** A worker is configured but did not answer. */
    public const TIER_UNKNOWN = 'unknown';

    /**
     * Domain => the tool slugs that reach it on this host.
     *
     * Keys are `MediaWorkerCapabilityReport`'s own, so the two cannot drift on
     * spelling. Every slug here must be servable in the manifest.
     *
     * @var array<string, list<string>>
     */
    private const TOOLS = array(
        'documents' => array(
            'html_to_pdf',
            'add_watermark_to_pdf',
            'merge_pdfs',
            'extract_pdf_text',
            'ocr_pdf_text',
        ),
        'office' => array(
            'generate_word',
            'generate_excel',
            'excel_data_import',
        ),
        'ocr' => array(
            'ocr_pdf_text',
            'extract_image_text',
        ),
        'image' => array(
            'resize_image',
            'crop_image',
            'rotate_image',
            'convert_image_format',
            'compress_image',
            'optimize_for_web',
            'enhance_image_quality',
            'upscale_image_ai',
            'optimise_images_batch',
            'generate_responsive_images',
            'apply_artistic_style',
            'colorize_image',
            'get_unoptimised_images',
            'get_images_without_alt',
        ),
        // Deliberate empty: the worker transcodes, this host has no tool that
        // asks it to. Video generation is a provider call, not `/api/video/process`.
        'video' => array(),
        'email' => array(
            'send_group_email',
            'send_group_email_validated',
        ),
        'data' => array(
            'create_chart',
            'create_chart_validated',
            'generate_chart',
        ),
        // Deliberate empty: `/api/browser/*` is not driven by any ported tool.
        'browser' => array(),
        'crawl' => array(
            'run_crawl4ai_job',
            'crawl4ai_price_lookup',
        ),
        'social' => array(
            'get_social_accounts',
            'generate_social_captions',
            'generate_post_ideas',
        ),
        // Publishing is its own domain precisely so it can read `worker_only`
        // while `social` reads `served`.
        'social_publishing' => array(),
    );

    /**
     * @param array<string, list<string>> $servableByDomain Servable slugs, filtered per domain.
     */
    private function __construct(
        private readonly MediaWorkerCapabilityReport $report,
        private readonly array $servableByDomain,
    ) {
    }

    /**
     * Intersect the worker's matrix with the host's servable set.
     *
     * @param list<string> $servableSlugs The manifest's servable slugs.
     */
    public static function fromReport(MediaWorkerCapabilityReport $report, array $servableSlugs): self
    {
        $servable = \array_fill_keys(\array_map('strval', $servableSlugs), true);
        $byDomain = array();

        foreach (self::TOOLS as $domain => $slugs) {
            $byDomain[$domain] = array();

            foreach ($slugs as $slug) {
                if (isset($servable[$slug])) {
                    $byDomain[$domain][] = $slug;
                }
            }
        }

        return new self($report, $byDomain);
    }

    /**
     * The effective tier: the worker's answer, narrowed by what this host serves.
     */
    public function tier(string $domain): string
    {
        $worker = $this->report->tier($domain);

        // A worker that cannot (or did not answer) is the whole story: a tool
        // that exists but cannot reach its engine is not coverage.
        if (MediaWorkerCapabilityReport::TIER_WORKER !== $worker) {
            return $worker;
        }

        return array() === $this->tools($domain) ? self::TIER_WORKER_ONLY : self::TIER_SERVED;
    }

    /**
     * The servable tools that reach one domain, in declaration order.
     *
     * @return list<string>
     */
    public function tools(string $domain): array
    {
        return $this->servableByDomain[$domain] ?? array();
    }

    /**
     * Every domain this host knows about, with its tier and tools.
     *
     * @return array<string, array{tier: string, tools: list<string>}>
     */
    public function domains(): array
    {
        $domains = array();

        foreach (\array_keys(self::TOOLS) as $domain) {
            $domains[$domain] = array(
                'tier' => $this->tier($domain),
                'tools' => $this->tools($domain),
            );
        }

        return $domains;
    }

    /**
     * A serialisable shape for diagnostics and the console.
     *
     * @return array{
     *     domains: array<string, array{tier: string, tools: list<string>}>,
     *     worker_only: list<string>
     * }
     */
    public function toArray(): array
    {
        $workerOnly = array();

        foreach ($this->domains() as $domain => $row) {
            if (self::TIER_WORKER_ONLY === $row['tier']) {
                $workerOnly[] = $domain;
            }
        }

        return array(
            'domains' => $this->domains(),
            'worker_only' => $workerOnly,
        );
    }

    /**
     * A one-line value for a diagnostics row.
     *
     * `served` names the count so an operator can tell one tool from twenty;
     * `worker_only` says "nothing here" rather than printing a bare tier, because
     * the tier alone is the sentence that misled the page in the first place.
     */
    public function describe(string $domain): string
    {
        $tier = $this->tier($domain);

        if (self::TIER_SERVED !== $tier) {
            return self::TIER_WORKER_ONLY === $tier ? 'worker available, no tool here' : $tier;
        }

        $count = \count($this->tools($domain));

        return \sprintf('%s (%d tool%s)', $tier, $count, 1 === $count ? '' : 's');
    }

    /**
     * Every domain this class knows how to ask about.
     *
     * @return list<string>
     */
    public static function knownDomains(): array
    {
        return \array_keys(self::TOOLS);
    }
}
