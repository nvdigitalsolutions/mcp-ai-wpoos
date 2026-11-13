<?php

namespace App\Services;

use App\Models\Assistant;
use App\Support\TenantContext;

/**
 * Reads the mirrored assistant-blueprint catalogue.
 *
 * Blueprints are authored next to the Pro toolkits
 * (`addons/pro/includes/tools/<toolkit>/examples/<slug>.json`) and copied into
 * `blueprints/` by `bin/sync-blueprints.php`, because a Laravel deployment does
 * not ship `addons/`. `bin/sync-blueprints.php --check` guards the copy.
 *
 * The normalisation mirrors the WordPress seams exactly
 * (`WP_MCP_AI_Unified_Blueprints_Page::get_all_blueprints_grouped()` and
 * `get_blueprint_details_data()`), so the console receives the same payload
 * from either runtime. Where WordPress reads its toolkit enablement from
 * `wp_mcp_ai_settings`, the Laravel runtime lists every mirrored toolkit: there
 * is no equivalent settings surface yet, and hiding blueprints nobody can
 * enable would be worse than showing them.
 */
final class BlueprintCatalogue
{
    /**
     * Toolkit slug => console label, mirroring the WordPress map.
     */
    private const LABELS = [
        'crm' => 'CRM & Email Marketing',
        'healthcare' => 'Health & Wellness',
        'ecommerce' => 'E-commerce',
        'law-firm' => 'Law Firm',
        'financial-planning' => 'Financial Planning',
        'cre-debt' => 'CRE Debt & Securitization',
        'calendar-booking' => 'Calendar & Booking',
        'dj-management' => 'DJ Management',
        'project-management' => 'Project Management',
        'regulatory-registration' => 'Regulatory Registration',
        'social-media' => 'Social Media',
        'video-production' => 'Video Production',
        'architectural-design' => 'Architectural Design',
        'eca-management' => 'ECA Management',
        'image-production' => 'Image Production',
        'document-generation' => 'Document Generation',
        'analytics' => 'Analytics',
        'chat-channels' => 'Chat Channels',
        'comic-creation' => 'Comic Creation',
        'site-creator-toolkit' => 'Site Creator',
        'multilingual' => 'Multilingual',
        'ai-tool-builder' => 'AI Tool Builder',
        'extended-cognition' => 'Extended Cognition',
        'media' => 'Media Toolkit',
        'email-marketing' => 'Email Marketing',
    ];

    public function __construct(private readonly ?string $root = null)
    {
    }

    /**
     * The grouped catalogue: `{ total, toolkits[] }`.
     *
     * @return array{total:int, toolkits:list<array{slug:string, label:string, blueprints:list<array<string,mixed>>}>}
     */
    public function listing(): array
    {
        $installed = $this->installedMap();
        $toolkits = [];
        $total = 0;

        foreach ($this->toolkitDirectories() as $directory) {
            $toolkit = basename($directory);
            $blueprints = [];

            foreach ($this->slugs($directory) as $slug) {
                $data = $this->load($toolkit, $slug);

                if (null === $data) {
                    continue;
                }

                $name = (string) ($data['name'] ?? $data['post_title'] ?? '');
                $meta = $this->metaBlock($data);
                $tools = $meta['available_tools'] ?? $meta['_wp_mcp_ai_tools'] ?? [];

                $blueprints[] = [
                    'slug' => $slug,
                    'name' => $name,
                    'description' => (string) ($data['description'] ?? ''),
                    'tool_count' => is_array($tools) ? count($tools) : 0,
                    'installed' => isset($installed[$name]),
                    'assistant_id' => $installed[$name] ?? 0,
                ];
            }

            if ([] === $blueprints) {
                continue;
            }

            $total += count($blueprints);
            $toolkits[] = [
                'slug' => $toolkit,
                'label' => self::LABELS[$toolkit] ?? ucfirst($toolkit),
                'blueprints' => $blueprints,
            ];
        }

        return ['total' => $total, 'toolkits' => $toolkits];
    }

