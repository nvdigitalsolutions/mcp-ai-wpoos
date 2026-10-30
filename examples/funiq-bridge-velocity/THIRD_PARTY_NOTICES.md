# Third-Party Notices

## Funiq CMS (Payload 3 admin, licensed item)

The "Funiq — Furniture Store App with Payload CMS" item (React Native app +
Payload CMS) is a purchased third-party item licensed through Envato
Market. Its source is confined to the private repository
`nvdigitalsolutions/nvoos-funiq-pwa`; its build output is confined to the
private mirror `nvdigitalsolutions/nvoos-funiq-velocity`, assembled at CI
time by `sync-nvoos-funiq-velocity.yml`. Its license proof (purchase code)
lives privately in `nvoos-funiq-pwa/LICENSE.txt` and ships inside the
mirror only. This file is the monorepo-side boundary marker required by
Proposal 085 §3.4: the public tree references the item only by name and
never carries its bytes.

## NV-authored deploy files

The deploy recipe and smoke harness in this example
(scripts/enable-standalone.mjs, scripts/assemble-mirror.mjs,
scripts/smoke.mjs, README.md, package.json, .env.example) are NV-authored
and proprietary — see the `LICENSE` file in this directory.

## Runtime packages (installed on Velocity from the vendor manifest)

The mirror carries the vendor's `package.json` /
`package-lock.json` / `.npmrc` verbatim; Velocity installs the declared
runtime packages (Payload 3.64, Next.js 15, React 19, sharp, the
`@payloadcms/*` suite, PostgreSQL adapter, etc.). Those packages are
third-party open-source software under their own licenses (MIT/Apache-2.0
per their manifests) — the mirror does not vendor their license texts;
see each package's repository for notices. Nothing in this monorepo
example declares or installs those dependencies.
