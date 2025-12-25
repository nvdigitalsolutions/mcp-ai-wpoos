/**
 * Diagnostic script for chat button functionality
 * 
 * This script checks if chat buttons are properly initialized and responding to events.
 * Add this to the browser console to debug button issues.
 */

(function() {
    'use strict';
    
    console.log('=== WP oOS Chat Button Diagnostic ===');
    
    // Check if services are loaded
    console.log('\n1. Service Availability:');
    console.log('  - wpMcpAiChatUIUtils:', typeof window.wpMcpAiChatUIUtils !== 'undefined' ? '✓ Loaded' : '✗ Missing');
    console.log('  - wpMcpAiChatAudio:', typeof window.wpMcpAiChatAudio !== 'undefined' ? '✓ Loaded' : '✗ Missing');
    console.log('  - wpMcpAiChatInstances:', typeof window.wpMcpAiChatInstances !== 'undefined' ? '✓ Loaded' : '✗ Missing');
    
    // Find chat containers
    const containers = document.querySelectorAll('[data-wp-mcp-ai-chat]');
    console.log('\n2. Chat Containers Found:', containers.length);
    
    if (containers.length === 0) {
        console.warn('  ⚠ No chat containers found on page');
        return;
    }
    
    // Check each container
    containers.forEach(function(container, index) {
        const instanceId = container.getAttribute('id');
        console.log('\n3. Container #' + (index + 1) + ' (ID: ' + instanceId + '):');
        
        // Check if initialized
        const initialized = container.getAttribute('data-wp-mcp-ai-initialized');
        console.log('  - Initialized:', initialized === 'true' ? '✓ Yes' : '✗ No');
        
        // Check state object
        const state = container.__wpMcpAiChatState;
        console.log('  - State object:', state ? '✓ Exists' : '✗ Missing');
        
        if (state) {
            console.log('    - canUploadAttachments:', state.canUploadAttachments);
            console.log('    - busy:', state.busy);
            console.log('    - uploading:', state.uploading);
            console.log('    - transcribing:', state.transcribing);
            console.log('    - isRecording:', state.isRecording);
        }
        
        // Check transcribe button
        const transcribeButton = container.querySelector('.wp-mcp-ai-chat__transcribe');
        console.log('  - Transcribe button:', transcribeButton ? '✓ Found' : '✗ Missing');
        
        if (transcribeButton) {
            console.log('    - Disabled:', transcribeButton.disabled);
            console.log('    - Hidden:', transcribeButton.hidden);
            console.log('    - Has click listener:', transcribeButton.onclick !== null || transcribeButton._listeners);
            
            // Try to manually trigger click
            console.log('    - Testing manual click...');
            try {
                transcribeButton.addEventListener('click', function testClick(e) {
                    console.log('    ✓ Click event fired!', e);
                    transcribeButton.removeEventListener('click', testClick);
                }, { once: true });
                
                transcribeButton.click();
            } catch (error) {
                console.error('    ✗ Click failed:', error);
            }
        }
        
        // Check transcribe input
        const transcribeInput = container.querySelector('.wp-mcp-ai-chat__transcribe-input');
        console.log('  - Transcribe input:', transcribeInput ? '✓ Found' : '✗ Missing');
        
        if (transcribeInput) {
            console.log('    - Disabled:', transcribeInput.disabled);
            console.log('    - Hidden:', transcribeInput.hidden);
            console.log('    - Accept attribute:', transcribeInput.getAttribute('accept'));
        }
        
        // Check voice chat button
        const voiceChatButton = container.querySelector('.wp-mcp-ai-chat__voice-chat');
        console.log('  - Voice chat button:', voiceChatButton ? '✓ Found' : '✗ Missing');
        
        if (voiceChatButton) {
            console.log('    - Disabled:', voiceChatButton.disabled);
            console.log('    - Hidden:', voiceChatButton.hidden);
        }
        
        // Check browser capabilities
        console.log('  - Browser capabilities:');
        console.log('    - getUserMedia:', typeof navigator.mediaDevices !== 'undefined' && typeof navigator.mediaDevices.getUserMedia === 'function' ? '✓ Supported' : '✗ Not supported');
        console.log('    - MediaRecorder:', typeof MediaRecorder !== 'undefined' ? '✓ Supported' : '✗ Not supported');
    });
    
    console.log('\n=== End Diagnostic ===\n');
})();
