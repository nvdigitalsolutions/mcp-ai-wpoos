<?php
/**
 * WordPress Adapter — Profession Repository.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

/**
 * WordPress adapter for profession data access.
 *
 * Bridges to legacy ProfessionService, KnowledgeBaseLoader,
 * PlaybookLoader, and ToolRecommender.
 *
 * @since 2.0.0
 */
final class ProfessionRepository implements ProfessionRepositoryInterface
{
    /**
     * Legacy profession service.
     *
     * @var \WP_MCP_AI_Profession_Service|null
     */
    private $service;

    /**
     * Legacy knowledge base loader.
     *
     * @var \WP_MCP_AI_Profession_Knowledge_Base_Loader|null
     */
    private $kbLoader;

    /**
     * Legacy tool recommender.
     *
     * @var \WP_MCP_AI_Profession_Tool_Recommender|null
     */
    private $toolRecommender;

    public function __construct()
    {
        if (\class_exists('WP_MCP_AI_Profession_Service') && \class_exists('WP_MCP_AI_Profession_Repository')) {
            $repo    = new \WP_MCP_AI_Profession_Repository();
            $this->service = new \WP_MCP_AI_Profession_Service($repo);
        }

        if (\class_exists('WP_MCP_AI_Profession_Knowledge_Base_Loader')) {
            $this->kbLoader = new \WP_MCP_AI_Profession_Knowledge_Base_Loader();
        }

        if (\class_exists('WP_MCP_AI_Profession_Tool_Recommender')) {
            $this->toolRecommender = new \WP_MCP_AI_Profession_Tool_Recommender();
        }
    }

    public function getAll(): array
    {
        if ($this->service === null) {
            return [];
        }

        /** @var array<string, array> $professions */
        $professions = $this->service->get_all_professions();

        return \is_array($professions) ? $professions : [];
    }

    public function getBySlug(string $slug): array
    {
        if ($this->service === null) {
            return ['found' => false];
        }

        /** @var array|null $profession */
        $profession = $this->service->get_profession($slug);

        if ($profession === null) {
            return ['found' => false];
        }

        return ['found' => true, 'profession' => $profession];
    }

    public function getByCategory(string $category): array
    {
        if ($this->service === null) {
            return [];
        }

        /** @var array<string, array> $professions */
        $professions = $this->service->get_professions_by_category($category);

        return \is_array($professions) ? $professions : [];
    }

    public function loadKnowledgeBase(string $slug): array
    {
        $result = ['knowledge' => [], 'playbook' => []];

        if ($this->kbLoader !== null && \method_exists($this->kbLoader, 'load_for_profession')) {
            /** @var array $kb */
            $kb = $this->kbLoader->load_for_profession($slug);
            if (\is_array($kb)) {
                $result['knowledge'] = $kb;
            }
        }

        // Load playbook if available.
        if (\class_exists('WP_MCP_AI_Profession_Playbook_Loader')) {
            $playbookLoader = new \WP_MCP_AI_Profession_Playbook_Loader();
            if (\method_exists($playbookLoader, 'load_for_profession')) {
                /** @var array $playbook */
                $playbook = $playbookLoader->load_for_profession($slug);
                if (\is_array($playbook)) {
                    $result['playbook'] = $playbook;
                }
            }
        }

        return $result;
    }

    public function getRecommendedTools(string $slug): array
    {
        if ($this->toolRecommender !== null && \method_exists($this->toolRecommender, 'get_recommended_tools')) {
            /** @var array<int, string> $tools */
            $tools = $this->toolRecommender->get_recommended_tools($slug);

            return \is_array($tools) ? $tools : [];
        }

        if ($this->service !== null && \method_exists($this->service, 'get_recommended_tools')) {
            /** @var array<int, string> $tools */
            $tools = $this->service->get_recommended_tools($slug);

            return \is_array($tools) ? $tools : [];
        }

        return [];
    }

    public function getCategories(): array
    {
        if ($this->service === null || !\method_exists($this->service, 'get_categories')) {
            return [];
        }

        /** @var array<string, array> $categories */
        $categories = $this->service->get_categories();

        return \is_array($categories) ? $categories : [];
    }
}
