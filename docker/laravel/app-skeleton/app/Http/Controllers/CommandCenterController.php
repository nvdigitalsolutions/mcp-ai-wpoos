<?php

namespace App\Http\Controllers;

use App\Support\CommandCentre\CommandCentreReport;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The command-centre overview (090-i).
 *
 *   GET /api/v1/pro/command-center/overview
 *
 * Six KPIs, an agent status map and an activity feed, in the shape the
 * console's `CommandCenterClient` parses.
 *
 * The numbers are assembled by `CommandCentreReport` rather than here, because
 * Proposal 149 §106 put the same overview on `/admin` and two copies of six
 * definitions is how a console and a panel start disagreeing (§105). This
 * controller owns exactly two things the page does not: the JSON envelope, and
 * the one shape difference it implies — `agent_statuses` is cast to an object
 * so an all-numeric key set does not encode as a JSON list and render empty in
 * the console.
 */
class CommandCenterController
{
    public function __construct(
        private readonly CommandCentreReport $report,
    ) {
    }

    /**
     * The dashboard payload.
     */
    public function overview(): JsonResponse
    {
        $report = $this->report->report(TenantContext::current());

        // An object, not a list: the console iterates Object.values().
        $report['agent_statuses'] = (object) $report['agent_statuses'];

        return response()->json(array(
            'success' => true,
            'data' => $report,
        ));
    }
}
