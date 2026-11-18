<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Services\AssistantShortcutCatalogue;
use App\Support\SkillCatalogue;
use App\Support\SlashCommands\SlashCommandExecutor;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Skills, shortcuts and slash commands — Proposal 149 §49, `/admin/agent-skills`.
 *
 * One page for the row's three console keys, because they are one subject: what
 * an assistant can be told to do without typing it out.
 *
 *  - **`skills`** — `GET /api/v1/skills`, read from `config('oos.skills.path')`.
 *    The WordPress manager installs, replaces and deletes skill folders; those
 *    are file writes driven by HTTP input and are still absent here, as the
 *    contract row says.
 *  - **`shortcuts`** — `GET /api/v1/pro/tool-shortcuts`, resolved through
 *    {@see AssistantShortcutCatalogue} so this page and the console drawer
 *    cannot disagree about which estate's assistant is being read.
 *  - **`slashCommands`** — `GET /api/v1/pro/slash-commands`, filtered by the
 *    executor's own capability check (`available($user)`), not by a second
 *    rule invented here.
 *
 * READ-ONLY. The WordPress slash-command dashboard also executes commands,
 * executes workflows and clears history; all three are writes, and the console
 * owns them (`writer: console`). The execution history is not rendered either:
 * see the page's follow-up note in the plan — `nvoos_slash_command_audit` has
 * no estate column, so a tenant-scoped panel cannot show it without leaking
 * across estates or inventing a join.
 *
 * Every list is filtered server-side, so a header count cannot claim more than
 * the table renders.
 */
class AgentSkills extends Page
{
    protected static ?string $slug = 'agent-skills';

    protected static ?string $navigationLabel = 'Agent skills';

    protected static ?string $title = 'Skills, shortcuts and slash commands';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    // Beside the profession catalogue: both are reference surfaces, not records.
    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.admin.agent-skills';

    /** Free-text filter over the skill name and description. */
    public string $skillSearch = '';

    /** Selected assistant id as a string; '' means the estate default. */
    public string $assistantId = '';

    /** Free-text filter over label, tool and description. */
    public string $shortcutSearch = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function mount(): void
    {
        $this->assistantId = (string) (array_key_first($this->assistants()) ?? '');
    }

    // ── Skills ──────────────────────────────────────────────────────────

    /**
     * The filtered skill catalogue.
     *
     * @return list<array{name: string, description: string, license: string, compatibility: string}>
     */
    public function skills(): array
    {
        $needle = mb_strtolower(trim($this->skillSearch));

        if ('' === $needle) {
            return $this->allSkills();
        }

        return array_values(array_filter(
            $this->allSkills(),
            static fn (array $skill): bool => str_contains(
                mb_strtolower($skill['name'].' '.$skill['description'].' '.$skill['license'].' '.$skill['compatibility']),
                $needle
            )
        ));
    }

    /**
     * The unfiltered catalogue, so the header can say "1 of 3" honestly.
     *
     * @return list<array{name: string, description: string, license: string, compatibility: string}>
     */
    public function allSkills(): array
    {
        return SkillCatalogue::fromConfig()->all();
    }

    /**
     * The directory the runtime reads. Operators need it to answer "why is this
     * list empty" without opening the env file.
     */
    public function skillsPath(): string
    {
        return SkillCatalogue::fromConfig()->path();
    }

    // ── Shortcuts ───────────────────────────────────────────────────────

    /**
     * @return array<int, string>
     */
    public function assistants(): array
    {
        return app(AssistantShortcutCatalogue::class)->assistants();
    }

    /**
     * The normalised shortcut list for the selected assistant.
     *
     * @return array{shortcuts: array<int, array<string, string>>, categories: array<int, string>, assistant_id: int}
     */
    public function shortcuts(): array
    {
        return app(AssistantShortcutCatalogue::class)->forAssistant(
            (int) $this->assistantId,
            $this->shortcutSearch
        );
    }

    // ── Slash commands ──────────────────────────────────────────────────

    /**
     * The commands this account may run, as rows.
     *
     * The capability filter is the executor's, so a command that would refuse
     * at execution time does not appear here as available.
     *
     * @return list<array{name: string, description: string, usage: string, parameters: string, category: string}>
     */
    public function commands(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return array();
        }

        $rows = array();

        foreach (app(SlashCommandExecutor::class)->available($user) as $name => $definition) {
            $rows[] = array(
                'name' => (string) $name,
                'description' => (string) ($definition['description'] ?? ''),
                'usage' => (string) ($definition['usage'] ?? ''),
                'parameters' => implode(', ', (array) ($definition['parameters'] ?? array())),
                'category' => (string) ($definition['category'] ?? ''),
            );
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => array($a['category'], $a['name']) <=> array($b['category'], $b['name'])
        );

        return $rows;
    }

    // ── Filters ─────────────────────────────────────────────────────────

    public function clearSkillSearch(): void
    {
        $this->skillSearch = '';
    }

    public function clearShortcutSearch(): void
    {
        $this->shortcutSearch = '';
    }
}
