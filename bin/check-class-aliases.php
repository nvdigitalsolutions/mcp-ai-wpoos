#!/usr/bin/env php
<?php
/**
 * Class-alias resolution gate.
 *
 * Fails when a file names a class in a position that resolves to a FQCN that
 * nothing in the index declares.
 *
 * ## The defect this exists to catch
 *
 * PHP resolves an unimported `Foo::class`, `new Foo`, `Foo $x` or `): Foo` to
 * `<current namespace>\Foo` at compile time — silently. No error, no autoload
 * attempt. Four instances of this turned up in one wave of Laravel-runtime work
 * (issue #8131), and each failed in its own quiet way:
 *
 *   - `$app->bind( EventDispatcherInterface::class, ... )` registered a binding
 *     on a name nobody would ever ask for, so registration stopped at the first
 *     tool whose constructor declared the real contract — an alphabetical tell,
 *     not a single-slug one.
 *   - a `catch` on a nonexistent class never matches, so a refused write
 *     answered 500 instead of 403.
 *
 * ## Why not a linter rule
 *
 * PHPStan and PHPCS can be told about unresolved classes, but neither flags
 * `Foo::class` on an unimported name, because the expression is legal PHP. This
 * is a namespace-resolution check, not a style rule. The repo's PHPStan job runs
 * at level 1 over `includes/` and `addons/` only, so it never sees `lib/` or
 * `docker/laravel/` at all.
 *
 * ## Why the token stream, not regex
 *
 * Two of the four original defects sat next to comments that named the real
 * class. A regex would have "found" the import in the prose. Comments, strings
 * and heredocs are therefore excluded by using the tokenizer.
 *
 * ## Resolution order (mirrors PHP)
 *
 *   1. `\Foo\Bar`   — absolute; no import consulted.
 *   2. `namespace\Foo` — relative to the file's namespace.
 *   3. `Foo` / `Foo\Bar` — the *first segment* is looked up in the file's
 *      imports; if it matches, it is replaced and the remainder kept.
 *   4. Otherwise — prefixed with the file's namespace.
 *
 * Class names are case-insensitive, so both the import table and the index are
 * compared case-insensitively.
 *
 * ## Usage
 *
 *   php bin/check-class-aliases.php                 Default roots, exits 1 on findings.
 *   php bin/check-class-aliases.php --report        Never fails; prints findings.
 *   php bin/check-class-aliases.php --self-test     Verify the parser against built-in fixtures.
 *   php bin/check-class-aliases.php --index=DIR     Also index DIR without scanning it (repeatable).
 *   php bin/check-class-aliases.php --root=DIR      Scan DIR instead of the defaults (repeatable).
 *   php bin/check-class-aliases.php --allow=Prefix  Treat Prefix as external (repeatable). A
 *                                                   namespace keeps its trailing `\`; `WP_` needs none.
 *   php bin/check-class-aliases.php --help          Show this help.
 *
 * Exit codes: 0 clean, 1 findings, 2 usage error.
 *
 * @package WP_MCP_AI
 * @since   2.7.0
 */

declare( strict_types=1 );

/**
 * Roots scanned by default: the portable package, its Laravel adapter, the
 * Laravel app, and the WiFi addon built to ADR 001's target layering.
 */
const WP_MCP_AI_ALIAS_DEFAULT_ROOTS = array(
	'lib/core/src',
	'lib/laravel-adapter/src',
	'docker/laravel/app-skeleton/app',
	'docker/laravel/app-skeleton/config',
	'docker/laravel/app-skeleton/routes',
	'docker/laravel/app-skeleton/database',
	'addons/nvoos-wifi',
);

/**
 * Roots indexed but not scanned.
 *
 * The plugin's own declarations live under `includes/`, which is not a scanned
 * root. Indexing it means a reference to `WP_MCP_AI_*` from the WiFi addon
 * resolves exactly instead of being waved through by a prefix, so a typo in a
 * plugin interface name is still caught. Costs roughly six seconds.
 *
 * `addons/` is deliberately absent: it holds ~1,700 Pro tools and a vendor
 * tree, which pushed the walk past two minutes.
 */
const WP_MCP_AI_ALIAS_DEFAULT_INDEX = array( 'includes' );

