/**
 * Supplier Security Management JavaScript
 * 
 * Handles UI interactions for the Supplier Security admin interface.
 */

(function($) {
	'use strict';

	/**
	 * Supplier Security Manager
	 */
	const SupplierSecurityManager = {
		/**
		 * Initialize the manager.
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// View supplier details.
			$(document).on('click', '.wp-mcp-ai-view-supplier', this.viewSupplier.bind(this));
			
			// Record incident.
			$(document).on('click', '.wp-mcp-ai-record-incident', this.recordIncident.bind(this));
			
			// Generate SBOM.
			$('#generate-sbom').on('click', this.generateSBOM.bind(this));
			
			// Scan dependencies.
			$('#scan-dependencies').on('click', this.scanDependencies.bind(this));
		},

		/**
		 * View supplier details.
		 *
		 * @param {Event} e Click event.
		 */
		viewSupplier: function(e) {
			e.preventDefault();
			const supplierId = $(e.currentTarget).data('supplier-id');
			
			// Fetch supplier details via REST API.
			$.ajax({
				url: wpMcpAiSupplierSecurity.restUrl + '/' + supplierId,
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiSupplierSecurity.nonce);
				},
				success: function(response) {
					if (response.success && response.supplier) {
						SupplierSecurityManager.showSupplierModal(response.supplier);
					}
				},
				error: function(xhr) {
					alert('Failed to load supplier details');
				}
			});
		},

		/**
		 * Show supplier details modal.
		 *
		 * @param {Object} supplier Supplier data.
		 */
		showSupplierModal: function(supplier) {
			// Create modal HTML.
			const modalHtml = `
				<div id="supplier-modal" class="wp-mcp-ai-modal">
					<div class="wp-mcp-ai-modal-content">
						<span class="wp-mcp-ai-modal-close">&times;</span>
						<h2>${supplier.name}</h2>
						<p><strong>Service:</strong> ${supplier.service}</p>
						<p><strong>Category:</strong> ${supplier.category}</p>
						<p><strong>Risk Level:</strong> ${supplier.risk_level}</p>
						<p><strong>Status:</strong> ${supplier.status}</p>
						<p><strong>Certifications:</strong> ${supplier.certifications ? supplier.certifications.join(', ') : 'None'}</p>
						<p><strong>Compliance:</strong> ${supplier.compliance ? supplier.compliance.join(', ') : 'None'}</p>
						<p><strong>Contact:</strong> ${supplier.contact_email || 'N/A'}</p>
						<p><strong>Documentation:</strong> <a href="${supplier.documentation || '#'}" target="_blank">View</a></p>
						<h3>SLA</h3>
						<ul>
							<li>Uptime: ${supplier.sla ? supplier.sla.uptime : 'N/A'}</li>
							<li>Incident Notification: ${supplier.sla ? supplier.sla.incident_notification : 'N/A'}</li>
							<li>Support Response: ${supplier.sla ? supplier.sla.support_response : 'N/A'}</li>
						</ul>
						<h3>Performance</h3>
						<ul>
							<li>Actual Uptime: ${supplier.performance ? supplier.performance.uptime_actual : 'N/A'}%</li>
							<li>Incidents YTD: ${supplier.performance ? supplier.performance.incidents_ytd : 'N/A'}</li>
						</ul>
					</div>
				</div>
			`;
			
			// Append modal to body.
			$('body').append(modalHtml);
			
			// Bind close event.
			$('.wp-mcp-ai-modal-close').on('click', function() {
				$('#supplier-modal').remove();
			});
		},

		/**
		 * Record incident.
		 *
		 * @param {Event} e Click event.
		 */
		recordIncident: function(e) {
			e.preventDefault();
			const supplierId = $(e.currentTarget).data('supplier-id');
			
			// Prompt for incident details.
			const title = prompt('Incident Title:');
			if (!title) return;
			
			const description = prompt('Incident Description:');
			if (!description) return;
			
			const severity = prompt('Severity (low, medium, high, critical):');
			if (!severity) return;
			
			// Submit incident via REST API.
			$.ajax({
				url: wpMcpAiSupplierSecurity.restUrl + '/' + supplierId + '/incidents',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiSupplierSecurity.nonce);
				},
				data: {
					title: title,
					description: description,
					severity: severity
				},
				success: function(response) {
					if (response.success) {
						alert('Incident recorded successfully');
						location.reload();
					}
				},
				error: function(xhr) {
					alert('Failed to record incident');
				}
			});
		},

		/**
		 * Generate Software Bill of Materials (SBOM).
		 *
		 * @param {Event} e Click event.
		 */
		generateSBOM: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			
			$button.prop('disabled', true).text('Generating...');
			
			$.ajax({
				url: wpMcpAiSupplierSecurity.restUrl + '/sbom',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiSupplierSecurity.nonce);
				},
				success: function(response) {
					if (response.success && response.sbom) {
						// Download SBOM as JSON file.
						const dataStr = JSON.stringify(response.sbom, null, 2);
						const dataUri = 'data:application/json;charset=utf-8,' + encodeURIComponent(dataStr);
						
						const exportFileDefaultName = 'sbom-' + Date.now() + '.json';
						
						const linkElement = document.createElement('a');
						linkElement.setAttribute('href', dataUri);
						linkElement.setAttribute('download', exportFileDefaultName);
						linkElement.click();
						
						alert('SBOM generated successfully');
					}
				},
				error: function(xhr) {
					alert('Failed to generate SBOM');
				},
				complete: function() {
					$button.prop('disabled', false).text('📦 Generate SBOM');
				}
			});
		},

		/**
		 * Scan dependencies for vulnerabilities.
		 *
		 * @param {Event} e Click event.
		 */
		scanDependencies: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			
			$button.prop('disabled', true).text('Scanning...');
			
			$.ajax({
				url: wpMcpAiSupplierSecurity.restUrl + '/scan',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiSupplierSecurity.nonce);
				},
				success: function(response) {
					if (response.success && response.results) {
						const composerVulns = response.results.composer.vulnerabilities || 0;
						const npmVulns = response.results.npm.vulnerabilities || 0;
						const totalVulns = composerVulns + npmVulns;
						
						if (totalVulns > 0) {
							alert('Dependency scan completed: ' + totalVulns + ' vulnerabilities found.\n\n' +
							      'Composer: ' + composerVulns + '\n' +
							      'NPM: ' + npmVulns);
						} else {
							alert('Dependency scan completed: No vulnerabilities found');
						}
					}
				},
				error: function(xhr) {
					alert('Failed to scan dependencies');
				},
				complete: function() {
					$button.prop('disabled', false).text('🔍 Scan Dependencies');
				}
			});
		}
	};

	// Initialize on document ready.
	$(document).ready(function() {
		SupplierSecurityManager.init();
	});

})(jQuery);
