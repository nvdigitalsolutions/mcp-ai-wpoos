# NPM Package Extraction Guide for NV oOS

## Executive Summary

This document outlines strategies for extracting reusable JavaScript components from the NV oOS WordPress plugin into standalone NPM packages that can be used across different projects and frameworks.

## Analysis Results

### Components Ready for Distribution

After analyzing the 67+ JavaScript files in the codebase, we've identified several component categories suitable for NPM distribution:

#### 1. Browser-Native Utilities (Zero External Dependencies)
**Location**: `assets/js/storage-util.js`, `assets/js/chat-clipboard-service.js`

These files use only Web APIs and have no framework dependencies:
- localStorage abstraction with Web Worker optimization
- Clipboard operations with progressive enhancement
- DOM batching for performance optimization

**Distribution Strategy**: These can become a standalone utility library focused on browser performance patterns.

#### 2. Markdown & Content Processing
**Location**: `assets/js/chat-markdown-service.js`

Current implementation uses `marked` and `DOMPurify` as dependencies. The wrapper provides:
- Security-focused configuration
- Custom rendering rules
- XSS protection layer

**Distribution Strategy**: Create a pre-configured security-hardened markdown renderer that other projects can adopt.

#### 3. Event-Driven Architecture Components
**Location**: `assets/js/sse-service.js`, `assets/js/job-event-bus.js`

Server-Sent Events client and event coordination system:
- Reconnection logic
- Error handling patterns
- Event multiplexing

**Distribution Strategy**: Standalone event system for real-time applications.

#### 4. Audio & Transcription Workflows
**Location**: `assets/js/chat-audio-service.js`, `assets/js/chat-transcription-service.js`

MediaRecorder API wrappers and audio processing pipelines:
- Recording state management
- Format conversion
- Quota monitoring

**Distribution Strategy**: Audio toolkit for web applications needing voice features.

## Extraction Architecture

### Option A: Monorepo Approach (Recommended)

Create a packages directory with independent modules:

```
mcp-ai-wpoos/
├── packages/
│   ├── browser-utils/          # Storage, clipboard, DOM utilities
│   ├── markdown-secure/        # Hardened markdown renderer
│   ├── realtime-events/        # SSE and event bus
│   ├── audio-toolkit/          # Recording and transcription
│   └── integration-helpers/    # WordPress-specific wrappers
├── assets/js/                  # Main plugin JS (imports from packages)
└── package.json                # Root workspace config
```

**Benefits**:
- Gradual migration path
- Shared development tooling
- Version synchronization
- Easy local testing

### Option B: Separate Repository

Create standalone repositories for each package:
- Cleaner separation
- Independent versioning
- More setup overhead
- Requires published packages for development

### Option C: Hybrid Approach (Most Flexible)

Keep framework-agnostic code in packages/, WordPress-specific code in plugin:

```
packages/
├── core/              # Framework-agnostic utilities
│   ├── src/
│   ├── tests/
│   └── package.json
└── wordpress-adapter/ # WordPress-specific integration layer
    ├── src/
    └── package.json
```

## Implementation Roadmap

### Phase 1: Foundation (Week 1-2)

**Goal**: Set up infrastructure without breaking existing functionality

Tasks:
1. Add workspace support to root `package.json`
2. Create `packages/` directory structure
3. Set up shared build tooling (tsup or esbuild)
4. Configure shared TypeScript settings
5. Set up testing framework (Vitest recommended)

**Validation**: Build both packages and original plugin successfully

### Phase 2: Extract Browser Utilities (Week 3)

**Goal**: Create first published package

Tasks:
1. Copy browser utility files to `packages/browser-utils/src/`
2. Remove WordPress-specific references
3. Add TypeScript types
4. Write comprehensive tests
5. Create API documentation
6. Publish alpha version to NPM

**Success Criteria**:
- Package installs successfully
- All tests pass
- Can be imported in vanilla JS project
- TypeScript types work correctly

### Phase 3: Extract Event System (Week 4)

**Goal**: Second package with SSE and event coordination

Tasks:
1. Extract SSE service and event bus
2. Create configurable connection parameters
3. Add reconnection strategies
4. Document event patterns
5. Publish beta version

### Phase 4: WordPress Integration Layer (Week 5-6)

**Goal**: Update plugin to consume new packages

Tasks:
1. Install packages as dependencies
2. Create WordPress-specific adapter layer
3. Migrate existing code to use packages
4. Test all plugin functionality
5. Update build process

**Critical**: Ensure zero breaking changes for end users

### Phase 5: Additional Packages (Week 7+)

Based on demand and feedback:
- Markdown renderer package
- Audio toolkit package
- Workflow engine (requires more refactoring)

