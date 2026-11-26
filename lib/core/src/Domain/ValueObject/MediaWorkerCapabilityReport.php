<?php

/**
 * What the media worker can do, as one honest report.
 *
 * Proposal 164 §164.4.2. The worker answers `GET /api/health/full` with a
 * capability matrix — `pdf_generation`, `video_processing`, `document_ocr`,
 * `browser_automation` and thirty more. Reading it once and reporting it is
 * what stops every later phase from discovering the truth by failing: a tool
 * asks `tier( 'video' )` before it spends a request, and diagnostics says
 * `video: unavailable` instead of offering a control that always errors.
 *
 * ## Three tiers, because "no" has two meanings
 *
 * - **`worker`** — the worker is configured, answered, and declares every
 *   capability the domain needs.
 * - **`unavailable`** — either no worker is configured (we know there is none),
 *   or the worker answered and named a missing binary (`ffmpeg` on a managed
 *   Node host is the common case).
 * - **`unknown`** — a worker is configured but the probe did not answer.
 *   Collapsing this into `unavailable` would blame the worker for a timeout,
 *   and collapse the operator's next action ("fix the binary" vs "check the
 *   network") into one message.
 *
 * A `portable` tier is named in 164.4.3 but is deliberately absent here: no
 * portable document renderer is bound on this runtime yet, and a tier nothing
 * can enter is a state that would never be reported. It arrives with the
 * dompdf binding.
 *
 * ## Domains are declared, not inferred
 *
 * The mapping from a domain an operator thinks in (`documents`, `video`) to the
 * capability keys the worker publishes is data in this class. Inferring it from
 * key prefixes would break the first time the worker renamed one, silently
 * turning a working domain into `unknown`.
 *
 * Every capability named below is read from the worker's own
 * `src/index.js` `/api/health/full` response, not guessed.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final class MediaWorkerCapabilityReport
{
    /** The worker answered and declares everything the domain needs. */
    public const TIER_WORKER = 'worker';

    /** We know it cannot do this: no worker, or the worker named a missing piece. */
    public const TIER_UNAVAILABLE = 'unavailable';

    /** A worker is configured but the probe did not answer. */
    public const TIER_UNKNOWN = 'unknown';

    /**
     * Domain => the worker capabilities it requires, all of them.
     *
     * Keys are the worker's own (`addons/media-worker/src/index.js`).
     *
     * @var array<string, list<string>>
     */
    private const DOMAINS = array(
        'documents' => array('pdf_generation'),
        'office' => array('document_word', 'document_excel'),
        'ocr' => array('document_ocr'),
        'image' => array('image_optimization'),
        'video' => array('video_processing'),
        'email' => array('email'),
        'data' => array('chart_rendering'),
        'browser' => array('browser_automation'),
        'crawl' => array('web_crawling'),
        // Reading channels and composing copy is separate from publishing.
        // Leaving one `social` domain mapped to `social_publishing` is what let
        // the diagnostics page say "Social: worker" while the runtime served no
        // social tool at all.
        'social' => array('social_accounts', 'social_composition'),
        'social_publishing' => array('social_publishing'),
    );

    /**
     * @param bool                $configured   Whether a worker URL is set.
     * @param bool                $available    Whether the worker answered the probe.
     * @param string|null         $version      Worker version, when it said.
     * @param array<string, bool> $capabilities The worker's own matrix.
     */
    private function __construct(
        private readonly bool $configured,
        private readonly bool $available,
        private readonly ?string $version,
        private readonly array $capabilities,
    ) {
    }

    /**
     * A host with no worker URL.
     */
    public static function unconfigured(): self
    {
        return new self(false, false, null, array());
    }

    /**
     * A configured worker that did not answer.
     */
    public static function unreachable(?string $version = null): self
    {
        return new self(true, false, $version, array());
    }

    /**
     * Build from what the probe observed.
     *
     * @param bool                $configured   Whether a worker URL is set.
     * @param bool                $available    Whether the worker answered.
     * @param string|null         $version      Worker version, when it said.
     * @param array<string, bool> $capabilities The worker's own matrix.
     */
    public static function fromProbe(
        bool $configured,
        bool $available,
        ?string $version,
        array $capabilities,
    ): self {
        $normalised = array();

        foreach ($capabilities as $key => $value) {
            if (\is_string($key) && '' !== $key) {
                $normalised[$key] = (bool) $value;
            }
        }

        return new self($configured, $available, $version, $normalised);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * Whether the worker answered at all.
     *
     * Note the asymmetry with `isConfigured()`: a configured worker that is
     * down is configured and not available, which is the `unknown` tier.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    /**
     * The worker's capability matrix, verbatim.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * One capability, with absence read as "not claimed".
     */
    public function supports(string $capability): bool
    {
        return true === ($this->capabilities[$capability] ?? false);
    }

    /**
     * The tier serving a domain: `worker`, `unavailable` or `unknown`.
     *
     * An undeclared domain answers `unknown` rather than `worker` — a domain
     * this class has never heard of cannot be vouched for.
     */
    public function tier(string $domain): string
    {
        if (! isset(self::DOMAINS[$domain])) {
            return self::TIER_UNKNOWN;
        }

        if (! $this->configured) {
            return self::TIER_UNAVAILABLE;
        }

        if (! $this->available) {
            return self::TIER_UNKNOWN;
        }

        foreach (self::DOMAINS[$domain] as $capability) {
            if (! $this->supports($capability)) {
                return self::TIER_UNAVAILABLE;
            }
        }

        return self::TIER_WORKER;
    }

    /**
     * Every declared domain and the tier serving it.
     *
     * @return array<string, string>
     */
    public function domains(): array
    {
        $tiers = array();

        foreach (array_keys(self::DOMAINS) as $domain) {
            $tiers[$domain] = $this->tier($domain);
        }

        return $tiers;
    }

    /**
     * The capabilities a domain wants and the worker does not declare.
     *
     * Only meaningful once the worker has answered: before that, everything is
     * missing in the sense that nothing is known, which is the `unknown` tier's
     * job to say.
     *
     * @return list<string>
     */
    public function missing(string $domain): array
    {
        if (! $this->available || ! isset(self::DOMAINS[$domain])) {
            return array();
        }

        $missing = array();

        foreach (self::DOMAINS[$domain] as $capability) {
            if (! $this->supports($capability)) {
                $missing[] = $capability;
            }
        }

        return $missing;
    }

    /**
     * The domains this class knows how to ask about.
     *
     * @return list<string>
     */
    public static function knownDomains(): array
    {
        return \array_keys(self::DOMAINS);
    }

    /**
     * A serialisable shape for diagnostics and the console.
     *
     * @return array{
     *     configured: bool,
     *     available: bool,
     *     version: string|null,
     *     domains: array<string, string>,
     *     missing: array<string, list<string>>,
     *     capabilities: array<string, bool>
     * }
     */
    public function toArray(): array
    {
        $missing = array();

        foreach (self::knownDomains() as $domain) {
            $gaps = $this->missing($domain);

            if (array() !== $gaps) {
                $missing[$domain] = $gaps;
            }
        }

        return array(
            'configured' => $this->configured,
            'available' => $this->available,
            'version' => $this->version,
            'domains' => $this->domains(),
            'missing' => $missing,
            'capabilities' => $this->capabilities,
        );
    }
}
