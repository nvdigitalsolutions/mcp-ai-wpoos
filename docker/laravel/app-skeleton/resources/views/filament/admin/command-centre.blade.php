{{--
    Command centre — Proposal 149 §106.

    Plain HTML with inline styles, as on the other ported pages: the panel's
    compiled CSS has no arbitrary utilities, so a Tailwind class here would
    silently do nothing.

    Two honesty devices, both deliberate:

      - a KPI this runtime cannot measure renders a dash and says why, never a
        zero;
      - an empty feed says "nothing recorded", not "nothing happened" — the
        recorder is written by the paths that do the work, and a quiet estate
        and a silent one look identical from here.
--}}
@php
    $tiles = $this->tiles();
    $agents = $this->agents();
    $events = $this->events();
@endphp

<x-filament-panels::page>
    @if ([] === $events)
        <x-filament::callout
            color="info"
            icon="heroicon-o-information-circle"
            heading="No activity recorded"
            description="Nothing has been written to this estate's feed yet. That is not the same as nothing having happened: the feed is written by the paths that do the work (workflow runs today), so work done before they were wired leaves no trace here."
        />
    @endif

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem;">
        @foreach ($tiles as $tile)
            <div style="padding: 0.9rem 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">
                    {{ $tile['label'] }}
                </div>
                <div style="font-size: 1.6rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; margin: 0.15rem 0;">
                    {{ $tile['value'] }}
                </div>
                <div style="font-size: 0.75rem; opacity: 0.7;">
                    {{ $tile['note'] }}
                </div>
            </div>
        @endforeach
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1.25rem;">
        <section>
            <h2 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem;">
                Agents
            </h2>

            @if ([] === $agents)
                <p style="font-size: 0.85rem; opacity: 0.75;">
                    This estate has no assistants yet.
                </p>
            @else
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.85rem;">
                    @foreach ($agents as $agent)
                        @php
                            $colour = match ($agent['status']) {
                                'online' => array('#065f46', 'rgba(16, 185, 129, 0.15)'),
                                'idle' => array('#92400e', 'rgba(245, 158, 11, 0.15)'),
                                default => array('#374151', 'rgba(107, 114, 128, 0.15)'),
                            };
                        @endphp
                        <li style="display: flex; align-items: baseline; gap: 0.5rem; padding: 0.4rem 0; border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                            <span style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                {{ $agent['title'] }}
                            </span>
                            <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.7rem; color: {{ $colour[0] }}; background: {{ $colour[1] }};">
                                {{ $agent['status'] }}
                            </span>
                            <span style="font-size: 0.7rem; opacity: 0.65; white-space: nowrap; font-variant-numeric: tabular-nums;">
                                {{ $this->stamp($agent['last_active']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                <p style="font-size: 0.75rem; opacity: 0.7; margin-top: 0.5rem;">
                    “Online” means enabled (published), not connected: this runtime has no
                    presence channel, and the timestamp is the assistant’s last edit rather
                    than a heartbeat.
                </p>
            @endif
        </section>

        <section>
            <h2 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem;">
                Recent activity
            </h2>

            @if ([] === $events)
                <p style="font-size: 0.85rem; opacity: 0.75;">
                    Nothing recorded in this estate.
                </p>
            @else
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.85rem;">
                    @foreach ($events as $event)
                        <li style="padding: 0.4rem 0; border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                            <div>
                                {{ '' !== (string) ($event['message'] ?? '') ? $event['message'] : (string) ($event['type'] ?? 'event') }}
                            </div>
                            <div style="font-size: 0.7rem; opacity: 0.65;">
                                <code>{{ $event['type'] ?? '' }}</code>
                                @if (null !== ($event['agent_id'] ?? null))
                                    · agent #{{ (int) $event['agent_id'] }}
                                @endif
                                · {{ $this->stamp((int) ($event['timestamp'] ?? 0)) }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <p style="font-size: 0.8rem; opacity: 0.75; margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px solid rgba(127, 127, 127, 0.25);">
        This estate's own figures. The rest of the WordPress command centre lives on
        its own pages here: the full approval queue on <strong>Approvals</strong>,
        run history on <strong>Workflow runs</strong>, consumption on
        <strong>Spend</strong>, and dependency health on the operator's
        <strong>Site health</strong>. Nothing is duplicated on this page.
    </p>
</x-filament-panels::page>
