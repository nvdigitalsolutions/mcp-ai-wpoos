<?php

/**
 * Tool readiness manifest.
 *
 * Readiness answers one question the tool contract cannot: *can this runtime
 * actually honour the tool it is advertising?* `ToolInterface` says what a tool
 * looks like; `ToolReadiness` says whether serving it would be honest.
 *
 * The distinction matters because a shared package and a host do not fail in
 * the same way. A tool that is absent returns a not-found error the model can
 * reason about. A tool that is present but hollow returns `success: true` with
 * a prompt string, and the agent reports the task as done. The second failure
 * is silent, so registration is deny-by-default: only `ready`, `pure` and
 * `delegated` are servable, and an unknown slug is `unverified`.
 *
 * Readiness is declared rather than inferred. Static inference was tried while
 * writing Proposal 090-M and produced both false negatives (inherited
 * constructors, inherited `execute()`) and false positives (`$this->p` hiding a
 * repository). The manifest is the decision; this class reads it.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Tool;

final class ToolReadiness
{
    /**
     * Contract version of the readiness payload exposed by the tools route.
     *
     * v1: `readiness` + `annotations` per tool, plus the servable count.
     * Additive to the endpoint contract in `EndpointCapabilities`.
     */
    public const CONTRACT_VERSION = 1;

    /** Real implementation, constructor satisfiable, evidence recorded. */
    public const READY = 'ready';

    /** No I/O by nature; correctness is self-evident or unit-tested. */
    public const PURE = 'pure';

    /** The client performs the work; the server half only describes it. */
    public const DELEGATED = 'delegated';

    /** Constructible, but no evidence recorded. Not served. */
    public const UNVERIFIED = 'unverified';

    /** Describes work it does not perform. Not served, under any slug. */
    public const QUARANTINED = 'quarantined';

    /** Needs a service this runtime does not bind. Not served. */
    public const UNAVAILABLE = 'unavailable';

    /**
     * The closed vocabulary, in the order a reader should meet it.
     *
     * @var string[]
     */
    public const VOCABULARY = array(
        self::READY,
        self::PURE,
        self::DELEGATED,
        self::UNVERIFIED,
        self::QUARANTINED,
        self::UNAVAILABLE,
    );

    /**
     * The classes a runtime may serve.
     *
     * @var string[]
     */
    public const SERVABLE = array(
        self::READY,
        self::PURE,
        self::DELEGATED,
    );

    /**
     * Parsed manifests, keyed by resolved path.
     *
     * One `require` per process per path: the file is a PHP array, so the
     * engine already caches its compiled form, and hosts read it once at boot.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $cache = array();

    private readonly string $manifestPath;

    /**
     * @param string|null $manifestPath Override for hosts that ship a copy
     *                                  (WordPress strips `lib/`). Defaults to
     *                                  `NVOOS_TOOL_READINESS`, then the
     *                                  packaged manifest.
     */
    public function __construct(?string $manifestPath = null)
    {
        if (null === $manifestPath) {
            $fromEnv = getenv('NVOOS_TOOL_READINESS');
            $manifestPath = is_string($fromEnv) && '' !== $fromEnv
                ? $fromEnv
                : dirname(__DIR__, 3) . '/resources/tool-readiness.php';
        }

        $this->manifestPath = $manifestPath;
    }

    /**
     * Absolute path of the manifest this instance reads.
     */
    public function path(): string
    {
        return $this->manifestPath;
    }

    /**
     * Every row, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return self::load($this->manifestPath);
    }

    /**
     * One row, or null when the manifest has no opinion.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * Readiness for a slug. Unknown slugs are `unverified`, never servable.
     */
    public function classify(string $slug): string
    {
        $readiness = (string) ($this->get($slug)['readiness'] ?? self::UNVERIFIED);

        return self::isKnown($readiness) ? $readiness : self::UNVERIFIED;
    }

    /**
     * May this runtime serve the slug?
     */
    public function isServable(string $slug): bool
    {
        return self::isServableClass($this->classify($slug));
    }

    /**
     * MCP-style annotations for a slug.
     *
     * Returns only the four hints the specification defines, so a client can
     * consume the payload without a version check. Readiness is a separate
     * axis: annotations say what the tool does to the world, readiness says
     * whether this host can honour it.
     *
     * @return array<string, bool>
     */
    public function annotations(string $slug): array
    {
        $row = $this->get($slug);
        $declared = is_array($row['annotations'] ?? null) ? $row['annotations'] : array();
        $annotations = array();

        foreach (array('readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint') as $hint) {
            if (isset($declared[$hint])) {
                $annotations[$hint] = (bool) $declared[$hint];
            }
        }

        return $annotations;
    }

    /**
     * Why a slug is not servable, when the manifest records a reason.
     */
    public function reason(string $slug): ?string
    {
        $reason = $this->get($slug)['reason'] ?? null;

        return is_string($reason) && '' !== $reason ? $reason : null;
    }

    /**
     * Servable rows, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public function servable(): array
    {
        $servable = array();

        foreach ($this->all() as $slug => $row) {
            if (self::isServableClass((string) ($row['readiness'] ?? ''))) {
                $servable[$slug] = $row;
            }
        }

        ksort($servable);

        return $servable;
    }

    /**
     * Fingerprint of the servable set.
     *
     * Covers slug, class, readiness and annotations — the facts a client acts
     * on. A change here means a served tool definition moved, which should be
     * visible in review rather than inherited silently by the next session.
     */
    public function hash(): string
    {
        $lines = array();

        foreach ($this->servable() as $slug => $row) {
            $annotations = is_array($row['annotations'] ?? null) ? $row['annotations'] : array();
            ksort($annotations);

            $pairs = array();
            foreach ($annotations as $key => $value) {
                $pairs[] = $key . '=' . (bool) $value;
            }

            $lines[] = implode('|', array(
                (string) $slug,
                (string) ($row['class'] ?? ''),
                (string) ($row['readiness'] ?? ''),
                implode(',', $pairs),
            ));
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Integrity problems in the manifest itself.
     *
     * Shared by the auditor and the test suite so both report the same thing.
     *
     * @return string[]
     */
    public function violations(): array
    {
        $violations = array();

        foreach ($this->all() as $slug => $row) {
            $readiness = (string) ($row['readiness'] ?? '');

            if (!self::isKnown($readiness)) {
                $violations[] = "{$slug}: readiness '{$readiness}' is outside the vocabulary";
            }

            $class = (string) ($row['class'] ?? '');

            if ('' === $class) {
                $violations[] = "{$slug}: no class recorded";
                continue;
            }

            if (!class_exists($class)) {
                $violations[] = "{$slug}: class {$class} does not exist";
            }
        }

        return $violations;
    }

    /**
     * Drop the parsed cache. Test seam; hosts never need it.
     */
    public static function flush(): void
    {
        self::$cache = array();
    }

    /**
     * Is the value part of the closed vocabulary?
     */
    public static function isKnown(string $readiness): bool
    {
        return in_array($readiness, self::VOCABULARY, true);
    }

    /**
     * May a runtime serve a tool with this readiness class?
     */
    public static function isServableClass(string $readiness): bool
    {
        return in_array($readiness, self::SERVABLE, true);
    }

    /**
     * Parse the manifest once per path.
     *
     * A missing file yields an empty manifest rather than an exception: the
     * registry then serves nothing, which is the safe direction. The auditor
     * is what makes a missing manifest loud.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function load(string $path): array
    {
        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        $tools = array();

        if (is_file($path) && is_readable($path)) {
            $loaded = require $path;

            if (is_array($loaded) && isset($loaded['tools']) && is_array($loaded['tools'])) {
                foreach ($loaded['tools'] as $slug => $row) {
                    if (is_array($row)) {
                        $tools[(string) $slug] = $row;
                    }
                }
            }
        }

        self::$cache[$path] = $tools;

        return $tools;
    }
}
