<?php
/**
 * Identity Translator for Symfony Validator
 *
 * Provides a named, serializable implementation of Symfony TranslatorInterface
 * for use with Symfony Validator.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

namespace WP_MCP_AI\Validators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;

/**
 * Class WP_MCP_AI_Identity_Translator
 *
 * A named implementation of Symfony TranslatorInterface that passes
 * messages through unchanged (identity translation). Being a named
 * class — rather than an anonymous class — it fully supports PHP
 * serialization. This prevents "Serialization of
 * 'Symfony\Contracts\Translation\TranslatorInterface@anonymous' is
 * not allowed" errors that occur when the Symfony Validator (which
 * internally creates an anonymous translator) is serialized as part
 * of WordPress transient or option storage.
 */
class WP_MCP_AI_Identity_Translator implements TranslatorInterface, LocaleAwareInterface {
	use TranslatorTrait;
}
