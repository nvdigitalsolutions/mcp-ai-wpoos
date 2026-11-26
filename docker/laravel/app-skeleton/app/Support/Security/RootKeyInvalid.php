<?php

namespace App\Support\Security;

use InvalidArgumentException;

/**
 * A root security key was rejected at the point of setting it — §188.
 *
 * Separate from `RootKeyRequired`: that one means "prove it", this one means
 * "that value cannot be the key". Rendered as `422`.
 */
final class RootKeyInvalid extends InvalidArgumentException
{
    public const CODE = 'root_key_invalid';
}
