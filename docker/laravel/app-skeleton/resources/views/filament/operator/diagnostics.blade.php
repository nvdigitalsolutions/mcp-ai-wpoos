{{--
    Operations, diagnostics and cost — Proposal 149 §48.

    Plain HTML with inline styles, as on the other operator pages.

    No credential is rendered: the overview says "credentials configured" or
    "no credentials", never a mask, and a probe result is built by ProbeRunner
    so it has no field a raw payload or a keyed URL could travel in.
--}}
@php
    $badge = static function ( string $status ): array {
        return match ( $status ) {
            'good'        => array( '#065f46', 'rgba(16, 185, 129, 0.15)' ),
            'recommended' => array( '#92400e', 'rgba(245, 158, 11, 0.15)' ),
            'critical'    => array( '#991b1b', 'rgba(239, 68, 68, 0.15)' ),
            default       => array( '#374151', 'rgba(107, 114, 128, 0.15)' ),
        };
    };

    $overview = $this->overview();
    $cost = $this->cost();
    $probeOptions = $this->probeOptions();
    $providerOptions = $this->providerOptions();
    $assistantOptions = $this->assistantOptions();
@endphp

<x-filament-panels::page>
    <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.08); border: 1px solid rgba(127, 127, 127, 0.25); font-size: 0.85rem;">
        This page re-runs every check on demand and stores nothing. The provider
        probe makes one authenticated <code>GET /models</code> call and spends no
        completion tokens; the assistant probe is the dry-run plan, not a turn.
    </div>

    <h3 style="margin: 1.5rem 0 0.5rem;">Diagnostics</h3>
    @foreach ( $overview as $group => $rows )
        <div style="margin-bottom: 0.75rem;">
            <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; margin-bottom: 0.25rem;">{{ $group }}</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <tbody>
                    @foreach ( $rows as $row )
                        <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                            <th style="padding: 0.4rem 0.5rem 0.4rem 0; text-align: left; font-weight: 500; width: 18rem;">{{ $row['label'] }}</th>
                            <td style="padding: 0.4rem 0;"><code style="font-size: 0.8rem;">{{ $row['value'] }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <h3 style="margin: 1.75rem 0 0.5rem;">Probes</h3>

    <form wire:submit="runProbe" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; margin-bottom: 1rem;">
        <label style="display: flex; flex-direction: column; font-size: 0.8rem; gap: 0.25rem;">
            Probe
            <select wire:model.live="probe" style="min-width: 14rem; padding: 0.35rem;">
                @foreach ( $probeOptions as $key => $label )
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        @if ( 'provider' === $probe )
            <label style="display: flex; flex-direction: column; font-size: 0.8rem; gap: 0.25rem;">
                Provider
                <select wire:model="provider" style="min-width: 12rem; padding: 0.35rem;">
                    @foreach ( $providerOptions as $slug => $label )
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ( 'assistant' === $probe )
            <label style="display: flex; flex-direction: column; font-size: 0.8rem; gap: 0.25rem;">
                Assistant
                <select wire:model="assistant" style="min-width: 16rem; padding: 0.35rem;">
                    @forelse ( $assistantOptions as $id => $title )
                        <option value="{{ $id }}">{{ $title }}</option>
                    @empty
                        <option value="">No assistants in this estate</option>
                    @endforelse
                </select>
            </label>
        @endif

        <button type="submit" style="padding: 0.45rem 1rem; border-radius: 0.375rem; background: #2563eb; color: #fff; border: 0; cursor: pointer;">
            Run probe
        </button>
    </form>

    @if ( null !== $result )
        @php( $colours = $badge( (string) $result['status'] ) )
        <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 0.5rem; background: {{ $colours[1] }}; border: 1px solid {{ $colours[0] }}33;">
            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: {{ $colours[0] }}; background: {{ $colours[1] }};">{{ $result['status'] }}</span>
                <strong>{{ $result['label'] }}</strong>
                <span style="font-size: 0.8rem; opacity: 0.8;">{{ $result['duration_ms'] }} ms</span>
            </div>
            <div style="margin-top: 0.35rem;">{{ $result['summary'] }}</div>
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 1rem;">
            <tbody>
                @foreach ( $result['rows'] as $row )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                        <th style="padding: 0.4rem 0.5rem 0.4rem 0; text-align: left; font-weight: 500; width: 18rem;">{{ $row['label'] }}</th>
                        <td style="padding: 0.4rem 0;"><code style="font-size: 0.8rem;">{{ $row['value'] }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="font-size: 0.85rem; opacity: 0.75;">Choose a probe and run it. Nothing on this page is saved.</p>
    @endif

    <h3 style="margin: 1.75rem 0 0.5rem;">Cost <span style="font-size: 0.8rem; font-weight: 400; opacity: 0.7;">(last 30 days · {{ $cost['period'] }})</span></h3>

    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <tbody>
            <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                <th style="padding: 0.4rem 0.5rem 0.4rem 0; text-align: left; font-weight: 500; width: 18rem;">Total cost</th>
                <td style="padding: 0.4rem 0;"><code style="font-size: 0.8rem;">{{ $cost['total_cost'] }}</code></td>
            </tr>
            <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                <th style="padding: 0.4rem 0.5rem 0.4rem 0; text-align: left; font-weight: 500;">Total tokens</th>
                <td style="padding: 0.4rem 0;"><code style="font-size: 0.8rem;">{{ $cost['total_tokens'] }}</code></td>
            </tr>
            @forelse ( $cost['by_provider'] as $row )
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                    <th style="padding: 0.4rem 0.5rem 0.4rem 0; text-align: left; font-weight: 500;">{{ $row['label'] }}</th>
                    <td style="padding: 0.4rem 0;"><code style="font-size: 0.8rem;">{{ $row['value'] }}</code></td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" style="padding: 0.4rem 0; opacity: 0.75;">No usage recorded in the period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-filament-panels::page>
