// Test packages that don't require external dependencies
import { StorageUtil } from './nvoos-storage/dist/nvoos-storage.js';

console.log('✅ Testing nvoos-storage...');
console.log('  - StorageUtil type:', typeof StorageUtil);
console.log('  - configure method:', typeof StorageUtil.configure);
console.log('  - parseJSON method:', typeof StorageUtil.parseJSON);
console.log('  - stringifyJSON method:', typeof StorageUtil.stringifyJSON);
console.log('  - cleanup method:', typeof StorageUtil.cleanup);

// Test configuration
StorageUtil.configure({
  workerUrl: '/test-worker.js',
  sizeThreshold: 5000
});

console.log('  - Configuration set successfully');
console.log('  - Threshold:', StorageUtil.WORKER_THRESHOLD);

// Test small data (synchronous path)
const testObj = { hello: 'world', number: 42 };
const jsonStr = await StorageUtil.stringifyJSON(testObj);
console.log('  - Stringify test:', jsonStr);

const parsed = await StorageUtil.parseJSON(jsonStr);
console.log('  - Parse test:', parsed);

console.log('');
console.log('✨ nvoos-storage validated successfully!');
console.log('');
console.log('Note: nvoos-markdown requires peer dependencies (marked, dompurify)');
console.log('Note: nvoos-events requires peer dependency (@microsoft/fetch-event-source)');
