/**
 * Create Site Wizard — multi-step form with provisioning progress.
 *
 * @since 0.1.0
 */

import { createElement, useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useApi, useMutation } from '../hooks/useApi';
import { usePollingApi } from '../hooks/usePollingApi';

interface Server {
  id: number;
  label: string;
  status: string;
}

interface ServersResponse {
  servers: Server[];
}

interface Toolkit {
  slug: string;
  label: string;
  description: string;
}

interface ToolkitsResponse {
  toolkits: Toolkit[];
}

interface CreateResponse {
  app?: { id: number; label: string };
}

interface ProvisioningStatus {
  status: string;
  attempt: number;
  started_at: number;
  last_poll_at: number | null;
  error: string | null;
  results: Record<string, unknown> | null;
  app_data: Record<string, unknown> | null;
  pending_toolkits: Record<string, unknown> | null;
}

export function CreateSiteWizard(): React.ReactElement {
  const navigate = useNavigate();
  const [step, setStep] = useState(1);
  const { data: serversData, loading: serversLoading } = useApi<ServersResponse>('/servers');
  const { data: toolkitsData } = useApi<ToolkitsResponse>('/toolkits');

  // Form state
  const [serverId, setServerId] = useState('');
  const [appLabel, setAppLabel] = useState('');
  const [projectName, setProjectName] = useState('');
  const [application, setApplication] = useState('wordpress');
  const [selectedTKs, setSelectedTKs] = useState<string[]>([]);

  // Provisioning state
  const [createdAppId, setCreatedAppId] = useState<number | null>(null);
  const [provisioning, setProvisioning] = useState(false);

  const { mutate: createApp, loading: creating, error: createError } = useMutation<CreateResponse>('/apps');

  // Poll provisioning status once app is created.
  const provisioningPath = createdAppId ? `/apps/${createdAppId}/provisioning` : '';
  const {
    data: provStatus,
    loading: provLoading,
    error: provError,
  } = usePollingApi<ProvisioningStatus>(
    provisioningPath,
    4000,
    provisioning ? (d: ProvisioningStatus) => d.status === 'ready' || d.status === 'failed' || d.status === 'timeout' : undefined
  );

  const servers = serversData?.servers ?? [];
  const toolkits = toolkitsData?.toolkits ?? [];

  const handleCreate = async () => {
    if (!serverId || !appLabel || !projectName) return;
    try {
      const result = await createApp({
        server_id: parseInt(serverId, 10),
        application,
        app_label: appLabel,
        project_name: projectName,
        toolkit_ids: selectedTKs,
        assistant_ids: [],
      });
      const newAppId = result.app?.id;
      if (newAppId) {
        setCreatedAppId(newAppId);
        setProvisioning(true);
        setStep(4); // Provisioning progress step
      }
    } catch {
      // error handled by useMutation
    }
  };

  const handleGoToSite = () => {
    if (createdAppId) {
      navigate(`/sites/${createdAppId}`);
    }
  };

  const provDone = provStatus?.status === 'ready' || provStatus?.status === 'failed' || provStatus?.status === 'timeout';
  const provAppData = provStatus?.app_data as Record<string, unknown> | undefined;

  return createElement(
    'div',
    { className: 'cwd-page' },
    createElement(Link, { to: '/sites', className: 'cwd-back-link' }, '← Back to Sites'),

    createElement('h2', null, 'Create New WordPress Site'),

    // Step indicator
    createElement(
      'div',
      { className: 'cwd-steps' },
      [1, 2, 3, 4].map((s) =>
        createElement(
          'div',
          { key: s, className: `cwd-step${s === step ? ' is-active' : ''}${s < step ? ' is-done' : ''}` },
          createElement('span', { className: 'cwd-step-num' }, String(s)),
          createElement('span', { className: 'cwd-step-label' },
            s === 1 ? 'Server' : s === 2 ? 'Details' : s === 3 ? 'Toolkits' : 'Provision'
          )
        )
      )
    ),

    // Step 1: Select Server
    step === 1 &&
      createElement(
        'div',
        { className: 'cwd-form-section' },
        createElement('h3', null, 'Select a Server'),
        serversLoading
          ? createElement('div', { className: 'cwd-loading' }, 'Loading servers…')
          : createElement(
              'select',
              { className: 'cwd-select', value: serverId, onChange: (e: any) => setServerId(e.target.value) },
              createElement('option', { value: '' }, '— Choose a server —'),
              servers.map((s) =>
                createElement('option', { key: s.id, value: String(s.id) }, `${s.label} (${s.status})`)
              )
            ),
        createElement(
          'button',
          { className: 'cwd-btn cwd-btn-primary', disabled: !serverId, onClick: () => setStep(2) },
          'Next →'
        )
      ),

    // Step 2: Site Details
    step === 2 &&
      createElement(
        'div',
        { className: 'cwd-form-section' },
        createElement('h3', null, 'Site Details'),
        createElement(
          'label',
          { className: 'cwd-field' },
          'App Label',
          createElement('input', { type: 'text', className: 'cwd-input', value: appLabel, onChange: (e: any) => setAppLabel(e.target.value), placeholder: 'e.g. My Client Site' })
        ),
        createElement(
          'label',
          { className: 'cwd-field' },
          'Project Name',
          createElement('input', { type: 'text', className: 'cwd-input', value: projectName, onChange: (e: any) => setProjectName(e.target.value), placeholder: 'e.g. client-project' })
        ),
        createElement(
          'label',
          { className: 'cwd-field' },
          'Application Type',
          createElement(
            'select',
            { className: 'cwd-select', value: application, onChange: (e: any) => setApplication(e.target.value) },
            createElement('option', { value: 'wordpress' }, 'WordPress'),
            createElement('option', { value: 'woocommerce' }, 'WooCommerce'),
            createElement('option', { value: 'wordpressmu' }, 'WordPress Multisite')
          )
        ),
        createElement(
          'div',
          { className: 'cwd-form-actions' },
          createElement('button', { className: 'cwd-btn cwd-btn-secondary', onClick: () => setStep(1) }, '← Back'),
          createElement('button', { className: 'cwd-btn cwd-btn-primary', disabled: !appLabel || !projectName, onClick: () => setStep(3) }, 'Next →')
        )
      ),

    // Step 3: Select Toolkits + Create
    step === 3 &&
      createElement(
        'div',
        { className: 'cwd-form-section' },
        createElement('h3', null, 'Select Toolkits (optional)'),
        toolkits.length === 0
          ? createElement('p', null, 'No toolkits available.')
          : createElement(
              'div',
              { className: 'cwd-toolkit-grid' },
              toolkits.map((tk) =>
                createElement(
                  'label',
                  {
                    key: tk.slug,
                    className: `cwd-toolkit-card${selectedTKs.includes(tk.slug) ? ' is-selected' : ''}`,
                  },
                  createElement('input', {
                    type: 'checkbox',
                    checked: selectedTKs.includes(tk.slug),
                    onChange: () =>
                      setSelectedTKs((prev) =>
                        prev.includes(tk.slug) ? prev.filter((s) => s !== tk.slug) : [...prev, tk.slug]
                      ),
                    style: { marginRight: 8 },
                  }),
                  createElement('strong', null, tk.label),
                  tk.description && createElement('small', null, tk.description)
                )
              )
            ),
        createError && createElement('div', { className: 'cwd-error' }, createError),
        createElement(
          'div',
          { className: 'cwd-form-actions' },
          createElement('button', { className: 'cwd-btn cwd-btn-secondary', onClick: () => setStep(2) }, '← Back'),
          createElement('button', { className: 'cwd-btn cwd-btn-primary cwd-btn-lg', disabled: creating, onClick: handleCreate },
            creating ? 'Creating…' : '🚀 Create Site'
          )
        )
      ),

    // Step 4: Provisioning Progress
    step === 4 &&
      createElement(
        'div',
        { className: 'cwd-form-section' },
        createElement('h3', null, 'Provisioning in Progress'),

        // Status card
        createElement(
          'div',
          { className: `cwd-info-box${provStatus?.status === 'ready' ? ' cwd-info-success' : provStatus?.status === 'failed' ? '' : ' cwd-info-warning'}` },
          createElement('p', null, getStatusMessage(provStatus?.status || 'provisioning')),
          provStatus && !provDone &&
            createElement('div', { className: 'cwd-resource-bar' },
              createElement('span', { className: 'cwd-resource-label' }, `Poll attempt ${provStatus.attempt}/60`),
              createElement('div', { className: 'cwd-progress' },
                createElement('div', { className: 'cwd-progress-fill', style: { width: `${Math.min((provStatus.attempt / 60) * 100, 100)}%` } })
              )
            ),
          provStatus?.error && createElement('p', { className: 'cwd-error' }, provStatus.error)
        ),

        // App data once ready
        provDone && provAppData &&
          createElement(
            'div',
            { className: 'cwd-stats-grid cwd-stats-sm', style: { marginTop: 16 } },
            createElement(QuickStat, { label: 'Domain', value: (provAppData.cname || provAppData.app_fqdn || '—') as string }),
            createElement(QuickStat, { label: 'Status', value: (provAppData.status || 'unknown') as string }),
            createElement(QuickStat, { label: 'Toolkits', value: String(selectedTKs.length) })
          ),

        // Results summary
        provStatus?.results &&
          createElement(
            'div',
            { className: 'cwd-info-box', style: { marginTop: 12 } },
            createElement('p', null, '✅ Provisioning complete!'),
            createElement('p', null,
              `Plugin install: ${(provStatus.results as Record<string, unknown>).plugin_install || '—'}`
            )
          ),

        createElement(
          'div',
          { className: 'cwd-form-actions', style: { marginTop: 16 } },
          provDone
            ? createElement('button', { className: 'cwd-btn cwd-btn-primary cwd-btn-lg', onClick: handleGoToSite }, 'View Site →')
            : createElement('span', { className: 'cwd-loading' }, '⏳ Waiting for Cloudways to provision your app… This may take a few minutes.')
        )
      )
  );
}

function getStatusMessage(status: string): string {
  switch (status) {
    case 'provisioning': return '🔄 Cloudways is provisioning your WordPress site…';
    case 'ready': return '✅ Your site is ready!';
    case 'failed': return '❌ Provisioning failed.';
    case 'timeout': return '⏰ Provisioning timed out. The site may still be deploying — check Cloudways directly.';
    default: return '🔄 Provisioning in progress…';
  }
}

function QuickStat({ label, value }: { label: string; value: string }): React.ReactElement {
  return createElement(
    'div',
    { className: 'cwd-quick-stat' },
    createElement('span', { className: 'cwd-quick-stat-label' }, label),
    createElement('span', { className: 'cwd-quick-stat-value' }, value)
  );
}
