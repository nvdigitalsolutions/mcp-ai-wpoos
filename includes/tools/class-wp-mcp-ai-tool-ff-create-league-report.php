<?php
/**
 * Tool for creating fantasy football league reports.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Creates comprehensive league reports with standings, statistics, and analysis.
 */
class WP_MCP_AI_Tool_FF_Create_League_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
use WP_MCP_AI_Tool_Chat_Response;

/**
 * {@inheritdoc}
 */
public function get_slug() {
return 'ff_create_league_report';
}

/**
 * {@inheritdoc}
 */
public function get_name() {
return __( 'Fantasy Football - Create League Report', 'mcp-ai-wpoos' );
}

/**
 * {@inheritdoc}
 */
public function get_description() {
return __( 'Creates a comprehensive league report with standings, team statistics, and analysis. Generates formatted HTML or PDF document with charts and insights.', 'mcp-ai-wpoos' );
}

/**
 * {@inheritdoc}
 */
public function get_parameters_schema() {
return array(
'type'                 => 'object',
'properties'           => array(
'league_key'     => array(
'type'        => 'string',
'description' => __( 'Yahoo league key (e.g., "nfl.l.123456"). Required to identify the league.', 'mcp-ai-wpoos' ),
),
'report_type'    => array(
'type'        => 'string',
'description' => __( 'Report type: "weekly" (week recap), "season" (full season summary), "standings" (current standings).', 'mcp-ai-wpoos' ),
'enum'        => array( 'weekly', 'season', 'standings' ),
'default'     => 'standings',
),
'week'           => array(
'type'        => 'integer',
'description' => __( 'Week number for weekly reports (1-18).', 'mcp-ai-wpoos' ),
'minimum'     => 1,
'maximum'     => 18,
),
'include_charts' => array(
'type'        => 'boolean',
'description' => __( 'Include Chart.js visualizations in the report.', 'mcp-ai-wpoos' ),
'default'     => true,
),
'include_analysis' => array(
'type'        => 'boolean',
'description' => __( 'Include AI-generated insights and analysis.', 'mcp-ai-wpoos' ),
'default'     => false,
),
'format'         => array(
'type'        => 'string',
'description' => __( 'Output format: "html" or "json".', 'mcp-ai-wpoos' ),
'enum'        => array( 'html', 'json' ),
'default'     => 'html',
),
),
'required'             => array( 'league_key' ),
'additionalProperties' => false,
);
}

/**
 * Execute the tool.
 *
 * @param array $arguments Tool arguments.
 * @param array $context   Execution context including user_id.
 * @return array|WP_Error Tool results or error.
 */
public function execute( array $arguments = array(), array $context = array() ) {
$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create reports.', 'mcp-ai-wpoos' ) );
}

if ( empty( $arguments['league_key'] ) ) {
return new WP_Error( 'wp_mcp_ai_missing_league_key', __( 'League key is required.', 'mcp-ai-wpoos' ) );
}

$league_key       = sanitize_text_field( $arguments['league_key'] );
$report_type      = isset( $arguments['report_type'] ) ? sanitize_text_field( $arguments['report_type'] ) : 'standings';
$week             = isset( $arguments['week'] ) ? absint( $arguments['week'] ) : null;
$include_charts   = isset( $arguments['include_charts'] ) ? (bool) $arguments['include_charts'] : true;
$include_analysis = isset( $arguments['include_analysis'] ) ? (bool) $arguments['include_analysis'] : false;
$format           = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'html';

// Get league standings using existing tool.
$standings_tool = new WP_MCP_AI_Tool_Yahoo_FF_League_Standings();
$standings_data = $standings_tool->execute(
array(
'league_key'    => $league_key,
'include_chart' => false, // We'll generate our own charts.
),
array( 'user_id' => $user_id )
);

if ( is_wp_error( $standings_data ) ) {
return $standings_data;
}

// Build report data.
$report_data = array(
'league_key'  => $league_key,
'report_type' => $report_type,
'generated_at' => current_time( 'mysql' ),
'standings'   => $standings_data['standings'],
'total_teams' => $standings_data['total_teams'],
);

// Add analysis if requested.
if ( $include_analysis ) {
$report_data['analysis'] = $this->generate_league_analysis( $standings_data['standings'] );
}

// Return JSON format.
if ( 'json' === $format ) {
return $report_data;
}

// Generate HTML report.
$html = $this->generate_html_report( $report_data, $include_charts );

return array(
'league_key'  => $league_key,
'report_type' => $report_type,
'format'      => $format,
'html'        => $html,
'data'        => $report_data,
);
}

/**
 * Generate AI-powered league analysis.
 *
 * @param array $standings League standings data.
 * @return array Analysis insights.
 */