/**
 * Prefixes treated as external, so they are not expected in the index.
 *
 * Each entry is matched as a plain case-insensitive prefix. Namespaces carry
 * their trailing backslash; bare class prefixes (`WP_`) do not, because
 * WordPress core classes are not namespaced.
 */
const WP_MCP_AI_ALIAS_DEFAULT_ALLOW = array(
	// Composer dependencies.
	'Illuminate\\',
	'Laravel\\',
	'Symfony\\',
	'Psr\\',
	'Cron\\',
	'Monolog\\',
	'Carbon\\',
	'Doctrine\\',
	'Composer\\',
	'Orchestra\\',
	'PhpOffice\\',
	'GuzzleHttp\\',
	'React\\',
	'Ramsey\\',
	'League\\',
	'Spatie\\',
	'Faker\\',
	'PHPUnit\\',
	'DeepCopy\\',
	'JsonSchema\\',
	'Filament\\',
	'Livewire\\',
	'OpenAI\\',
	'Aws\\',
	'Google\\',
	'Stripe\\',
	// PHP namespaces that only exist on a newer interpreter than the one
	// running this gate. `Pdo\Mysql` is used behind a PHP_VERSION_ID guard
	// in config/database.php for exactly that reason.
	'Pdo\\',
	'Dom\\',
	// WordPress core and plugin prefixes. WordPress is not loaded here.
	'WP_',
	'wpdb',
	'Walker_',
	'WooCommerce',
	'WC_',
	'JetEngine',
	'Elementor',
	'RankMath',
	'ActionScheduler',
	// Extension classes provided by the PHP build rather than by a package.
	'ZipArchive',
	'DOMDocument',
	'SimpleXMLElement',
);

/**
 * Vendor and dependency directories skipped when walking a tree.
 *
 * `addons/pro/vendor` alone is large enough to exhaust the CLI memory limit, so
 * a `--index=addons` that walked it would be unusable.
 */
const WP_MCP_AI_ALIAS_SKIP_DIRS = array(
	'vendor',
	'node_modules',
	'dist',
	'build',
);

/**
 * Keywords and declared types that are never class references.
 *
 * Scalar type names are context-sensitive in PHP (`string` is a type in a
 * signature and a constant name elsewhere), so they are filtered from
 * candidates rather than from the resolver.
 */
const WP_MCP_AI_ALIAS_BUILTINS = array(
	'int',
	'integer',
	'float',
	'double',
	'string',
	'bool',
	'boolean',
	'array',
	'object',
	'callable',
	'iterable',
	'mixed',
	'void',
	'never',
	'null',
	'true',
	'false',
	'self',
	'static',
	'parent',
	'resource',
);

/**
 * Parse one file into everything the gate needs.
 *
 * @param string $source File contents.
 * @return array{
 *     namespace: string,
 *     declarations: array<string, bool>,
 *     imports: array<string, string>,
 *     candidates: array<int, array{name: string, line: int}>
 * }
 */
