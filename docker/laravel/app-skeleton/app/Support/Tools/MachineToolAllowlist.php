<?php

namespace App\Support\Tools;

use App\Support\Settings\SettingsService;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;

/**
 * The operator's machine-executable tool allowlist (159 slice 4, 149 §127).
 *
 * Two sources, unioned: the deployment's `oos.machine_tools` config (written by
 * someone with shell access) and the operator-scope `machine_tools` setting
 * (written on `/ops`). The setting can add a tool; it cannot remove one the
 * config names — a panel may widen the list but not narrow what a deploy
 * decided, and neither source can grant a permission the other denies.
 *
 * Every candidate is checked against the registry and the readiness manifest
 * before it counts. A typo, a retired tool or a package that is not servable is
 * dropped, so the failure direction is closed: a name that does not resolve
 * refuses the machine rather than permitting it.
 *
 * This is the allowlist, not the whole gate. An allowlisted tool still needs
 * the assistant to assign it, and its own annotations can veto unattended use
 * ({@see MachineToolSafety}).
 */
final class MachineToolAllowlist
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ToolRegistry $registry,
        private readonly ToolReadiness $readiness,
    ) {
    }

    /**
     * The slugs a machine may execute, in registry-independent order.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        $stored = $this->settings->value('machine_tools');

        if (is_string($stored)) {
            $stored = preg_split('/[\s,]+/', $stored) ?: array();
        }

        $slugs = array();

        foreach (array_merge((array) config('oos.machine_tools', array()), (array) $stored) as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $slug = trim($candidate);

            if ('' === $slug || isset($slugs[$slug])) {
                continue;
            }

            if (! isset($this->registry->all()[$slug])) {
                continue;
            }

            if (! ToolReadiness::isServableClass($this->readiness->classify($slug))) {
                continue;
            }

            $slugs[$slug] = true;
        }

        return array_keys($slugs);
    }

    public function allows(string $slug): bool
    {
        return in_array($slug, $this->slugs(), true);
    }
}
