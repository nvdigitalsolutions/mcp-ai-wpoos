/**
 * Settings Page — Cloudways API credentials management.
 *
 * @since 0.1.0
 */

import { createElement, useState } from 'react';
import { useApi, useMutation } from '../hooks/useApi';

interface SettingsData {
  configured: boolean;
  masked_email: string;
}

interface UpdateSettingsBody {
  cloudways_email: string;
  cloudways_api_key: string;
}

export function SettingsPage(): React.ReactElement {
  const { data, loading, error, refetch } = useApi<SettingsData>('/settings');
  const { mutate: updateSettings, loading: saving, error: saveError } = useMutation<{ ok: boolean }, UpdateSettingsBody>('/settings');

  const [email, setEmail] = useState('');
  const [apiKey, setApiKey] = useState('');
  const [saved, setSaved] = useState(false);

  if (loading) return createElement('div', { className: 'cwd-loading' }, 'Loading settings…');
  if (error) return createElement('div', { className: 'cwd-error' }, `Error: ${error}`);

  const configured = data?.configured ?? false;

  const handleSave = async () => {
    if (!email || !apiKey) return;
    try {
      await updateSettings({ cloudways_email: email, cloudways_api_key: apiKey });
      setSaved(true);
      setApiKey('');
      refetch();
    } catch {
      // handled by error state
    }
  };

  const handleDisconnect = () => {
    setSaved(false);
    refetch();
  };

  return createElement(
    'div',
    { className: 'cwd-page' },
    createElement('h2', null, 'Cloudways API Settings'),

    configured
      ? createElement(
          'div',
          { className: 'cwd-info-box cwd-info-success' },
          createElement('p', null, `✅ Connected as ${data?.masked_email || '—'}`),
          createElement(
            'button',
            { className: 'cwd-btn cwd-btn-danger', onClick: handleDisconnect },
            'Disconnect'
          )
        )
      : createElement('div', { className: 'cwd-info-box cwd-info-warning' }, '⚠️ Not connected — enter your Cloudways API credentials below.'),

    createElement(
      'div',
      { className: 'cwd-form-section' },
      createElement(
        'label',
        { className: 'cwd-field' },
        'Cloudways Email',
        createElement('input', {
          type: 'email',
          className: 'cwd-input',
          value: email,
          onChange: (e: React.ChangeEvent<HTMLInputElement>) => setEmail(e.target.value),
          placeholder: 'you@example.com',
        })
      ),
      createElement(
        'label',
        { className: 'cwd-field' },
        'Cloudways API Key',
        createElement('input', {
          type: 'password',
          className: 'cwd-input',
          value: apiKey,
          onChange: (e: React.ChangeEvent<HTMLInputElement>) => setApiKey(e.target.value),
          placeholder: 'Enter API key',
        })
      ),
      saveError && createElement('div', { className: 'cwd-error' }, saveError),
      saved && createElement('div', { className: 'cwd-success' }, '✅ Credentials saved!'),
      createElement(
        'button',
        { className: 'cwd-btn cwd-btn-primary', disabled: saving || !email || !apiKey, onClick: handleSave },
        saving ? 'Saving…' : 'Save Credentials'
      )
    ),

    createElement(
      'div',
      { className: 'cwd-info-box', style: { marginTop: 24 } },
      createElement('p', null, 'To obtain your Cloudways API credentials:'),
      createElement(
        'ol',
        null,
        createElement('li', null, 'Log in to your Cloudways Platform account.'),
        createElement('li', null, 'Navigate to Grid → API Access.'),
        createElement('li', null, 'Generate a new API key and copy both the email and key.'),
        createElement('li', null, 'Paste them above and click Save Credentials.')
      )
    )
  );
}
