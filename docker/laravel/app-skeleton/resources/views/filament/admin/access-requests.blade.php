{{--
    Access requests — Proposal 149 §110, decision 158 D2.

    Read-write, deliberately, and the only page in this slice that writes. Every
    action below calls `recordResource()` in the page class: Livewire traffic is
    excluded from the request middleware (plan §25), so a membership granted here
    would otherwise leave no trace.

    Tables go through `x-admin.data-table` (§55.5), including the actions column,
    whose heading is screen-reader-only.
--}}
@php
    $requests = $this->requests();
    $domains = $this->domains();
    $enabled = $this->jitEnabled();
@endphp

<x-filament-panels::page>
    @if (! $enabled)
        <x-filament::callout
            color="info"
            icon="heroicon-o-information-circle"
            heading="Provisioning is off for this estate"
            description="An identity that authenticates without a linked account is refused, and the attempt is recorded below. Turning on “Provision new accounts on first login” in Settings → Authentication lets a verified address on a verified domain create a viewer account automatically; approving a request here works either way."
        />
    @endif

    <h2 style="font-size: 0.95rem; font-weight: 600; margin: 0.5rem 0;">Waiting for a decision</h2>

    <x-admin.data-table
        caption="Identities that authenticated and were not provisioned"
        :columns="[
            ['label' => 'Address'],
            ['label' => 'Why'],
            ['label' => 'Last attempt'],
            ['srLabel' => 'Actions'],
        ]"
        :rows="count($requests)"
        :empty="'Nobody is waiting. An unlinked identity is still refused, and the attempt is recorded here.'"
    >
        @foreach ($requests as $request)
            <tr>
                <td>
                    <div>{{ '' !== $request['email'] ? $request['email'] : '—' }}</div>
                    <code style="font-size: 0.7rem; opacity: 0.6;">{{ $request['subject'] }}</code>
                </td>
                <td style="opacity: 0.85;">{{ $this->reasonLabel($request['reason']) }}</td>
                <td style="font-size: 0.8rem; opacity: 0.7; white-space: nowrap;">
                    {{ '' !== $request['attempted_at'] ? substr($request['attempted_at'], 0, 16) : '—' }}
                </td>
                <td style="white-space: nowrap;">
                    <button
                        type="button"
                        wire:click="approve({{ $request['id'] }})"
                        style="padding: 0.25rem 0.6rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; font-size: 0.8rem;"
                    >Approve</button>

                    <button
                        type="button"
                        wire:click="dismiss({{ $request['id'] }})"
                        style="padding: 0.25rem 0.6rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; font-size: 0.8rem; margin-left: 0.35rem;"
                    >Dismiss</button>
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>

    <p style="font-size: 0.8rem; opacity: 0.75; margin-top: 0.5rem;">
        Approving grants <strong>viewer</strong> access to this estate and nothing else — no
        other role, no other estate, and no role taken from the identity provider.
    </p>

    <h2 style="font-size: 0.95rem; font-weight: 600; margin: 1.75rem 0 0.5rem;">Allowed domains</h2>

    <x-admin.data-table
        caption="Email domains this estate trusts for first-login provisioning"
        :columns="[
            ['label' => 'Domain'],
            ['label' => 'Status'],
            ['label' => 'Added'],
            ['srLabel' => 'Actions'],
        ]"
        :rows="count($domains)"
        :empty="'No domains. With nothing on this list, provisioning creates nobody even when it is switched on.'"
    >
        @foreach ($domains as $domain)
            <tr>
                <td><code>{{ $domain['domain'] }}</code></td>
                <td>
                    @if ($domain['verified'])
                        <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.7rem; color: #065f46; background: rgba(16, 185, 129, 0.15);">verified</span>
                    @else
                        <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.7rem; color: #92400e; background: rgba(245, 158, 11, 0.15);">unverified</span>
                    @endif
                </td>
                <td style="font-size: 0.8rem; opacity: 0.7; white-space: nowrap;">
                    {{ '' !== $domain['created_at'] ? substr($domain['created_at'], 0, 10) : '—' }}
                </td>
                <td style="white-space: nowrap;">
                    @if ($domain['verified'])
                        <button
                            type="button"
                            wire:click="unverifyDomain({{ $domain['id'] }})"
                            style="padding: 0.25rem 0.6rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; font-size: 0.8rem;"
                        >Unverify</button>
                    @else
                        <button
                            type="button"
                            wire:click="verifyDomain({{ $domain['id'] }})"
                            style="padding: 0.25rem 0.6rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; font-size: 0.8rem;"
                        >Verify</button>
                    @endif

                    <button
                        type="button"
                        wire:click="removeDomain({{ $domain['id'] }})"
                        style="padding: 0.25rem 0.6rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; font-size: 0.8rem; margin-left: 0.35rem;"
                    >Remove</button>
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>

    <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 0.9rem;">
        <label for="new-domain" style="font-weight: 600; font-size: 0.85rem;">Add a domain</label>
        <input
            id="new-domain"
            type="text"
            wire:model="newDomain"
            placeholder="acme.com"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; min-width: 14rem;"
        />
        <button
            type="button"
            wire:click="addDomain"
            style="padding: 0.35rem 0.8rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
        >Add</button>
    </div>

    <p style="font-size: 0.75rem; opacity: 0.7; margin-top: 0.6rem;">
        Matching is exact on the domain after the <code>@</code>, after lowercasing and IDN
        normalisation — <code>notacme.com</code> never satisfies <code>acme.com</code>, and a
        claim on <code>acme.com</code> does not cover <code>eng.acme.com</code>. A newly added
        domain provisions nobody until it is verified, so proposing one is not the same act as
        authorising it.
    </p>
</x-filament-panels::page>
