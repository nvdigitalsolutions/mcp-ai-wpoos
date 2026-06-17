import svc, { configure, TranscriptionService } from '../dist/nvoos-transcription.js';

console.assert(svc === TranscriptionService, 'default === named');
console.assert(typeof configure === 'function');

// Constants
console.assert(svc.TRANSCRIBE_TOOL_NAME === 'transcribe_openai_audio');
console.assert(svc.MAX_TRANSCRIBE_BYTES === 26214400);

// Default class is the new neutral name
console.assert(svc.TRANSCRIBE_RECORDING_CLASS === 'nvoos-transcribe--recording', 'default class: ' + svc.TRANSCRIBE_RECORDING_CLASS);

// Configure overrides
configure({ recordingClass: 'my-rec', fileSelectTriggerSelector: '[data-file]' });
console.assert(svc.TRANSCRIBE_RECORDING_CLASS === 'my-rec', 'override applied');

// supportsAudioRecording: false in pure-Node (no MediaRecorder, navigator)
console.assert(svc.supportsAudioRecording() === false, 'no MediaRecorder in Node');

// stopRecordingStream is safe with empty state
svc.stopRecordingStream(null);
svc.stopRecordingStream({});
svc.stopRecordingStream({ recordingStream: null });

// stopRecordingStream actually calls track.stop on each track + nulls it out
let stopped = 0;
const fakeStream = {
	getTracks: () => [
		{ stop: () => stopped++ },
		{ stop: () => stopped++ },
	],
};
const state = { recordingStream: fakeStream };
svc.stopRecordingStream(state);
console.assert(stopped === 2, 'expected 2 track.stop calls, got ' + stopped);
console.assert(state.recordingStream === null, 'recordingStream nulled');

// extractTranscriptionResult unwraps body.result if present, otherwise returns body itself.
const r1 = svc.extractTranscriptionResult({ result: { text: 'hello' } });
console.assert(r1 && r1.text === 'hello', 'unwraps body.result: ' + JSON.stringify(r1));

const r2 = svc.extractTranscriptionResult({ text: 'no wrapper' });
console.assert(r2 && r2.text === 'no wrapper', 'returns body when no .result key: ' + JSON.stringify(r2));

console.assert(svc.extractTranscriptionResult(null) === null, 'null in → null out');
console.assert(svc.extractTranscriptionResult('not an object') === null, 'non-object in → null out');

// API surface coverage
const expected = [
	'supportsAudioRecording', 'stopRecordingStream', 'setTranscribeRecordingState',
	'updateTranscribeButtonState', 'handleTranscribeButtonClick',
	'startTranscribeRecording', 'stopTranscribeRecording',
	'handleTranscribeFileSelection', 'transcribeAudioFile',
	'uploadAudioForTranscription', 'requestTranscription',
	'extractTranscriptionResult', 'insertTranscriptionResult',
];
for (const m of expected) {
	console.assert(typeof svc[m] === 'function', 'missing method: ' + m);
}

console.log('All smoke tests passed.');
