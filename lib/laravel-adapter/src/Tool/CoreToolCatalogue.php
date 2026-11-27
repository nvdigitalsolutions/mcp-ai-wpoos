<?php
/**
 * Self-contained core tool catalogue.
 *
 * `lib/core` ships ~198 tool classes, but the Laravel provider registered only
 * `color_convert` because most tools need constructor dependencies that are not
 * wired yet. This catalogue lists the tools that need **nothing but** the error
 * factory, so they can be registered today with no keys, no HTTP client and no
 * provider configuration.
 *
 * The list is explicit rather than reflective: a tool joins it only after its
 * constructor is verified as self-contained, and the grouping comments record
 * why each family qualifies. Anything that needs a service belongs in the
 * provider's own registration, not here.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Tool\Base64Tool;
use Nvoos\Core\Tool\ClientAnalyzeSentimentTool;
use Nvoos\Core\Tool\ClientExtractEntitiesTool;
use Nvoos\Core\Tool\ClientQuestionAnsweringTool;
use Nvoos\Core\Tool\ClientSemanticSearchTool;
use Nvoos\Core\Tool\ClientSummarizeTextTool;
use Nvoos\Core\Tool\ClientTranslateTextTool;
use Nvoos\Core\Tool\ColorConvertTool;
use Nvoos\Core\Tool\CountTokensTool;
use Nvoos\Core\Tool\EnableReasoningModeTool;
use Nvoos\Core\Tool\ExtractDomainTool;
use Nvoos\Core\Tool\FormatBytesTool;
use Nvoos\Core\Tool\FormatDateTool;
use Nvoos\Core\Tool\GenerateEmailTemplateTool;
use Nvoos\Core\Tool\GenerateSlugTool;
use Nvoos\Core\Tool\GenerateUuidTool;
use Nvoos\Core\Tool\HashStringTool;
use Nvoos\Core\Tool\ListPromptCuesTool;
use Nvoos\Core\Tool\MathEvalTool;
use Nvoos\Core\Tool\MergeArraysTool;
use Nvoos\Core\Tool\ParseCsvTool;
use Nvoos\Core\Tool\ScopeMemoryTool;
use Nvoos\Core\Tool\Ecommerce\ShippingBoxPackerTool;
use Nvoos\Core\Tool\StripHtmlTool;
use Nvoos\Core\Tool\SuggestBestModelTool;
use Nvoos\Core\Tool\TimeAgoTool;
use Nvoos\Core\Tool\TruncateTextTool;
use Nvoos\Core\Tool\ValidateJsonTool;
use Nvoos\Core\Tool\ValidateToolSchemaTool;
use Nvoos\Core\Tool\VerifyInformationTool;

final class CoreToolCatalogue {

	/**
	 * Tools whose constructor is satisfied by the error factory alone.
	 *
	 * Grouped by why they qualify, so a reviewer can see the bar: pure
	 * transforms, formatting, identifiers/validation, and the client-side tools
	 * that run in the browser and hold no server state.
	 *
	 * @return array<int, class-string<ToolInterface>>
	 */
	public static function selfContained(): array {
		return array(
			// Pure transforms.
			Base64Tool::class,
			HashStringTool::class,
			MathEvalTool::class,
			StripHtmlTool::class,
			ParseCsvTool::class,
			MergeArraysTool::class,

			// Formatting / presentation.
			TimeAgoTool::class,
			FormatDateTool::class,
			FormatBytesTool::class,
			TruncateTextTool::class,
			ColorConvertTool::class,

			// Identifiers, slugs and validation.
			GenerateSlugTool::class,
			GenerateUuidTool::class,
			ValidateJsonTool::class,
			ExtractDomainTool::class,
			CountTokensTool::class,

			// Model advisory (no network; reads a local matrix).
			SuggestBestModelTool::class,

			// Domain computation (Proposal 164 section 164.116): a pure bin packer.
			// It composes nothing and calls nothing, so it needs only the error
			// factory — which is what makes it the substrate the rate estimator
			// can be built on here.
			ShippingBoxPackerTool::class,

			// Client-side tools: the browser performs the work, so the server
			// half needs no provider, no HTTP client and no stored state.
			ClientSummarizeTextTool::class,
			ClientTranslateTextTool::class,
		ClientSemanticSearchTool::class,
		ClientExtractEntitiesTool::class,
		ClientAnalyzeSentimentTool::class,
		ClientQuestionAnsweringTool::class,

			// Ported from the WordPress plugin (090-D cluster 1): identical
			// slug, parameter schema and result fields, canonical envelope.
			VerifyInformationTool::class,
			ValidateToolSchemaTool::class,

			// 090-D cluster 1, email family. The MJML compiler is an optional
			// constructor argument, so this registers without one: `mjml` and
			// `mjml`-only output work here, and `html` reports that the runtime
			// has no compiler instead of failing obscurely.
			GenerateEmailTemplateTool::class,

			// 090-D cluster 1, harness family. Same shape: the harness-profile
			// provider is optional, so tag computation works anywhere and
			// `pii_filter` reports the documented default when no provider is
			// bound.
			ScopeMemoryTool::class,

			// 090-D cluster 1, harness family. The cue catalogue moves with the
			// tool, so this serves the same eight seeded cues everywhere; a
			// caller that registers extra cues passes its own library.
			ListPromptCuesTool::class,

			// 090-D cluster 1, reasoning family. Scoring is pure, so this needs
			// no service; the optional controller exists so a caller can pin the
			// clock that stamps `reasoning_metadata.activated_at`.
			EnableReasoningModeTool::class,
		);
	}

	/**
	 * Instantiate every self-contained tool.
	 *
	 * @param ErrorFactoryInterface $errors Error factory.
	 * @return array<int, ToolInterface>
	 */
	/**
	 * Instantiate the self-contained tools.
	 *
	 * Every tool in the list takes only the error factory, with one exception:
	 * {@see ScopeMemoryTool} also accepts a harness-profile provider. It is still
	 * self-contained — the provider is optional and absence means "this runtime
	 * has no harness storage" — but a runtime that binds one gets resolved
	 * values instead of the documented defaults.
	 */
	public static function build(
		ErrorFactoryInterface $errors,
		?HarnessProfileProviderInterface $harnessProfiles = null,
	): array {
		return array_map(
			static fn ( string $class ): ToolInterface => ScopeMemoryTool::class === $class
				? new ScopeMemoryTool( $errors, $harnessProfiles )
				: new $class( $errors ),
			self::selfContained()
		);
	}
}
