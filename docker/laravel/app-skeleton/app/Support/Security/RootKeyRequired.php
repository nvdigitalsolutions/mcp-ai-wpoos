<?php

namespace App\Support\Security;

use RuntimeException;

/**
 * The operator action needs a verified root security key — Proposal 149 §188.
 *
 * Rendered as `423 Locked`, which is the honest status: the caller is
 * authenticated and authorised, and the request is refused until a second
 * credential is presented. `403` would say "you may not", which is wrong.
 */
final class RootKeyRequired extends RuntimeException
{
    public const CODE = 'root_key_required';

    public function __construct()
    {
        parent::__construct(
            'This action changes operator-scope settings and requires the root security key.'
        );
    }
}
