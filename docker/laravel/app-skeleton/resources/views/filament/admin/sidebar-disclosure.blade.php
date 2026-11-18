{{--
    Sidebar group disclosure — keyboard and screen-reader semantics.

    Proposal 149 §55 step 2. Filament 5 renders a collapsible navigation group's
    header as a plain `<div>` with an Alpine click handler
    (`sidebar/group.blade.php`: `<div … x-on:click="$store.sidebar.toggleCollapsedGroup(label)" class="fi-sidebar-group-btn">`).
    That is: no `role`, no `tabindex`, no `aria-expanded`, no keyboard handler.
    A mouse user can fold a group; a keyboard or screen-reader user cannot, and
    cannot even tell the group is collapsible.

    That contradicts both WCAG 2.1.1 (Keyboard) and this proposal's own §55.7
    acceptance criterion, so it is corrected here rather than accepted. The
    enhancement is strictly additive:

      - `role="button"` + `tabindex="0"` + Enter/Space, which delegates to
        Filament's own click handler (`header.click()`), so the Alpine store and
        the persisted collapse list stay authoritative — this script never
        writes state of its own;
      - `aria-expanded`, kept in sync by observing the `fi-collapsed` class
        Filament toggles, so the attribute cannot drift from the visible state;
      - `aria-controls` pointing at the group's item list, which Filament
        already gives a stable id.

    If a future Filament release renames `.fi-sidebar-group-btn` or stops using
    `fi-collapsed`, the selectors match nothing and the script no-ops: it adds
    attributes to elements it can find and never removes or replaces markup.

    Rendered through `PanelsRenderHook::SIDEBAR_NAV_END`, which is inside the
    `<nav>` after the groups, so `document.currentScript.closest('nav')` is the
    sidebar and a re-render re-runs this block.
--}}
@php( $nonce = $cspNonce ?? null )
<script @if ( filled( $nonce ) ) nonce="{{ $nonce }}" @endif>
    (() => {
        const nav = document.currentScript?.closest('nav');
        if (!nav) return;

        const sync = (header, group) => {
            header.setAttribute('aria-expanded', group.classList.contains('fi-collapsed') ? 'false' : 'true');
        };

        const enhance = () => {
            nav.querySelectorAll('.fi-sidebar-group').forEach((group) => {
                const header = group.querySelector('.fi-sidebar-group-btn');
                if (!header || '1' === header.dataset.sidebarDisclosure) return;

                header.dataset.sidebarDisclosure = '1';
                header.setAttribute('role', 'button');
                header.setAttribute('tabindex', '0');

                const items = group.querySelector('.fi-sidebar-group-items');
                if (items?.id) header.setAttribute('aria-controls', items.id);

                sync(header, group);

                header.addEventListener('keydown', (event) => {
                    if ('Enter' !== event.key && ' ' !== event.key) return;
                    event.preventDefault();
                    header.click();
                    requestAnimationFrame(() => sync(header, group));
                });

                new MutationObserver(() => sync(header, group)).observe(group, {
                    attributes: true,
                    attributeFilter: ['class'],
                });
            });
        };

        enhance();
        new MutationObserver(enhance).observe(nav, { childList: true, subtree: true });
    })();
</script>
