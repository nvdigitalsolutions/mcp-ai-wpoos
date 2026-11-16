<?php

namespace App\Console\Commands;

use App\Support\AdminThemeGenerator;
use Illuminate\Console\Command;

/**
 * Regenerate the Filament admin palette from the ADS DTCG tokens
 * (Proposal 149 T-0.6).
 *
 * `--check` is the CI/drift form: it exits non-zero instead of writing, so a
 * token change that was not regenerated fails the build rather than shipping a
 * panel that silently disagrees with the storefront.
 */
class AdminThemeCommand extends Command
{
    protected $signature = 'admin:theme
        {--check : Exit non-zero if the committed theme is out of date instead of writing it}';

    protected $description = 'Generate the Filament admin palette from the ADS design tokens (Proposal 149).';

    public function handle(AdminThemeGenerator $generator): int
    {
        $target = base_path(AdminThemeGenerator::TARGET);

        if ($this->option('check')) {
            if ($generator->isInSync($target)) {
                $this->info('Admin theme is in sync with the design tokens.');

                return self::SUCCESS;
            }

            $this->error('Admin theme is OUT OF DATE with the design tokens.');
            $this->line('  Run: php artisan admin:theme');

            return self::FAILURE;
        }

        try {
            $php = $generator->php();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        file_put_contents($target, $php);

        $resolved = $generator->resolve();
        $filament = $generator->filamentColors($resolved['colors']);
        $missing = array_values(array_diff(AdminThemeGenerator::FILAMENT_KEYS, array_keys($filament)));

        $this->info('Wrote '.AdminThemeGenerator::TARGET.' ('.count($resolved['colors']).' tokens).');

        if (array() !== $missing) {
            // Reported, never invented: if a Filament key loses its ADS token,
            // the panel would silently fall back to Filament's own colour.
            $this->warn('ADS has no token for: '.implode(', ', $missing).'. Filament falls back to its palette.');
        }

        return self::SUCCESS;
    }
}