function wp_mcp_ai_alias_parse( string $source ): array {
	$tokens = array();

	foreach ( \PhpToken::tokenize( $source ) as $token ) {
		if ( in_array( $token->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG ), true ) ) {
			continue;
		}

		$tokens[] = $token;
	}

	$namespace       = '';
	$namespace_depth = 0;
	$depth           = 0;
	$declarations    = array();
	$imports         = array();
	$candidates      = array();

	$paren_stack    = array();
	$pending_sig    = false;
	$return_type    = false;
	$implements     = false;
	$catch          = false;
	$trait_use      = false;
	$after_sig_paren = false;

	$total = count( $tokens );

	for ( $i = 0; $i < $total; $i++ ) {
		$token = $tokens[ $i ];
		$id    = $token->id;
		$prev  = $i > 0 ? $tokens[ $i - 1 ] : null;

		// The flag only means anything for the token immediately after a
		// signature's `)`. Consume it here so it cannot leak across `{` or `;`.
		$closed_signature   = $after_sig_paren;
		$after_sig_paren    = false;

		if ( '{' === $token->text ) {
			++$depth;
			$return_type = false;
			$implements  = false;
			continue;
		}

		if ( '}' === $token->text ) {
			--$depth;
			$return_type = false;
			$implements  = false;
			continue;
		}

		if ( ';' === $token->text ) {
			$return_type = false;
			$implements  = false;
			$trait_use   = false;
			continue;
		}

		if ( T_DOUBLE_ARROW === $id ) {
			$return_type = false;
			continue;
		}

		// ── namespace ────────────────────────────────────────────────
		if ( T_NAMESPACE === $id ) {
			$name = '';
			$j    = $i + 1;

			while ( $j < $total && '{' !== $tokens[ $j ]->text && ';' !== $tokens[ $j ]->text ) {
				$name .= $tokens[ $j ]->text;
				++$j;
			}

			$namespace       = trim( $name, '\\' );
			$namespace_depth = ( $j < $total && '{' === $tokens[ $j ]->text ) ? $depth + 1 : 0;
			$i               = $j - 1;
			continue;
		}

		// ─── imports and trait use ─────────────────────────────
		if ( T_USE === $id ) {
			$next = $tokens[ $i + 1 ] ?? null;

			// A closure's `use (...)` sits at the namespace body's depth, so
			// it must be told apart from an import by the `(`.
			if ( $next && '(' === $next->text ) {
				continue;
			}

			if ( $depth === $namespace_depth ) {
				$i = wp_mcp_ai_alias_parse_imports( $tokens, $i, $imports );
				continue;
			}

			$trait_use = true;
			continue;
		}

		// ── declarations ─────────────────────────────────────────────
		if ( in_array( $id, array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
			// `Foo::class` tokenizes `class` as T_CLASS; it declares nothing.
			if ( $prev && T_DOUBLE_COLON === $prev->id ) {
				continue;
			}

			// An anonymous class declares nothing nameable.
			if ( $prev && T_NEW === $prev->id ) {
				continue;
			}

			if ( isset( $tokens[ $i + 1 ] ) && T_STRING === $tokens[ $i + 1 ]->id ) {
				$fqcn = '' === $namespace ? $tokens[ $i + 1 ]->text : $namespace . '\\' . $tokens[ $i + 1 ]->text;
				$declarations[ $fqcn ] = true;
				++$i;
			}

			continue;
		}

		// ── signature parens, so `): Foo` can be recognised ──────────
		if ( T_FUNCTION === $id || T_FN === $id ) {
			$pending_sig = true;
			continue;
		}

		if ( '(' === $token->text ) {
			$paren_stack[] = $pending_sig ? 'signature' : 'other';
			$pending_sig   = false;

			if ( $prev && T_CATCH === $prev->id ) {
				$catch = true;
			}

			continue;
		}

		if ( ')' === $token->text ) {
			$kind            = array_pop( $paren_stack );
			$catch           = false;
			$after_sig_paren = ( 'signature' === $kind );
			continue;
		}

		if ( ':' === $token->text && $closed_signature ) {
			$return_type = true;
			continue;
		}

		if ( T_IMPLEMENTS === $id ) {
			$implements = true;
			continue;
		}

		// ── candidate collection ─────────────────────────────────────
		if ( ! wp_mcp_ai_alias_is_name_token( $id ) ) {
			continue;
		}

		$next          = $tokens[ $i + 1 ] ?? null;
		$is_class_name = false;

		// 1. `Foo::anything`.
		if ( $next && T_DOUBLE_COLON === $next->id ) {
			$is_class_name = true;
		}

		// 2. `new Foo`, `instanceof Foo`, `extends Foo`.
		if ( $prev && in_array( $prev->id, array( T_NEW, T_INSTANCEOF, T_EXTENDS ), true ) ) {
			$is_class_name = true;
		}

		// 3. `implements Foo, Bar` and the commas within it.
		if ( $implements ) {
			$is_class_name = true;
		}

		// 4. `catch ( Foo $e )` and its union members.
		if ( $catch ) {
			$is_class_name = true;
		}

		// 5. A type immediately before a variable: `Foo $x`, `Foo &$x`,
		//    `Foo ...$x`. A named argument (`bar: $x`) has `:` in between
		//    and therefore does not match.
		if ( wp_mcp_ai_alias_before_variable( $tokens, $i ) ) {
			$is_class_name = true;
		}

		// 6. `use Foo, Bar;` inside a class body.
		if ( $trait_use ) {
			$is_class_name = true;
		}

		// 7. A return type after a signature's closing paren.
		if ( $return_type ) {
			$is_class_name = true;
		}

		if ( ! $is_class_name ) {
			continue;
		}

		$candidates[] = array(
			'name' => $token->text,
			'line' => $token->line,
		);
	}

	return array(
		'namespace'    => $namespace,
		'declarations' => $declarations,
		'imports'      => $imports,
		'candidates'   => $candidates,
	);
}

