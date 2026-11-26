<?php

namespace App\Support\Orchestration;

use App\Support\Settings\SettingsService;

/**
 * Named turn-budget postures — Proposal 149 §181.
 *
 * A preset is a **projection over the three `Turn budget` keys and nothing
 * else**. That is narrower than the WordPress `orchestration` → `presets` view,
 * which writes a bag of orchestrator flags; §181 records why the rest of that
 * bag is not ported, and the short version is that the other keys have no reader
 * in this runtime, so writing them would be a button that changes nothing.
 *
 * **There is no stored "active preset".** WordPress keeps
 * `wp_mcp_ai_active_preset` beside the values, which is a second truth that
 * drifts the moment someone edits a ceiling by hand. Here the active preset is
 * *derived*: a preset is active when the stored values match it exactly, and the
 * page says "Custom" when none does. The same reading several settings UIs take
 * — show that the values have left the preset rather than pretending they have
 * not.
 *
 * **`Off` is a preset, not an absence.** Zero means off in both dimensions
 * (§179), so "Off" writes the behaviour every estate had before the ceilings
 * existed — which is more discoverable than asking an operator to type two
 * zeroes and know what they mean.
 */
final class TurnBudgetPresets
{
    /** The only keys a preset writes. */
    public const KEYS = array(
        'turn_max_call_tokens',
        'turn_max_turn_tokens',
        'turn_warn_at',
    );

    /**
     * The postures.
     *
     * Token values are quarters and halves of a 128k provider window, multiplied
     * by the number of calls a turn of that shape usually makes. They are
     * starting points an operator may edit; the page can always be left for
     * "Custom", and nothing here is enforced beyond what `TurnBudget` enforces.
     *
     * @var array<string, array{label: string, description: string, rationale: string, values: array<string, int|float>}>
     */
    private const PRESETS = array(
        'frugal' => array(
            'label' => 'Frugal',
            'description' => 'A short turn for high-volume chat, where a wrong answer is cheap to ask for again.',
            'rationale' => '16k per call and 64k per turn: a quarter of a 128k window, four calls deep.',
            'values' => array(
                'turn_max_call_tokens' => 16000,
                'turn_max_turn_tokens' => 64000,
                'turn_warn_at' => 0.7,
            ),
        ),
        'balanced' => array(
            'label' => 'Balanced',
            'description' => 'Room for one large document plus a few tool round-trips.',
            'rationale' => '32k per call and 160k per turn: a quarter of a 128k window, five calls deep.',
            'values' => array(
                'turn_max_call_tokens' => 32000,
                'turn_max_turn_tokens' => 160000,
                'turn_warn_at' => 0.8,
            ),
        ),
        'generous' => array(
            'label' => 'Generous',
            'description' => 'Long research turns that chain many tools before answering.',
            'rationale' => '64k per call and 512k per turn: half a 128k window, eight calls deep. A provider that caps smaller contexts refuses the call itself.',
            'values' => array(
                'turn_max_call_tokens' => 64000,
                'turn_max_turn_tokens' => 512000,
                'turn_warn_at' => 0.9,
            ),
        ),
        'off' => array(
            'label' => 'Off',
            'description' => 'No ceilings. A turn is bounded only by the iteration cap.',
            'rationale' => 'Zero means off in both dimensions, so this is the behaviour every estate had before §179.',
            'values' => array(
                'turn_max_call_tokens' => 0,
                'turn_max_turn_tokens' => 0,
                'turn_warn_at' => 0.8,
            ),
        ),
    );

    /**
     * @return array<string, array{label: string, description: string, rationale: string, values: array<string, int|float>}>
     */
    public function all(): array
    {
        return self::PRESETS;
    }

    public function has(string $key): bool
    {
        return isset(self::PRESETS[$key]);
    }

    /**
     * The stored values, typed for display and comparison.
     *
     * `SettingsService::values()` returns what the store held, which may be a
     * numeric string; a strict comparison against the preset would then never
     * match and every estate would read as Custom.
     *
     * @return array<string, int|float>
     */
    public function current(SettingsService $settings): array
    {
        $values = $settings->values(SettingsService::SURFACE_ADMIN);

        return array(
            'turn_max_call_tokens' => (int) ($values['turn_max_call_tokens'] ?? 0),
            'turn_max_turn_tokens' => (int) ($values['turn_max_turn_tokens'] ?? 0),
            'turn_warn_at' => (float) ($values['turn_warn_at'] ?? 0.8),
        );
    }

    /**
     * The preset the stored values match exactly, or null for Custom.
     *
     * @param array<string, int|float> $current
     */
    public function activeKey(array $current): ?string
    {
        foreach (self::PRESETS as $key => $preset) {
            if ($this->matches($preset['values'], $current)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, int|float> $expected
     * @param array<string, int|float> $current
     */
    public function matches(array $expected, array $current): bool
    {
        foreach ($expected as $key => $value) {
            $actual = $current[$key] ?? null;

            if (is_float($value) || is_float($actual)) {
                if (abs((float) $value - (float) $actual) > 0.0001) {
                    return false;
                }

                continue;
            }

            if ((int) $value !== (int) $actual) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply one preset through the one settings writer.
     *
     * Returns the keys the service actually wrote. An unknown key writes
     * nothing; the caller decides what to say about that.
     *
     * @return list<string>
     */
    public function apply(string $key, SettingsService $settings): array
    {
        if (! isset(self::PRESETS[$key])) {
            return array();
        }

        return $settings->write(self::PRESETS[$key]['values'], SettingsService::SURFACE_ADMIN);
    }
}
