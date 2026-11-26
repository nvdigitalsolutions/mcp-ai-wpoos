{{--
    Settings coverage — Proposal 149 §183.

    Read-only. `/admin/settings` writes values; this page says which of those
    values something reads. The report is generated from source (`SettingsCoverage`
    reads resources/settings/coverage.json); nothing here queries a table, so the
    page renders the same on every estate.

    The unenforced bucket leads because a writable security control that no
    enforcement point reads is the most dangerous row on the page — it records an
    intention and changes nothing. Burying it under 469 neutral rows would repeat
    the silent false assurance one level up.

    Plain HTML with inline styles, as on the other ported pages.
--}}
@php
    $counts = $this->counts();
    $buckets = $this->buckets();
    $problems = $this->problemRows();
    $unenforced = $counts['unenforced'] ?? 0;
@endphp

<x-filament-panels::page>
    @if (! $this->hasData())
        <div style="padding: 1rem 1.15rem; border: 1px solid rgba(220, 38, 38, 0.35); border-radius: 0.5rem; background: rgba(220, 38, 38, 0.10);">
            <strong>The coverage map is missing.</strong>
            <p style="font-size: 0.85rem; margin: 0.35rem 0 0;">
                <code>resources/settings/coverage.json</code> could not be read, so this
                page has nothing to report. That is a build problem, not a statement about
                your settings — regenerate the map before trusting any count here.
            </p>
        </div>
    @else
        <div style="padding: 1rem 1.15rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem; background: rgba(59, 130, 246, 0.08); margin-bottom: 1.25rem;">
            <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">Coverage</div>
            <div style="font-size: 1.5rem; font-weight: 700; line-height: 1.2;">
                {{ $this->enforcedPercent() }} of controls change behaviour here
            </div>
            <p style="font-size: 0.85rem; margin: 0.35rem 0 0;">
                {{ number_format($counts['enforced'] ?? 0) }} of {{ number_format($this->total()) }}
                settings in the catalogue are read by code that acts on them. The rest are
                one of the situations below — most of them the ported WordPress surface,
                which this admin can render but cannot make take effect.
            </p>
        </div>

        @if ($unenforced > 0)
            <div style="padding: 1rem 1.15rem; border: 1px solid rgba(220, 38, 38, 0.35); border-radius: 0.5rem; background: rgba(220, 38, 38, 0.10); margin-bottom: 1.25rem;">
                <div style="font-size: 1.05rem; font-weight: 700; color: #b91c1c; line-height: 1.25;">
                    {{ number_format($unenforced) }} security {{ 1 === $unenforced ? 'control is' : 'controls are' }} writable here but enforced nowhere
                </div>
                <p style="font-size: 0.85rem; margin: 0.35rem 0 0;">
                    Saving one of these records an intention and changes no behaviour: no
                    middleware, policy or service reads it. If a control you expect to be
                    enforcing something appears below, the runtime is not enforcing it.
                    They are listed first, by name, rather than summarised.
                </p>
                @if (($counts['unenforced-operator'] ?? 0) > 0)
                    <p style="font-size: 0.85rem; margin: 0.35rem 0 0;">
                        {{ number_format($counts['unenforced-operator']) }} more are the same
                        problem one scope up: operator-scope controls, writable from
                        <code>/ops</code> rather than this panel. Same absence of a reader,
                        different administrator.
                    </p>
                @endif
            </div>
        @endif

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
            @foreach ($buckets as $bucket)
                @php [$accent, $wash] = $this->tone($bucket); @endphp
                <div style="padding: 0.9rem 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem; background: {{ $wash }};">
                    <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">
                        {{ $this->label($bucket) }}
                    </div>
                    <div style="font-size: 1.45rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; color: {{ $accent }}; margin: 0.15rem 0;">
                        {{ number_format($counts[$bucket] ?? 0) }}
                    </div>
                    <div style="font-size: 0.75rem; opacity: 0.7;">
                        {{ (int) round(100 * ($counts[$bucket] ?? 0) / $this->total()) }}% of the catalogue
                    </div>
                </div>
            @endforeach
        </div>

        <div style="padding: 0.9rem 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem; margin-bottom: 1.25rem; font-size: 0.85rem;">
            <strong style="display: block; margin-bottom: 0.4rem;">What each bucket means</strong>
            <dl style="margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 0.35rem 0.85rem; align-items: baseline;">
                @foreach ($buckets as $bucket)
                    <dt style="font-weight: 600; white-space: nowrap;">{{ $this->label($bucket) }}</dt>
                    <dd style="margin: 0; opacity: 0.85;">{{ $this->reason($bucket) }}</dd>
                @endforeach
            </dl>
        </div>

        @foreach ($problems as $bucket => $rows)
            <x-admin.data-table
                :caption="$this->label($bucket).' — settings whose relationship to behaviour is unclear'"
                :columns="[['label' => 'Setting'], ['label' => 'Why']]"
                :rows="count($rows)"
                :empty="'No settings in this bucket.'"
            >
                @foreach ($rows as $row)
                    <tr>
                        <td style="overflow-wrap: anywhere;"><code>{{ $row['key'] }}</code></td>
                        <td style="opacity: 0.85;">{{ $row['reason'] }}</td>
                    </tr>
                @endforeach
            </x-admin.data-table>
        @endforeach

        <p style="font-size: 0.8rem; opacity: 0.75; margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px solid rgba(127, 127, 127, 0.25);">
            The <strong>WordPress settings</strong> buckets are not a defect. Those keys
            are the ported catalogue: the plugin reads them from <code>wp_options</code>, and
            this admin writes <code>nvoos_tenant_settings</code>, which the plugin never reads.
            They are rendered so the parity map stays visible, and they are inert on this
            runtime until a slice gives one a reader. <em>Built by slug</em> is the same
            situation for keys the plugin assembles at runtime
            (<code>$slug . '_safety_buffer'</code>), which a literal scan cannot see. The map
            is generated from source rather than hand-written, so it drifts the moment the
            code moves — regenerate it with <code>bin/settings-coverage</code> instead of
            editing it.
        </p>
    @endif
</x-filament-panels::page>