/**
 * Is this token a class-name candidate by shape?
 *
 * `namespace\Foo` is excluded: it can only ever resolve inside the file's own
 * namespace, which the index covers by construction.
 */
function wp_mcp_ai_alias_is_name_token( int $id ): bool {
	if ( T_STRING === $id || T_NAME_QUALIFIED === $id || T_NAME_FULLY_QUALIFIED === $id ) {
		return true;
	}

	return false;
}

/**
 * Does the name at $i sit directly before a variable, modulo `&` and `...`?
 *
 * @param array<int, \PhpToken> $tokens Significant tokens.
 * @param int                   $i      Index of the name token.
 */
function wp_mcp_ai_alias_before_variable( array $tokens, int $i ): bool {
	$total = count( $tokens );
	$j     = $i + 1;

	while ( $j < $total && in_array( $tokens[ $j ]->text, array( '&', '...' ), true ) ) {
		++$j;
	}

	return $j < $total && T_VARIABLE === $tokens[ $j ]->id;
}

/**
 * Parse one `use` statement into `$imports`.
 *
 * Handles `use A\B;`, `use A\B as C;`, `use A\{B, C as D};` and comma lists.
 * `use function` / `use const` are skipped: they import no class name.
 *
 * @param array<int, \PhpToken> $tokens  Significant tokens.
 * @param int                   $start   Index of T_USE.
 * @param array<string, string> $imports Import table, lowercase alias => FQCN.
 * @return int Index to continue from.
 */
function wp_mcp_ai_alias_parse_imports( array $tokens, int $start, array &$imports ): int {
	$total = count( $tokens );
	$i     = $start + 1;

	if ( $i < $total && in_array( $tokens[ $i ]->id, array( T_FUNCTION, T_CONST ), true ) ) {
		while ( $i < $total && ';' !== $tokens[ $i ]->text ) {
			++$i;
		}

		return $i;
	}

	$prefix  = '';
	$pending = '';
	$alias   = null;

	$flush = static function () use ( &$imports, &$prefix, &$pending, &$alias ): void {
		$name = trim( $prefix . $pending, '\\' );

		if ( '' === $name ) {
			$alias   = null;
			$pending = '';

			return;
		}

		if ( null === $alias ) {
			$segments = explode( '\\', $name );
			$alias    = (string) end( $segments );
		}

		$imports[ strtolower( $alias ) ] = $name;

		$alias   = null;
		$pending = '';
	};

	for ( ; $i < $total; $i++ ) {
		$text = $tokens[ $i ]->text;
		$id   = $tokens[ $i ]->id;

		if ( '{' === $text ) {
			$prefix = trim( $pending, '\\' ) . '\\';
			$pending = '';
			continue;
		}

		if ( '}' === $text ) {
			$flush();
			continue;
		}

		if ( ';' === $text ) {
			$flush();

			return $i;
		}

		if ( ',' === $text ) {
			$flush();
			continue;
		}

		if ( T_AS === $id ) {
			// The next token is the alias.
			if ( isset( $tokens[ $i + 1 ] ) ) {
				$alias = $tokens[ $i + 1 ]->text;
				++$i;
			}

			continue;
		}

		$pending .= $text;
	}

	return $i;
}

/**
 * Resolve a name the way PHP would.
 *
 * @param string                $name      Name as written.
 * @param string                $namespace Current namespace ('' for global).
 * @param array<string, string> $imports   Import table, lowercase alias => FQCN.
 */
