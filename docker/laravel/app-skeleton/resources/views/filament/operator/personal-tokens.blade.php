{{--
    Personal access tokens, across estates — Proposal 149 §114.

    Plain HTML with inline styles, as on the other ported pages: the panel's
    compiled CSS has no arbitrary utilities, so a Tailwind class here would
    silently do nothing.

    The table itself is Filament's, because search and sort are SQL-level and a
    hand-built table would have to re-implement both. What this view owns is the
    framing — which matters more here than usual, because two of the columns are
    easy to misread:

      - "Reaches" is the HOLDER's membership list, not the token's scope. A
        personal token is not estate data; it presents the person's authority in
        every estate they belong to. Reading the column as scope would suggest a
        narrowing the runtime does not enforce.
      - "Never used" is a finding, not a blank. A long-lived credential that has
        never been presented is the reason this page exists.
--}}
@php
    $totals = $this->totals();
    $scoped = $this->scopedEstate();
    $estates = $this->estates();
@endphp

<x-filament-panels::page>
    <p style="opacity: 0.8; margin-bottom: 1rem;">
        Every personal access token on this deployment. A token belongs to a person and
        carries their authority in every estate they belong to — so the estates below
        describe who a holder can act for, not what a token is limited to.
    </p>

    {{-- Estate scope. A link, not a Livewire control: each narrowing is a page
         load, which is what lets the audit trail record WHICH estate was looked
         at rather than only that the page was opened. --}}
    @if ([] !== $estates)
        <nav
            aria-label="Estate scope"
            style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 1.25rem;"
        >
            <span style="font-size: 0.8rem; opacity: 0.7;">Scope</span>

            <a
                href="{{ url('/ops/personal-tokens') }}"
                aria-current="{{ null === $scoped ? 'page' : 'false' }}"
                style="padding: 0.3rem 0.7rem; border-radius: 999px; border: 1px solid rgba(127, 127, 127, 0.4); text-decoration: none; {{ null === $scoped ? 'background: rgba(127, 127, 127, 0.18); font-weight: 600;' : 'font-weight: 400;' }}"
            >
                All estates ({{ $totals['total'] }})
            </a>

            @foreach ($estates as $estate)
                <a
                    href="{{ url('/ops/personal-tokens?tenant='.rawurlencode($estate['slug'])) }}"
                    aria-current="{{ $estate['slug'] === $this->estate ? 'page' : 'false' }}"
                    style="padding: 0.3rem 0.7rem; border-radius: 999px; border: 1px solid rgba(127, 127, 127, 0.4); text-decoration: none; {{ $estate['slug'] === $this->estate ? 'background: rgba(127, 127, 127, 0.18); font-weight: 600;' : 'font-weight: 400;' }}"
                >
                    {{ $estate['name'] }} ({{ $estate['tokens'] }})
                </a>
            @endforeach
        </nav>
    @endif

    @if (null !== $scoped)
        <p style="font-size: 0.85rem; opacity: 0.75; margin-bottom: 1rem;">
            Showing the {{ $scoped['tokens'] }} token(s) held by {{ $scoped['holders'] }} member(s) of
            {{ $scoped['name'] }}.
        </p>
    @endif

    {{-- Counts describe the SCOPE, not the table: a search narrows the table and
         must not appear to change how many credentials exist. --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem;">
        @foreach ([
            ['In scope', $totals['total'], ''] ,
            ['Active', $totals['active'], ''] ,
            ['Expired', $totals['expired'], ''] ,
            ['Never used', $totals['unused'], 'A credential nobody has presented'],
        ] as $tile)
            <div style="padding: 0.7rem 0.85rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">
                    {{ $tile[0] }}
                </div>
                <div style="font-size: 1.4rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; margin: 0.15rem 0;">
                    {{ $tile[1] }}
                </div>
                @if ('' !== $tile[2])
                    <div style="font-size: 0.72rem; opacity: 0.65;">{{ $tile[2] }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{ $this->table }}

    <p style="font-size: 0.8rem; opacity: 0.7; margin-top: 1.25rem;">
        Token secrets are not readable here or anywhere else after minting — only a hash is
        stored. Revoking a user's token is their own action under
        {{-- Deliberately not a link: the operator is not standing in for the user. --}}
        Connections → Personal tokens, so that the audit trail names the owner as the actor.
    </p>
</x-filament-panels::page>
