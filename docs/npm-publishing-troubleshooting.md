# NPM Publishing Troubleshooting Guide

This guide helps resolve common issues when publishing NPM packages via GitHub Actions.

## Table of Contents

- [OTP/2FA Error (EOTP) - Most Common](#otp2fa-error-eotp---most-common)
- [Authentication Errors](#authentication-errors)
- [Permission Errors](#permission-errors)
- [Build Failures](#build-failures)
- [Package Name Conflicts](#package-name-conflicts)
- [Testing Before Publishing](#testing-before-publishing)

---

## OTP/2FA Error (EOTP) - Most Common

### Error Message

```
npm error code EOTP
npm error This operation requires a one-time password from your authenticator.
npm error You can provide a one-time password by passing --otp=<code> to the command you ran.
```

### Root Cause

Your NPM account has 2FA enabled (which is good for security!), but you're using a **"Publish"** or **"Granular Access Token"** type in the `NPM_TOKEN` secret. These token types require interactive OTP codes that GitHub Actions cannot provide.

### Solution

You need to use an **"Automation"** token instead:

#### Step 1: Generate Automation Token

1. Go to https://www.npmjs.com/settings/tokens
2. Click "Generate New Token"
3. **Select "Automation" token type** (this is crucial!)
   - ❌ DO NOT select "Publish"
   - ❌ DO NOT select "Granular Access Token"
   - ✅ Select "Automation"
4. Give it a descriptive name like "GitHub Actions - mcp-ai-wpoos"
5. Click "Generate Token"
6. **Copy the token immediately** (it's only shown once)

#### Step 2: Update GitHub Secret

1. Go to your repository on GitHub
2. Navigate to: **Settings** → **Secrets and variables** → **Actions**
3. Find the `NPM_TOKEN` secret
4. Click the pencil icon to edit (or delete and recreate)
5. Paste the new Automation token
6. Click "Update secret"

#### Step 3: Re-run the Workflow

1. Go to the **Actions** tab
2. Find the failed workflow run
3. Click "Re-run failed jobs" or "Re-run all jobs"

### Why This Works

| Token Type | Requires OTP? | Works in CI/CD? | Use Case |
|------------|---------------|-----------------|----------|
| **Automation** | ❌ No | ✅ Yes | CI/CD pipelines, GitHub Actions |
| Publish | ✅ Yes | ❌ No | Manual publishing only |
| Granular Access Token | ✅ Yes (if 2FA enabled) | ❌ No | Fine-grained permissions |

**Automation tokens** are specifically designed for CI/CD systems. They bypass the OTP requirement while maintaining security through:
- Token-based authentication
- Scoped permissions
- Audit logging
- Revocable access

### Verification

After updating the token, the workflow should show:

```
✅ NPM authentication token found

⚠️ IMPORTANT: Ensure your NPM_TOKEN is an 'Automation' token type
   If you see 'EOTP' errors, you need to regenerate with 'Automation' type
   Generate at: https://www.npmjs.com/settings/tokens
```

And publishing should succeed without OTP prompts.

---

## Authentication Errors

### Error: 401 Unauthorized

**Cause:** Invalid or expired NPM token

**Solution:**
1. Generate a new Automation token at https://www.npmjs.com/settings/tokens
2. Update the `NPM_TOKEN` secret in GitHub repository settings
3. Ensure the token hasn't expired (check token creation date)

### Error: NPM_TOKEN secret not configured

**Cause:** The GitHub secret is missing or named incorrectly

**Solution:**
1. Go to repository **Settings** → **Secrets and variables** → **Actions**
2. Click "New repository secret"
3. Name: exactly `NPM_TOKEN` (case-sensitive)
4. Value: Your NPM Automation token
5. Click "Add secret"

---

## Permission Errors

### Error: 403 Forbidden

**Cause:** You don't have publish permissions for the package or organization

**Solution:**

1. **Check Organization Membership:**
   - Go to https://www.npmjs.com/settings/[your-username]/organizations
   - Verify you're a member of `@nvdigitalsolutions`
   - Ensure you have "Developer" or "Owner" role

2. **Verify Package Access:**
   - Go to https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-storage
   - Check if the package exists and you have permissions
   - Contact organization owner if needed

3. **Check Token Permissions:**
   - Automation tokens should have full publish permissions by default
   - If using Granular Access Token (not recommended), verify package access

### Error: Package name conflict

**Cause:** A package with the same name already exists and you don't own it

**Solution:**
1. Check if the package exists: https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-storage
2. If it exists and you should have access, verify organization membership
3. If using a different organization, update `package.json`:
   ```json
   {
     "name": "@your-org/nvoos-storage"
   }
   ```

---

## Build Failures

### Error: Build script failed

**Cause:** Missing dependencies or build errors in the package

**Solution:**

1. **Test locally:**
   ```bash
   cd packages/nvoos-storage
   npm run build
   ```

2. **Check dependencies:**
   - Ensure all dependencies are listed in `package.json`
   - Run `npm install` to verify dependencies resolve

3. **Check build script:**
   - Verify the `build` script exists in `package.json`
   - Test the build command manually

4. **Check Node.js version:**
   - Workflow uses Node.js 20
   - Verify your code is compatible with Node 20
   - Check `engines` field in `package.json`

### Error: dist/ directory not found

**Cause:** Build didn't create output files

**Solution:**
1. Verify your build script creates the `dist/` directory
2. Check that TypeScript or bundler is configured correctly
3. Test locally: `npm run build && ls -la dist/`

---

## Package Name Conflicts

### Error: You do not have permission to publish

**Cause:** Package name is taken or you don't have access

**Solution:**

1. **Search for existing package:**
   ```bash
   npm search @nvdigitalsolutions/nvoos-storage
   ```

2. **If package exists:**
   - Check if you should have access (organization member?)
   - Contact organization owner
   - Use a different package name if necessary

3. **If using scoped package:**
   - Ensure you're a member of the organization
   - Verify organization exists on NPM
   - Check that `publishConfig` in `package.json` is correct:
     ```json
     {
       "publishConfig": {
         "access": "public"
       }
     }
     ```

---

## Testing Before Publishing

### Dry Run Test

Test the workflow without actually publishing:

1. Go to **Actions** → **Publish Alpha to NPM**
2. Click "Run workflow"
3. Enter version: `0.1.0-alpha.99`
4. Check "Dry run" option
5. Click "Run workflow"

This will build and verify packages without publishing.

### Local Package Test

Test packages locally before publishing:

```bash
# Build the package
cd packages/nvoos-storage
npm run build

# Create a tarball
npm pack

# This creates: nvdigitalsolutions-nvoos-storage-X.Y.Z-alpha.N.tgz

# Install in a test project
cd /path/to/test-project
npm install /path/to/mcp-ai-wpoos/packages/nvoos-storage/*.tgz

# Test the package
npm list @nvdigitalsolutions/nvoos-storage
```

### Verify Package Contents

Before publishing, verify what will be included:

```bash
cd packages/nvoos-storage
npm pack --dry-run

# Shows files that will be included in the package
```

Check that:
- ✅ `dist/` directory is included
- ✅ `README.md` is included
- ✅ `package.json` is correct
- ❌ Source files are not included (unless intended)
- ❌ Test files are not included
- ❌ Build artifacts are excluded

---

## Workflow-Specific Issues

### Tags not triggering workflow

**Cause:** Tag format doesn't match the trigger pattern

**Solution:**
- Tags must match: `v*.*.*-alpha.*`
- ✅ Valid: `v0.1.0-alpha.1`, `v1.2.3-alpha.10`
- ❌ Invalid: `0.1.0-alpha.1` (missing 'v'), `v0.1.0` (not alpha)

### Workflow not appearing in Actions tab

**Cause:** Workflow file might have syntax errors

**Solution:**
1. Check YAML syntax: https://yaml-online-parser.appspot.com/
2. Validate workflow file structure
3. Ensure workflow file is in `.github/workflows/`
4. Push to main branch to register the workflow

---

## Still Having Issues?

### Check Workflow Logs

1. Go to **Actions** tab on GitHub
2. Click on the failed workflow run
3. Expand the failed step to see detailed logs
4. Look for specific error messages

### Get Help

- **Repository Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **NPM Documentation:** https://docs.npmjs.com/creating-and-viewing-access-tokens
- **GitHub Actions:** https://docs.github.com/en/actions
- **Email Support:** hello@nvdigitalsolutions.com

### Common Quick Fixes Checklist

- [ ] Using "Automation" token type (not "Publish")
- [ ] `NPM_TOKEN` secret is correctly set in GitHub
- [ ] Organization exists and you're a member
- [ ] Package names are correct in `package.json`
- [ ] Build succeeds locally
- [ ] Node.js version compatibility
- [ ] Tag format matches `v*.*.*-alpha.*`
- [ ] No typos in package names or organization

---

## Additional Resources

- [NPM Token Types Documentation](https://docs.npmjs.com/creating-and-viewing-access-tokens)
- [GitHub Actions Secrets](https://docs.github.com/en/actions/security-guides/encrypted-secrets)
- [NPM Publishing Guide](../NPM_PUBLISHING_GUIDE.md)
- [Alpha Publishing Quick Reference](../ALPHA_PUBLISHING.md)
- [Detailed Alpha Publishing Guide](./npm-alpha-publishing.md)