function wp_mcp_ai_alias_resolve( string $name, string $namespace, array $imports ): string {
	if ( '' === $name ) {
		return '';
	}

	if ( '\\' === $name[0] ) {
		return ltrim( $name, '\\' );
	}

	if ( 0 === stripos( $name, 'namespace\\' ) ) {
		$rest = substr( $name, strlen( 'namespace\\' ) );

		return '' === $namespace ? $rest : $namespace . '\\' . $rest;
	}

	$segments = explode( '\\', $name );
	$first    = strtolower( $segments[0] );

	if ( isset( $imports[ $first ] ) ) {
		$segments[0] = $imports[ $first ];

		return implode( '\\', $segments );
	}

	return '' === $namespace ? $name : $namespace . '\\' . $name;
}

/**
 * Findings for one file, given the index and the vendor allow-list.
 *
 * @param string                $source File contents.
 * @param array<string, bool>   $index  Lowercase FQCN => true.
 * @param array<int, string>    $allow  Vendor prefixes.
 * @return array<int, array{line: int, name: string, resolved: string}>
 */
function wp_mcp_ai_alias_findings( string $source, array $index, array $allow ): array {
	$parsed   = wp_mcp_ai_alias_parse( $source );
	$findings = array();
	$seen     = array();
	$allow    = array_map( 'strtolower', $allow );

	foreach ( $parsed['candidates'] as $candidate ) {
		$name = $candidate['name'];

		if ( in_array( strtolower( $name ), WP_MCP_AI_ALIAS_BUILTINS, true ) ) {
			continue;
		}

		$resolved = wp_mcp_ai_alias_resolve( $name, $parsed['namespace'], $parsed['imports'] );
		$needle   = strtolower( $resolved );

		if ( '' === $needle || isset( $index[ $needle ] ) ) {
			continue;
		}

		$allowed = false;
		foreach ( $allow as $prefix ) {
			if ( 0 === strpos( $needle, $prefix ) ) {
				$allowed = true;
				break;
			}
		}

		if ( $allowed ) {
			continue;
		}

		if ( isset( $seen[ $needle ] ) ) {
			continue;
		}

		$seen[ $needle ] = true;

		$findings[] = array(
			'line'     => $candidate['line'],
			'name'     => $name,
			'resolved' => $resolved,
		);
	}

	return $findings;
}

/**
 * Every `.php` file under a directory, recursively.
 *
 * @return array<int, string>
 */
function wp_mcp_ai_alias_files( string $directory ): array {
	if ( ! is_dir( $directory ) ) {
		return array();
	}

	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $entry ): bool {
				if ( ! $entry->isDir() ) {
					return true;
				}

				return ! in_array( $entry->getFilename(), WP_MCP_AI_ALIAS_SKIP_DIRS, true );
			}
		)
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}

	sort( $files );

	return $files;
}

/**
 * Symbols PHP itself declares: every built-in class, interface, trait and enum.
 *
 * Collected by reflection rather than listed by hand, so `Throwable`,
 * `BackedEnum`, `LogicException` and the rest need no maintenance as PHP adds
 * more. Without this the gate reports every built-in exception as unresolved.
 *
 * @return array<int, string>
 */
function wp_mcp_ai_alias_internal_symbols(): array {
	$symbols = array_merge(
		get_declared_classes(),
		get_declared_interfaces(),
		get_declared_traits()
	);

	$internal = array();

	foreach ( $symbols as $symbol ) {
		try {
			if ( ( new ReflectionClass( $symbol ) )->isInternal() ) {
				$internal[] = $symbol;
			}
		} catch ( ReflectionException $e ) {
			continue;
		}
	}

	return $internal;
}

/**
 * Verify the parser against built-in fixtures.
 *
 * A hand-rolled tokenizer is only as good as its tests, and this script has no
 * PHPUnit home. Each case below is a shape the gate must classify correctly;
 * the four original defects are cases 1, 2, 8 and 9.
 */
