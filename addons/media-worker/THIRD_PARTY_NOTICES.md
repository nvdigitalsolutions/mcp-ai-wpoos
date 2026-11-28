# Third-party notices — design-media-worker

This service redistributes the packages listed in `package.json`. Each package
carries its own licence, and the full text is in `node_modules/<package>/LICENSE`
in any installed tree. This file records the notices that must travel with a
distribution and are not already satisfied by that directory.

## Notice required

### nerdamer

- Version: 2.1.x (`^2.1.0`)
- Licence: **Apache License 2.0**
- Upstream: <https://github.com/jiggzson/nerdamer>
- Copyright: Martin Donk and contributors
- Used by: `src/routes/math.js` (`POST /api/math/integral`) via
  `src/utils/integrate.js`

Apache-2.0 requires that this notice is preserved and that a copy of the
licence accompanies any distribution of the service. It does not require this
service, or the product that ships it, to be licensed under Apache-2.0.

## Everything else

The remaining runtime dependencies are distributed under permissive licences
(MIT, ISC or BSD) declared in their own `package.json`, and their notices are
carried by their packages. No dependency in this service is copyleft.
