/**
 * Inline Assistant — Gutenberg sidebar for AI text transformation.
 *
 * Zed equivalent: Select text → Ctrl+Enter → Describe → Transform in place.
 *
 * This is a WordPress block editor plugin that adds a sidebar panel
 * for inline AI-powered text transformation.
 */

const { registerPlugin } = wp.plugins;
const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
const {
	PanelBody,
	TextareaControl,
	Button,
	Spinner,
	Notice,
	SelectControl,
} = wp.components;
const { useSelect, useDispatch } = wp.data;
const { useState, useCallback } = wp.element;
const { __ } = wp.i18n;

// Inline Assistant Sidebar Component
function InlineAssistantSidebar() {
	const [prompt, setPrompt] = useState('');
	const [loading, setLoading] = useState(false);
	const [result, setResult] = useState('');
	const [error, setError] = useState('');
	const [model, setModel] = useState('gpt-4o');
	const [provider, setProvider] = useState('openai');

	// Get selected text from the block editor.
	const selectedText = useSelect((select) => {
		const editor = select('core/block-editor');
		if (!editor) return '';

		const selection = editor.getSelectedBlock();
		if (selection) {
			return selection.attributes?.content || '';
		}

		// Try getting selected text from rich text selection.
		const multiSelection = editor.getMultiSelectedBlocks();
		if (multiSelection?.length) {
			return multiSelection.map((block) => block.attributes?.content || '').join('\n\n');
		}

		return '';
	}, []);

	const { replaceBlocks, insertBlocks } = useDispatch('core/block-editor');

	// Handle the transform request.
	const handleTransform = useCallback(async (mode = 'replace') => {
		if (!selectedText.trim() || !prompt.trim()) return;

		setLoading(true);
		setError('');
		setResult('');

		try {
			const response = await fetch(
				`${wpMcpAiInline.restUrl}mcp-ai-pro/v1/inline/transform`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': wpMcpAiInline.nonce,
					},
					body: JSON.stringify({
						text: selectedText,
						prompt,
						model,
						provider,
					}),
				}
			);

			const data = await response.json();

			if (!data.success) {
				throw new Error(data.message || wpMcpAiInline.i18n.error);
			}

			const transformedText = data.data.transformed_text;
			setResult(transformedText);

			// Apply the transformation to the editor.
			if (mode === 'replace') {
				const selectedBlock = wp.data.select('core/block-editor').getSelectedBlock();
				if (selectedBlock) {
					wp.data.dispatch('core/block-editor').updateBlockAttributes(
						selectedBlock.clientId,
						{ content: transformedText }
					);
				}
			} else if (mode === 'insertAfter') {
				const selectedBlock = wp.data.select('core/block-editor').getSelectedBlock();
				if (selectedBlock) {
					const newBlock = wp.blocks.createBlock('core/paragraph', {
						content: transformedText,
					});
					wp.data.dispatch('core/block-editor').insertBlocks(
						newBlock,
						wp.data.select('core/block-editor').getBlockIndex(selectedBlock.clientId) + 1
					);
				}
			}
		} catch (err) {
			setError(err.message || wpMcpAiInline.i18n.error);
		} finally {
			setLoading(false);
		}
	}, [selectedText, prompt, model, provider]);

	const hasSelection = selectedText.trim().length > 0;

	return (
		<>
			<PluginSidebarMoreMenuItem target="nvoos-inline-assistant">
				{wpMcpAiInline.i18n.title}
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="nvoos-inline-assistant"
				title={wpMcpAiInline.i18n.title}
				icon="superhero"
			>
				<PanelBody>
					{!hasSelection && (
						<Notice status="info" isDismissible={false}>
							{wpMcpAiInline.i18n.noSelection}
						</Notice>
					)}

					{hasSelection && (
						<>
							<div className="nvoos-inline__selection-preview">
								<strong>Selected text:</strong>
								<p className="nvoos-inline__selection-text">
									{selectedText.slice(0, 200)}
									{selectedText.length > 200 && '…'}
								</p>
							</div>

							<SelectControl
								label="Model"
								value={provider + '|' + model}
								options={[
									{ label: 'GPT-4o', value: 'openai|gpt-4o' },
									{ label: 'GPT-4o Mini', value: 'openai|gpt-4o-mini' },
									{ label: 'Claude Sonnet 4.5', value: 'anthropic|claude-sonnet-4-5' },
									{ label: 'Gemini 2.5 Flash', value: 'google|gemini-2.5-flash' },
								]}
								onChange={(value) => {
									const [p, m] = value.split('|');
									setProvider(p);
									setModel(m);
								}}
							/>

							<TextareaControl
								label="Transformation prompt"
								placeholder={wpMcpAiInline.i18n.placeholder}
								value={prompt}
								onChange={setPrompt}
								rows={3}
							/>

							<div className="nvoos-inline__actions">
								<Button
									isPrimary
									onClick={() => handleTransform('replace')}
									disabled={loading || !prompt.trim()}
								>
									{loading ? <><Spinner /> {wpMcpAiInline.i18n.transforming}</> : wpMcpAiInline.i18n.replace}
								</Button>
								<Button
									isSecondary
									onClick={() => handleTransform('insertAfter')}
									disabled={loading || !prompt.trim()}
								>
									{wpMcpAiInline.i18n.insertAfter}
								</Button>
							</div>
						</>
					)}

					{error && (
						<Notice status="error" isDismissible onRemove={() => setError('')}>
							{error}
						</Notice>
					)}

					{result && !loading && (
						<div className="nvoos-inline__result">
							<strong>Result:</strong>
							<div className="nvoos-inline__result-text">{result}</div>
						</div>
					)}
				</PanelBody>
			</PluginSidebar>
		</>
	);
}

// Register the plugin.
registerPlugin('nvoos-inline-assistant', {
	render: InlineAssistantSidebar,
	icon: 'superhero',
});
