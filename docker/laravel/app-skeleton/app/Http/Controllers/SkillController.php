<?php

namespace App\Http\Controllers;

use App\Support\SkillCatalogue;
use Illuminate\Http\JsonResponse;

/**
 * Agent Skills catalogue (`GET /api/v1/skills`).
 *
 * Port of `WP_MCP_AI_REST_MCP_Controller::handle_skills_catalogue()`. The
 * console's assistant editor lists this to populate its skill picker, so the
 * payload is the WordPress shape — `{success, skills, total}` with
 * `{name, description, license, compatibility}` per entry — because
 * `SkillsClient` is shared by both runtimes.
 *
 * Read-only. WordPress also ships a skill manager (install/replace/delete);
 * those routes are deliberately absent here rather than stubbed, so a client
 * that tries to write gets a 404 instead of a half-working installer.
 */
class SkillController
{
    /**
     * GET /skills — installed skills, name-sorted.
     */
    public function index(): JsonResponse
    {
        $skills = SkillCatalogue::fromConfig()->all();

        return response()->json([
            'success' => true,
            'skills' => $skills,
            'total' => count($skills),
        ]);
    }
}
