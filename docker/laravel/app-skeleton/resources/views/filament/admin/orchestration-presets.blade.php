{{--
    Orchestration presets — Proposal 149 §181.

    Four postures over the three `Turn budget` keys. Every row shows the numbers
    it will write, because a preset that hides its values is a surprise, and the
    apply step expands into a from → to sentence because the dangerous case is
    silently overwriting ceilings someone tuned by hand.

    The "Now" card carries the derived state: a preset name when the stored values
    match one exactly, "Custom" otherwise. There is no stored active-preset key —
    see App\Support\Orchestration\TurnBudgetPresets.

    Plain HTML with inline styles, as on the other ported pages.
--}}
@php
    $presets = $this->presets();
    $current = $this->currentValues();
    $active = $this->activeKey();
@endphp

<x-filament-panels::page>
    <x-filament::callout
        color="info"
        icon="heroicon-o-information-circle"
        heading="Postures over the turn budget"
        description="A preset writes the per-call ceiling, the per-turn ceiling and the warn band — the same three fields as Settings → Advanced → Turn budget. Nothing else is touched, and applying one does not lock the estate into it: the settings page stays open, and editing any value turns the state below into Custom."
    />

    <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; padding: 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem; margin: 1rem 0 1.25rem;">
        <div style="flex: 1 1 14rem;">
            <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">Now</div>
            <div style="font-size: 1.05rem; font-weight: 600; margin-top: 0.15rem;">
                {{ $this->humanTokens($current['turn_max_call_tokens']) }} per call ·
                {{ $this->humanTokens($current['turn_max_turn_tokens']) }} per turn ·
                warn at {{ $this->humanWarn($current['turn_warn_at']) }}
            </div>
        </div>

        <div>
            @if (null === $active)
                <span style="display: inline-block; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; color: #92400e; background: rgba(245, 158, 11, 0.15);">
                    Custom
                </span>
            @else
                <span style="display: inline-block; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; color: #166534; background: rgba(34, 197, 94, 0.15);">
                    {{ $presets[$active]['label'] }}
                </span>
            @endif
        </div>
    </div>

    <x-admin.data-table
        caption="Orchestration presets and the values each one writes"
        :columns="[
            ['label' => 'Preset'],
            ['label' => 'Per call'],
            ['label' => 'Per turn'],
            ['label' => 'Warn at'],
            ['label' => 'Action'],
        ]"
        :rows="count($presets)"
        :empty="'No presets are defined.'"
    >
        @foreach ($presets as $key => $preset)
            <tr>
                <td style="overflow-wrap: anywhere;">
                    <strong>{{ $preset['label'] }}</strong>
                    @if ($active === $key)
                        <span style="display: inline-block; margin-left: 0.35rem; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.65rem; color: #166534; background: rgba(34, 197, 94, 0.15);">active</span>
                    @endif
                    <div style="font-size: 0.78rem; opacity: 0.8; margin-top: 0.15rem;">{{ $preset['description'] }}</div>
                    <div style="font-size: 0.72rem; opacity: 0.65; margin-top: 0.1rem;">{{ $preset['rationale'] }}</div>
                </td>
                <td style="font-variant-numeric: tabular-nums; white-space: nowrap;">
                    {{ $this->humanTokens($preset['values']['turn_max_call_tokens']) }}
                </td>
                <td style="font-variant-numeric: tabular-nums; white-space: nowrap;">
                    {{ $this->humanTokens($preset['values']['turn_max_turn_tokens']) }}
                </td>
                <td style="font-variant-numeric: tabular-nums; white-space: nowrap;">
                    {{ $this->humanWarn((float) $preset['values']['turn_warn_at']) }}
                </td>
                <td style="white-space: nowrap;">
                    @if ($this->pending === $key)
                        <button
                            type="button"
                            wire:click="apply"
                            style="padding: 0.3rem 0.75rem; font-size: 0.8rem; font-weight: 600; border: 1px solid rgba(127, 127, 127, 0.45); border-radius: 0.375rem;"
                        >Confirm</button>
                        <button
                            type="button"
                            wire:click="cancel"
                            style="padding: 0.3rem 0.75rem; font-size: 0.8rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                        >Cancel</button>
                    @else
                        <button
                            type="button"
                            wire:click="confirm('{{ $key }}')"
                            @disabled($active === $key)
                            style="padding: 0.3rem 0.75rem; font-size: 0.8rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                        >{{ $active === $key ? 'Applied' : 'Apply' }}</button>
                    @endif
                </td>
            </tr>

            @if ($this->pending === $key)
                <tr>
                    <td colspan="5" style="background: rgba(127, 127, 127, 0.06);">
                        <div style="font-size: 0.85rem;">
                            This replaces
                            <strong>{{ $this->humanTokens($current['turn_max_call_tokens']) }}</strong> per call,
                            <strong>{{ $this->humanTokens($current['turn_max_turn_tokens']) }}</strong> per turn and
                            a warn band of <strong>{{ $this->humanWarn($current['turn_warn_at']) }}</strong>
                            with
                            <strong>{{ $this->humanTokens($preset['values']['turn_max_call_tokens']) }}</strong> per call,
                            <strong>{{ $this->humanTokens($preset['values']['turn_max_turn_tokens']) }}</strong> per turn and
                            a warn band of <strong>{{ $this->humanWarn((float) $preset['values']['turn_warn_at']) }}</strong>.
                            The change takes effect on the next turn; calls already in flight are not stopped.
                        </div>
                    </td>
                </tr>
            @endif
        @endforeach
    </x-admin.data-table>

    <p style="font-size: 0.8rem; opacity: 0.75; margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px solid rgba(127, 127, 127, 0.25);">
        This estate only. A preset writes three settings and nothing else — it does not
        change the model, the tools, or any per-tool quota. The WordPress view's other
        bundles (agent teams, reasoning mode, speculative execution) are not listed
        because this runtime reads none of their fields; showing them would be a
        button that changes nothing.
    </p>
</x-filament-panels::page>