function wp_mcp_ai_alias_selftest(): int {
	$index = array(
		'app\\service'                       => true,
		'lib\\contract\\dispatcherinterface'  => true,
		'lib\\error\\notfoundexception'       => true,
		'lib\\model\\thing'                   => true,
	);
	$allow = array( 'Illuminate\\' );

	$cases = array(
		'missing import resolves to the current namespace' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run() { \$this->bind( Contract\\DispatcherInterface::class ); }\n}\n",
			'Contract\\DispatcherInterface',
			'App\\Contract\\DispatcherInterface',
		),
		'qualified name with a lost first segment is caught' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run() { return Model\\Thing::class; }\n}\n",
			'Model\\Thing',
			'App\\Model\\Thing',
		),
		'a correct import resolves and passes' => array(
			"<?php\nnamespace App;\n\nuse Lib\\Contract\\DispatcherInterface;\n\nclass Boot {\n\tpublic function run() { return DispatcherInterface::class; }\n}\n",
			null,
			null,
		),
		'same-namespace declaration passes' => array(
			"<?php\nnamespace App;\n\nclass Service {}\n\nclass Boot {\n\tpublic function run() { return Service::class; }\n}\n",
			null,
			null,
		),
		'new on a missing class is caught' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run() { return new Missing\\Thing(); }\n}\n",
			'Missing\\Thing',
			'App\\Missing\\Thing',
		),
		'a builtin parameter type passes' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run( string \$x, int \$y ): void {}\n}\n",
			null,
			null,
		),
		'a named argument is not a type' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run() { return \$this->svc->call( bar: \$x ); }\n}\n",
			null,
			null,
		),
		'a catch type is checked' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run() { try {} catch ( Missing\\Boom \$e ) {} }\n}\n",
			'Missing\\Boom',
			'App\\Missing\\Boom',
		),
		'a return type is checked' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run(): Missing\\Result {}\n}\n",
			'Missing\\Result',
			'App\\Missing\\Result',
		),
		'a vendor namespace is allow-listed' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run(): \\Illuminate\\Support\\Collection {}\n}\n",
			null,
			null,
		),
		'the real class named in a comment does not rescue a lost import' => array(
			"<?php\nnamespace App;\n\n// This should have been use Lib\\Contract\\DispatcherInterface;\n\nclass Boot {\n\tpublic function run() { return DispatcherInterface::class; }\n}\n",
			'DispatcherInterface',
			'App\\DispatcherInterface',
		),
		'an imported qualified name keeps its remainder' => array(
			"<?php\nnamespace App;\n\nuse Lib\\Model as M;\n\nclass Boot {\n\tpublic function run() { return M\\Thing::class; }\n}\n",
			null,
			null,
		),
		'a group use is parsed' => array(
			"<?php\nnamespace App;\n\nuse Lib\\{Contract\\DispatcherInterface, Error\\NotFoundException};\n\nclass Boot {\n\tpublic function run() { return DispatcherInterface::class; }\n}\n",
			null,
			null,
		),
		'`::class` does not count as a declaration' => array(
			"<?php\nnamespace App;\n\nclass Boot {\n\tpublic function run() { return Lib\\Model\\Thing::class; }\n}\n",
			'Lib\\Model\\Thing',
			'App\\Lib\\Model\\Thing',
		),
		// Two of the four original defects were a *wrong* import rather than a
		// missing one: `Domain\Exception` where the tree declares `Domain\Error`.
		'an import pointing at a nonexistent namespace is caught' => array(
			"<?php\nnamespace App;\n\nuse Lib\\Exception\\NotFoundException;\n\nclass Boot {\n\tpublic function run() { throw new NotFoundException(); }\n}\n",
			'NotFoundException',
			'Lib\\Exception\\NotFoundException',
		),
		'an import pointing at a typoed member is caught' => array(
			"<?php\nnamespace App;\n\nuse Lib\\Error\\NotFoundExceptions;\n\nclass Boot {\n\tpublic function run(): NotFoundExceptions {}\n}\n",
			'NotFoundExceptions',
			'Lib\\Error\\NotFoundExceptions',
		),
	);

	$failures = 0;

	foreach ( $cases as $label => $case ) {
		[$source, $expected_name, $expected_resolved] = $case;
		$findings = wp_mcp_ai_alias_findings( $source, $index, $allow );

		if ( null === $expected_name ) {
			if ( array() !== $findings ) {
				++$failures;
				fwrite( STDOUT, "FAIL  {$label}\n      unexpected finding: {$findings[0]['name']} => {$findings[0]['resolved']}\n" );
			}

			continue;
		}

		$matched = false;
		foreach ( $findings as $finding ) {
			if ( $expected_name === $finding['name'] && $expected_resolved === $finding['resolved'] ) {
				$matched = true;
				break;
			}
		}

		if ( ! $matched ) {
			++$failures;
			$seen = array() === $findings ? '(none)' : $findings[0]['name'] . ' => ' . $findings[0]['resolved'];
			fwrite( STDOUT, "FAIL  {$label}\n      expected {$expected_name} => {$expected_resolved}, got {$seen}\n" );
		}
	}

	if ( 0 !== $failures ) {
		fwrite( STDOUT, "\n{$failures} self-test failure(s).\n" );

		return 1;
	}

	fwrite( STDOUT, 'self-test: ' . count( $cases ) . " case(s) passed.\n" );

	return 0;
}

