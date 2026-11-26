<?php

namespace App\Support\Skills;

use InvalidArgumentException;

/**
 * A SKILL.md that the installer refused — Proposal 149 §190.
 *
 * Rejected at validation, before anything touches the filesystem. The message is
 * shown to the operator as-is, so it names the rule that was broken rather than
 * saying "invalid input".
 */
final class SkillInstallInvalid extends InvalidArgumentException
{
    public const CODE = 'skill_install_invalid';
}