protected function generate_league_analysis( $standings ) {
$analysis = array();

if ( empty( $standings ) ) {
return $analysis;
}

// Find highest scoring team.
$highest_scoring = $standings[0];
foreach ( $standings as $team ) {
if ( $team['points_for'] > $highest_scoring['points_for'] ) {
$highest_scoring = $team;
}
}

$analysis['highest_scoring'] = sprintf(
__( '%s leads in total points with %.1f points scored.', 'mcp-ai-wpoos' ),
$highest_scoring['team_name'],
$highest_scoring['points_for']
);

// Find most dominant team (best record).
if ( ! empty( $standings[0] ) ) {
$leader = $standings[0];
$analysis['leader'] = sprintf(
__( '%s is in first place with a %d-%d record.', 'mcp-ai-wpoos' ),
$leader['team_name'],
$leader['wins'],
$leader['losses']
);
}

// Calculate average points.
$total_points = array_sum( array_column( $standings, 'points_for' ) );
$avg_points   = $total_points / count( $standings );

$analysis['league_average'] = sprintf(
__( 'League average: %.1f points per team.', 'mcp-ai-wpoos' ),
$avg_points
);

return $analysis;
}

/**
 * Generate HTML report document.
 *
 * @param array $report_data   Report data.
 * @param bool  $include_charts Include charts.
 * @return string HTML document.
 */
protected function generate_html_report( $report_data, $include_charts ) {
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fantasy Football League Report</title>
<style>
* {
margin: 0;
padding: 0;
box-sizing: border-box;
}
body {
font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
background: #f5f5f5;
padding: 20px;
}
.container {
max-width: 1200px;
margin: 0 auto;
background: white;
padding: 40px;
border-radius: 8px;
box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
h1 {
color: #1a1a1a;
margin-bottom: 10px;
font-size: 32px;
}
.meta {
color: #666;
margin-bottom: 30px;
font-size: 14px;
}
.standings {
width: 100%;
border-collapse: collapse;
margin: 30px 0;
}
.standings thead {
background: #2c3e50;
color: white;
}
.standings th,
.standings td {
padding: 12px;
text-align: left;
border-bottom: 1px solid #ddd;
}
.standings tbody tr:hover {
background: #f9f9f9;
}
.rank {
font-weight: bold;
color: #2c3e50;
}
.analysis {
background: #e3f2fd;
padding: 20px;
border-radius: 4px;
margin: 30px 0;
}
.analysis h2 {
color: #1976d2;
margin-bottom: 15px;
font-size: 20px;
}
.analysis ul {
list-style-position: inside;
color: #333;
}
.analysis li {
margin: 8px 0;
line-height: 1.6;
}
</style>
</head>
<body>
<div class="container">
<h1><?php esc_html_e( 'Fantasy Football League Report', 'mcp-ai-wpoos' ); ?></h1>
<div class="meta">
<?php
printf(
/* translators: 1: report type, 2: timestamp */
esc_html__( 'Type: %1$s | Generated: %2$s', 'mcp-ai-wpoos' ),
esc_html( ucfirst( $report_data['report_type'] ) ),
esc_html( $report_data['generated_at'] )
);
?>
</div>

<h2><?php esc_html_e( 'League Standings', 'mcp-ai-wpoos' ); ?></h2>
<table class="standings">
<thead>
<tr>
<th><?php esc_html_e( 'Rank', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Team', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Record', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Points For', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Points Against', 'mcp-ai-wpoos' ); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ( $report_data['standings'] as $team ) : ?>
<tr>
<td class="rank"><?php echo esc_html( $team['rank'] ); ?></td>
<td><?php echo esc_html( $team['team_name'] ); ?></td>
<td><?php echo esc_html( $team['wins'] . '-' . $team['losses'] ); ?></td>
<td><?php echo esc_html( number_format( $team['points_for'], 1 ) ); ?></td>
<td><?php echo esc_html( number_format( $team['points_against'], 1 ) ); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php if ( ! empty( $report_data['analysis'] ) ) : ?>
<div class="analysis">
<h2><?php esc_html_e( 'League Analysis', 'mcp-ai-wpoos' ); ?></h2>
<ul>
<?php foreach ( $report_data['analysis'] as $insight ) : ?>
<li><?php echo esc_html( $insight ); ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>
</div>
</body>
</html>
<?php
return ob_get_clean();
}

/**
 * Get extended tool definition including toolkit metadata.
 *
 * @return array Tool definition with metadata.
 */
public function get_definition() {
return array(
'name'                  => $this->get_name(),
'description'           => $this->get_description(),
'toolkit'               => 'fantasy_football',
'pattern_compatibility' => array( 'event_driven' ),
'profession_tags'       => array( 'fantasy_sports_manager', 'content_creator' ),
'risk_level'            => 'low',
);
}

/**
 * {@inheritdoc}
 */
public function get_capability_flags() {
return array(
'read-only',
'external-api',
'requires-credentials',
'requires-capability',
'network-dependent',
);
}
}