// ─── CLI ─────────────────────────────────────────────────────────────────

$root    = dirname( __DIR__ );
$options = array(
	'report'    => false,
	'self-test' => false,
	'roots'     => array(),
	'index'     => array(),
	'allow'     => array(),
);

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( '--report' === $argument ) {
		$options['report'] = true;
	} elseif ( '--self-test' === $argument ) {
		$options['self-test'] = true;
	} elseif ( 0 === strpos( $argument, '--root=' ) ) {
		$options['roots'][] = substr( $argument, 7 );
	} elseif ( 0 === strpos( $argument, '--index=' ) ) {
		$options['index'][] = substr( $argument, 8 );
	} elseif ( 0 === strpos( $argument, '--allow=' ) ) {
		$options['allow'][] = substr( $argument, 8 );
	} elseif ( '--help' === $argument || '-h' === $argument ) {
		fwrite( STDOUT, "Usage: php bin/check-class-aliases.php [--report] [--self-test] [--root=DIR] [--index=DIR] [--allow=Prefix]\n" );
		exit( 0 );
	} else {
		fwrite( STDERR, "Unknown argument: {$argument}\n" );
		exit( 2 );
	}
}

if ( $options['self-test'] ) {
	exit( wp_mcp_ai_alias_selftest() );
}

$roots = array() === $options['roots'] ? WP_MCP_AI_ALIAS_DEFAULT_ROOTS : $options['roots'];
$index_roots = array_merge( WP_MCP_AI_ALIAS_DEFAULT_INDEX, $options['index'] );
$allow = array_merge( WP_MCP_AI_ALIAS_DEFAULT_ALLOW, $options['allow'] );

// Index declarations from the scanned roots plus any --index roots. PHP's own
// symbols are seeded first: they are never declared in the tree.
$index    = array();
$scanned  = array();

foreach ( wp_mcp_ai_alias_internal_symbols() as $symbol ) {
	$index[ strtolower( $symbol ) ] = true;
}

foreach ( array_merge( $roots, $index_roots ) as $directory ) {
	foreach ( wp_mcp_ai_alias_files( $root . '/' . $directory ) as $file ) {
		$parsed = wp_mcp_ai_alias_parse( (string) file_get_contents( $file ) );

		foreach ( array_keys( $parsed['declarations'] ) as $fqcn ) {
			$index[ strtolower( (string) $fqcn ) ] = true;
		}
	}
}

foreach ( $roots as $directory ) {
	foreach ( wp_mcp_ai_alias_files( $root . '/' . $directory ) as $file ) {
		$scanned[] = $file;
	}
}

sort( $scanned );

$total = 0;

foreach ( $scanned as $file ) {
	$findings = wp_mcp_ai_alias_findings( (string) file_get_contents( $file ), $index, $allow );

	foreach ( $findings as $finding ) {
		++$total;
		$relative = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );
		fwrite(
			STDOUT,
			"{$relative}:{$finding['line']}  {$finding['name']} resolves to {$finding['resolved']}, which is declared nowhere\n"
		);
	}
}

fwrite(
	STDOUT,
	"\n" . count( $scanned ) . ' file(s) scanned, ' . count( $index ) . ' symbol(s) indexed, ' . $total . " finding(s).\n"
);

if ( 0 !== $total && ! $options['report'] ) {
	fwrite( STDOUT, "FAIL — {$total} unresolved class name(s).\n" );
	exit( 1 );
}

if ( 0 === $total ) {
	fwrite( STDOUT, "OK — every class-position name resolves.\n" );
}

exit( 0 );
