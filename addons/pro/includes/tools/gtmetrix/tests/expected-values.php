<?php
/**
 * GTmetrix toolkit — score-engine oracle (golden values).
 *
 * Generated from the 068 proposal §3 oracle table. Every row must be
 * reproduced by WP_MCP_AI_GTmetrix_Score_Engine (see smoke-run.php).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 */

return array(
	// CWV thresholds — LCP (ms) boundaries.
	'cwv.lcp.good'          => 'good',
	'cwv.lcp.boundary.good' => 'good',
	'cwv.lcp.ni'            => 'needs-improvement',
	'cwv.lcp.poor'          => 'poor',
	// CWV thresholds — CLS.
	'cwv.cls.good'          => 'good',
	'cwv.cls.ni'            => 'needs-improvement',
	'cwv.cls.poor'          => 'poor',
	// CWV thresholds — TBT (INP lab proxy).
	'cwv.tbt.good'          => 'good',
	'cwv.tbt.poor'          => 'poor',
	// Overall assessment on fixture reports.
	'cwv.overall.good'      => 'pass',
	'cwv.overall.fail'      => 'fail',
	// Grade bands (_gtmetrixScore floors).
	'grade.A'               => 'A',
	'grade.B'               => 'B',
	'grade.C'               => 'C',
	'grade.D'               => 'D',
	'grade.E'               => 'E',
	'grade.F'               => 'F',
	// Page-weight budget classes.
	'budget.lean'           => 'lean',
	'budget.acceptable'     => 'acceptable',
	'budget.heavy'          => 'heavy',
	'budget.very_heavy'     => 'very_heavy',
	// LCP phase breakdown sanity (sum ≈ LCP).
	'lcp.phases.sum'        => 1800,
	// Impact ordering.
	'impact.first'          => 'largest-contentful-paint',
	'impact.none_band'      => 'None',
	// Before/after deltas.
	'delta.score.regressed' => 'regressed',
	'delta.lcp.regressed'   => 'regressed',
	'delta.lcp.steady'      => 'steady',
	'delta.cls.regressed'   => 'regressed',
	'delta.bytes.regressed' => 'regressed',
);
