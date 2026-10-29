# Third-Party Notices

## Funiq PWA bundle (`dist/`)

The Funiq PWA storefront is a purchased third-party item licensed through
Envato Market. It is confined to the private repositories
(`nvoos-funiq-pwa` source, `nvoos-funiq-velocity` mirror) and is injected
into the mirror at CI time — never committed to the public monorepo. Its
license proof (purchase code) lives privately with the PWA repository.
This file is the monorepo-side boundary marker required by Proposal 085
§3.4: the public tree references the bundle only by name and never carries
its bytes.

## NV-authored server files

The server/proxy files in this example (server.js, scripts/serve.mjs,
scripts/lib/proxy-headers.mjs) are NV-authored and proprietary — see the
`LICENSE` file in this directory.

## Runtime packages

No third-party npm dependencies are shipped — `package.json` declares zero
dependencies. The server uses only the Node.js standard library
(http, https, fs, path, url, zlib). The PWA's own client-side packages
(React etc.) are bundled inside the licensed `dist/` and covered by the
PWA's upstream notices.
