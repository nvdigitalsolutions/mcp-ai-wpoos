<?php

namespace App\Support\ToolFlags;

use App\Models\ToolFlagOverride;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvoos\Core\Domain\Contract\CapabilityFlagOverridesInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

/**
 * Per-tool capability-flag additions — Proposal 149 §177.
 *
 * Implements the core port the guards read, so the read-only profile, the chat
 * profile and the necessity gate all see one estate's answer to "this tool
 * under-declares what it can do".
 *
 * **Only the read-only gate set may be added.** `allowedFlags()` is
 * `CapabilityFlagVocabulary::READ_ONLY_GATED` — the eight flags the runtime
 * already refuses on. Restricting the vocabulary is what lets the page say
 * something honest and simple: an override can make a tool stricter, never
 * looser. A flag outside that list (`cacheable`, `read-only`, …) could *relax* a
 * verdict if added, so it is not offerable at all rather than offered and
 * filtered away at the last moment.
 *
 * **No memo.** The value is one indexed query against a table with a row per
 * overridden tool. A per-instance cache in a singleton would be read by the next
 * request on an Octane worker — the T-0.8 rule — and a stale safety input is
 * worse than a cheap query.
 */
class ToolFlagOverrideStore implements CapabilityFlagOverridesInterface
{
    public const TABLE = 'nvoos_tool_flag_overrides';

    /**
     * {@inheritDoc}
     */
    public function addedFlags(string $slug): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return array();
        }

        $row = ToolFlagOverride::query()->where('tool_slug', $slug)->first();

        return null === $row ? array() : self::allowed($row->flags);
    }

    /**
     * Every row of the current estate, for the page.
     *
     * @return list<ToolFlagOverride>
     */
    public function forCurrentEstate(): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return array();
        }

        return ToolFlagOverride::query()->orderBy('tool_slug')->get()->all();
    }

    /**
     * Write an override.
     *
     * An empty list is not a row: it is the absence of one, and storing it would
     * leave a row that renders and changes nothing. So a save with no flags
     * removes the override instead.
     *
     * @param array<int, mixed> $flags Raw flags; normalised and filtered here.
     */
    public function put(string $slug, array $flags): ?ToolFlagOverride
    {
        $slug = trim($slug);

        if ('' === $slug) {
            throw new InvalidArgumentException('A flag override needs a tool slug.');
        }

        $permitted = self::allowed($flags);
        $existing = $this->forTool($slug);

        if (array() === $permitted) {
            if (null !== $existing) {
                $existing->delete();
            }

            return null;
        }

        $override = $existing ?? new ToolFlagOverride();
        $override->tool_slug = $slug;
        $override->flags = $permitted;
        $override->save();

        return $override;
    }

    /**
     * Remove an override.
     */
    public function remove(int $id): bool
    {
        if (! Schema::hasTable(self::TABLE)) {
            return false;
        }

        return (bool) ToolFlagOverride::query()->whereKey($id)->delete();
    }

    /**
     * The row for one tool, or null.
     */
    public function forTool(string $slug): ?ToolFlagOverride
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        return ToolFlagOverride::query()->where('tool_slug', $slug)->first();
    }

    /**
     * The flags an override may add.
     *
     * @return list<string>
     */
    public static function allowedFlags(): array
    {
        return CapabilityFlagVocabulary::READ_ONLY_GATED;
    }

    /**
     * Labels for the page's flag checklist.
     *
     * @return array<string, string>
     */
    public static function flagOptions(): array
    {
        $labels = array(
            'destructive'           => 'Destructive — may destroy or overwrite',
            'data-destruction'      => 'Deletes data',
            'irreversible'          => 'Cannot be undone',
            'state-changing'        => 'Changes stored state',
            'write'                 => 'Writes',
            'financial-impact'      => 'Moves money',
            'access-control-change' => 'Changes permissions',
            'mass-email'            => 'Emails many recipients',
        );

        $options = array();

        foreach (self::allowedFlags() as $flag) {
            $options[$flag] = $labels[$flag] ?? $flag;
        }

        return $options;
    }

    /**
     * Normalise and filter to the permitted vocabulary.
     *
     * @param array<int, mixed> $flags Raw input from a form or a row.
     *
     * @return list<string>
     */
    private static function allowed(array $flags): array
    {
        $normalised = CapabilityFlagVocabulary::normalise(
            array_values(array_map('strval', $flags))
        );

        $permitted = array();

        foreach (self::allowedFlags() as $flag) {
            if (in_array($flag, $normalised, true)) {
                $permitted[] = $flag;
            }
        }

        return $permitted;
    }
}