    /**
     * The detail payload for one blueprint, or null when it does not exist.
     *
     * @return array{name:string, description:string, instructions:string, tools:array<int,mixed>, defaults:array<string,mixed>|null}|null
     */
    public function detail(string $toolkit, string $slug): ?array
    {
        $data = $this->load($toolkit, $slug);

        if (null === $data) {
            return null;
        }

        $meta = $this->metaBlock($data);

        // Same precedence as WordPress: CRM-style abstract keys first, then the
        // direct WP keys a Healthcare-style blueprint carries.
        $instructions = $meta['instructions']
            ?? $meta['_wp_mcp_ai_system_prompt']
            ?? $data['post_content']
            ?? $data['instructions']
            ?? '';

        $tools = $meta['available_tools'] ?? $meta['_wp_mcp_ai_tools'] ?? $data['tools'] ?? [];

        $defaults = [];

        if (! empty($meta['_wp_mcp_ai_model'])) {
            $defaults['model'] = $meta['_wp_mcp_ai_model'];
        }

        if (isset($meta['_wp_mcp_ai_temperature'])) {
            $defaults['temperature'] = $meta['_wp_mcp_ai_temperature'];
        }

        if (! empty($meta['_wp_mcp_ai_provider'])) {
            $defaults['provider'] = $meta['_wp_mcp_ai_provider'];
        }

        if (! empty($meta['profession'])) {
            $defaults['profession'] = $meta['profession'];
        }

        if (! empty($meta['framework'])) {
            $defaults['framework'] = $meta['framework'];
        }

        if (! empty($meta['channels'])) {
            $defaults['channels'] = $meta['channels'];
        }

        return [
            'name' => $data['name'] ?? $data['post_title'] ?? '',
            'description' => $data['description'] ?? $data['post_content'] ?? '',
            'instructions' => is_string($instructions) ? $instructions : '',
            'tools' => is_array($tools) ? array_values($tools) : [],
            'defaults' => [] !== $defaults ? $defaults : null,
        ];
    }

    /**
     * Assistants installed from a blueprint, keyed by title.
     *
     * Mirrors WordPress: an assistant carries `_blueprint_source` in its meta
     * and the console matches on title. Scoped to the current tenant, because
     * a title from another tenant must never resolve to an id here.
     *
     * @return array<string,int>
     */
    private function installedMap(): array
    {
        $map = [];

        $assistants = Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->get(['id', 'title', 'meta']);

        foreach ($assistants as $assistant) {
            $meta = is_array($assistant->meta) ? $assistant->meta : [];

            if (! array_key_exists('_blueprint_source', $meta)) {
                continue;
            }

            $map[(string) $assistant->title] = (int) $assistant->id;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function toolkitDirectories(): array
    {
        $directories = glob($this->root().'/*', GLOB_ONLYDIR) ?: [];
        sort($directories);

        return $directories;
    }

    /**
     * @return list<string>
     */
    private function slugs(string $directory): array
    {
        $slugs = [];

        foreach (glob($directory.'/*.json') ?: [] as $file) {
            $slug = basename($file, '.json');

            // `schema.json` describes the blueprint format, it is not one.
            if ('schema' === $slug) {
                continue;
            }

            $slugs[] = $slug;
        }

        sort($slugs);

        return $slugs;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load(string $toolkit, string $slug): ?array
    {
        // The values arrive from the URL; refuse anything that is not a plain
        // slug so a crafted path cannot escape the catalogue root.
        if (1 !== preg_match('/^[a-z0-9-]+$/', $toolkit) || 1 !== preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }

        $file = $this->root().'/'.$toolkit.'/'.$slug.'.json';

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function metaBlock(array $data): array
    {
        $meta = $data['meta'] ?? $data['meta_input'] ?? [];

        return is_array($meta) ? $meta : [];
    }

    private function root(): string
    {
        return $this->root ?? base_path('blueprints');
    }
}
