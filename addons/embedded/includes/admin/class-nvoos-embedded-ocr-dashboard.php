<?php
/**
 * Embedded OCR Health Dashboard
 *
 * Admin sub-page under Embedded AI settings showing self-hosted OCR
 * backend status, model information, and quick-test capabilities.
 *
 * @package NV_oOS_Embedded
 * @since   0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OCR Health Dashboard for the embedded addon.
 *
 * @since 0.3.0
 */
class NV_oOS_Embedded_OCR_Dashboard {

	/**
	 * Page slug.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-embedded-ocr-dashboard';

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ), 28 );
	}

	/**
	 * Register the admin sub-page.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'OCR Health', 'nvoos-embedded' ),
			__( 'OCR Health', 'nvoos-embedded' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the OCR health dashboard page.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-embedded' ) );
		}

		$registry = class_exists( 'NV_oOS_Embedded_Backend_Registry' )
			? NV_oOS_Embedded_Backend_Registry::get_instance()
			: null;

		$ocr_backends = array();
		if ( $registry ) {
			foreach ( array( 'self_hosted_ocr_unlimited_ocr', 'self_hosted_ocr_deepseek_ocr' ) as $slug ) {
				$backend = $registry->get_llm_backend( $slug );
				if ( $backend && $backend instanceof NV_oOS_Embedded_Self_Hosted_OCR_Backend ) {
					$ocr_backends[ $slug ] = $backend;
				}
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Self-Hosted OCR Health', 'nvoos-embedded' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Monitor the status of your self-hosted OCR models (Unlimited-OCR and DeepSeek-OCR). These models run on your own GPU via vLLM Docker containers.', 'nvoos-embedded' ); ?>
			</p>

			<?php if ( empty( $ocr_backends ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'No self-hosted OCR backends are registered. Ensure the NV oOS base plugin (v1.5.0+) and Embedded addon (v0.3.0+) are active.', 'nvoos-embedded' ); ?>
					</p>
				</div>
				<?php
				return;
			endif;

			foreach ( $ocr_backends as $slug => $backend ) :
				$health    = $backend->get_health_status();
				$available = $backend->is_available();
				$models    = $backend->get_available_models();
				$reqs      = $backend->get_requirements();

				$status_class = 'good' === $health['status'] ? 'notice-success' : ( 'critical' === $health['status'] ? 'notice-error' : 'notice-warning' );
				$status_icon  = $available ? '🟢' : ( 'critical' === $health['status'] ? '🔴' : '🟡' );
				?>
				<div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
					<h2>
						<?php echo esc_html( $status_icon ); ?>
						<?php echo esc_html( $backend->get_label() ); ?>
					</h2>

					<!-- Status -->
					<div class="notice <?php echo esc_attr( $status_class ); ?>" style="margin: 10px 0;">
						<p><strong><?php echo esc_html( $health['label'] ); ?></strong></p>
						<p><?php echo esc_html( $health['description'] ); ?></p>
					</div>

					<!-- Model Info -->
					<?php if ( ! empty( $models ) ) : ?>
					<h3><?php esc_html_e( 'Model Information', 'nvoos-embedded' ); ?></h3>
					<table class="widefat striped" style="margin-bottom: 15px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Model', 'nvoos-embedded' ); ?></th>
								<th><?php esc_html_e( 'Context Window', 'nvoos-embedded' ); ?></th>
								<th><?php esc_html_e( 'Approx. Size', 'nvoos-embedded' ); ?></th>
								<th><?php esc_html_e( 'Recommended', 'nvoos-embedded' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $models as $model ) : ?>
							<tr>
								<td><?php echo esc_html( $model['label'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $model['context_window'] ) ); ?> tokens</td>
								<td><?php echo esc_html( size_format( $model['size_mb'] * MB_IN_BYTES ) ); ?></td>
								<td><?php echo empty( $model['recommended'] ) ? '—' : '✅'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>

					<!-- Requirements -->
					<h3><?php esc_html_e( 'Requirements', 'nvoos-embedded' ); ?></h3>
					<table class="widefat striped" style="margin-bottom: 15px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Requirement', 'nvoos-embedded' ); ?></th>
								<th><?php esc_html_e( 'Status', 'nvoos-embedded' ); ?></th>
								<th><?php esc_html_e( 'Notes', 'nvoos-embedded' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $reqs as $req ) : ?>
							<tr>
								<td><?php echo esc_html( $req['label'] ); ?></td>
								<td><?php echo empty( $req['status'] ) ? '❌' : '✅'; ?></td>
								<td><?php echo esc_html( $req['note'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<!-- Quick Link to Settings -->
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Endpoint URL', 'nvoos-embedded' ); ?>
						</a>
					</p>
				</div>
				<?php
			endforeach;
			?>

			<!-- Deployment Guide -->
			<div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
				<h2><?php esc_html_e( 'Deployment Guide', 'nvoos-embedded' ); ?></h2>

				<h3><?php esc_html_e( 'Unlimited-OCR (Baidu)', 'nvoos-embedded' ); ?></h3>
				<pre style="background: #f0f0f1; padding: 15px; overflow-x: auto;"><code>docker run --rm --gpus all --network host --ipc host \
	vllm/vllm-openai:unlimited-ocr \
	baidu/Unlimited-OCR \
	--trust-remote-code \
	--logits-processors vllm.model_executor.models.unlimited_ocr:NGramPerReqLogitsProcessor \
	--no-enable-prefix-caching \
	--mm-processor-cache-gb 0 \
	--host 0.0.0.0 \
	--port 8000</code></pre>

				<h3><?php esc_html_e( 'DeepSeek-OCR', 'nvoos-embedded' ); ?></h3>
				<pre style="background: #f0f0f1; padding: 15px; overflow-x: auto;"><code># Requires vLLM nightly build (>=0.11.1)
pip install -U vllm --pre --extra-index-url https://wheels.vllm.ai/nightly

vllm serve deepseek-ai/DeepSeek-OCR \
	--trust-remote-code \
	--logits-processors vllm.model_executor.models.deepseek_ocr:NGramPerReqLogitsProcessor \
	--no-enable-prefix-caching \
	--mm-processor-cache-gb 0 \
	--host 0.0.0.0 \
	--port 8000</code></pre>

				<h3><?php esc_html_e( 'Requirements', 'nvoos-embedded' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'NVIDIA GPU with CUDA 12.9+ (13.0 for Unlimited-OCR Docker image)', 'nvoos-embedded' ); ?></li>
					<li><?php esc_html_e( 'Docker installed and configured with nvidia runtime', 'nvoos-embedded' ); ?></li>
					<li><?php esc_html_e( '~12GB free GPU memory (3B model weights ~6GB + KV cache)', 'nvoos-embedded' ); ?></li>
					<li><?php esc_html_e( '~8-12GB free disk space for Docker image + model weights', 'nvoos-embedded' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