## Technical Considerations

### Dependency Management

**Current State**:
- Plugin bundles all dependencies
- No tree-shaking between modules
- Duplicated utilities across files

**After Extraction**:
- Packages declare peer dependencies
- Consumers control dependency versions
- Better tree-shaking opportunities

### Build Process Changes

**Current**: Single esbuild config for all files

**Proposed**: 
- Packages build independently
- Root build orchestrates everything
- Maintain backward compatibility

### Testing Strategy

**Package-Level Tests**:
- Unit tests for each function
- Integration tests for API contracts
- Browser compatibility tests (Playwright)

**Plugin Integration Tests**:
- Ensure packages work correctly in WordPress context
- Test upgrade paths
- Verify no regressions

### Versioning Strategy

Use semantic versioning with coordination:
- Packages: Independent versions
- Plugin: Pins package versions
- Breaking changes: Major version bump
- Features: Minor version bump
- Fixes: Patch version bump

## Migration Safety Checklist

Before publishing any package:

- [ ] All existing plugin tests pass
- [ ] Package has comprehensive test coverage (>80%)
- [ ] TypeScript types are complete and accurate
- [ ] Documentation explains all public APIs
- [ ] Examples demonstrate common use cases
- [ ] Browser compatibility is documented
- [ ] Performance characteristics are documented
- [ ] Security considerations are documented
- [ ] License is clearly stated (MIT recommended for packages)
- [ ] Package can be installed and used independently
- [ ] No WordPress globals are required
- [ ] No hardcoded plugin-specific values

## Example: Browser Utils Package Structure

```
packages/browser-utils/
├── src/
│   ├── storage/
│   │   ├── quota-manager.ts
│   │   ├── worker-parser.ts
│   │   └── index.ts
│   ├── clipboard/
│   │   ├── copy-handler.ts
│   │   └── index.ts
│   ├── dom/
│   │   ├── batch-updater.ts
│   │   └── index.ts
│   └── index.ts (main export)
├── tests/
│   ├── storage.test.ts
│   ├── clipboard.test.ts
│   └── dom.test.ts
├── examples/
│   ├── storage-usage.html
│   └── clipboard-demo.html
├── docs/
│   ├── API.md
│   └── MIGRATION.md
├── README.md
├── LICENSE
├── package.json
├── tsconfig.json
└── vitest.config.ts
```

## API Design Principles

### 1. Framework Agnostic
No assumptions about React, Vue, WordPress, etc.

### 2. Progressive Enhancement
Works in all browsers, enhanced features in modern browsers

### 3. Zero Config Default
Works out of the box with sensible defaults

### 4. Full TypeScript Support
Complete type definitions with JSDoc comments

### 5. Tree-Shakeable
Export individual functions, not just namespace objects

### 6. No Side Effects on Import
All actions must be explicitly initiated

## Publishing Checklist

Before publishing to NPM:

1. **Legal Review**
   - [ ] Confirm GPL code isn't included (use MIT for packages)
   - [ ] Check third-party license compatibility
   - [ ] Update CODEOWNERS if needed

2. **Quality Assurance**
   - [ ] Run full test suite
   - [ ] Test in multiple browsers
   - [ ] Verify bundle sizes are reasonable
   - [ ] Check for security vulnerabilities

3. **Documentation**
   - [ ] README with clear examples
   - [ ] API documentation (TypeDoc)
   - [ ] Migration guide if replacing existing code
   - [ ] Changelog for version history

4. **NPM Setup**
   - [ ] Create @nvdigital organization on NPM
   - [ ] Set up 2FA for publishing
   - [ ] Configure package access
   - [ ] Test installation in fresh project

## Maintenance Strategy

### Version Support
- Maintain 2 major versions
- Security patches for older versions
- Clear deprecation timeline

### Update Cadence
- Packages: As needed
- Plugin: Quarterly updates
- Security: Immediate

### Communication Channels
- GitHub Issues for bugs
- Discussions for features
- Blog posts for major releases

## Success Metrics

Track these metrics to evaluate extraction success:

1. **Adoption**: Downloads per month
2. **Quality**: Issue resolution time
3. **Performance**: Bundle size impact
4. **Community**: GitHub stars, contributions
5. **Integration**: Projects using the packages

## Conclusion

Extracting components into NPM packages will:
- Increase code reusability
- Improve testing and quality
- Enable community contributions
- Create additional value streams
- Establish NV Digital as thought leaders

The recommended approach is the monorepo hybrid model, starting with browser utilities and expanding based on community feedback.

**Estimated Timeline**: 6-8 weeks for initial packages
**Risk Level**: Low (with proper testing and gradual migration)
**Impact**: High (enables broader ecosystem participation)
