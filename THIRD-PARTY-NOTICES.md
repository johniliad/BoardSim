# Third-party notices

BoardSim itself is licensed under the Apache License 2.0 (see `LICENSE`). This file
records the third-party components its build depends on, because a clean
attribution story is part of publishing open source.

Two facts shape what belongs here:

1. **The PHP engine has no third-party runtime dependencies.** `composer.json`
   declares only `php >= 8.2` and `ext-json`. Everything under `src/` is original.
2. **No third-party code is committed to this repository.** The front-end declares
   dependencies in `web/package.json`; `node_modules/` and the built bundle
   (`public/app/`) are gitignored and are resolved or produced only at build time.

## 1. Components distributed in the web bundle

The compiled SPA embeds the React runtime. These are MIT-licensed and are included
with their copyright notice preserved:

| Component | Version | License | Copyright |
| --- | --- | --- | --- |
| react | 18.3.1 | MIT | Meta Platforms, Inc. and affiliates |
| react-dom | 18.3.1 | MIT | Meta Platforms, Inc. and affiliates |
| scheduler | 0.23.2 | MIT | Meta Platforms, Inc. and affiliates |
| loose-envify | 1.4.0 | MIT | Andres Suarez |
| js-tokens | 4.0.0 | MIT | Simon Lydell |

`loose-envify` and `js-tokens` are the CommonJS `process.env` indirection React's
package metadata pulls in; depending on which entry point Rollup resolves, one or
both may be tree-shaken out of the bundle. They are listed because the lockfile
resolves them as production dependencies, and an attribution list that is
sometimes short is worse than one that is occasionally generous.

React is © Meta Platforms, Inc. and affiliates, used under the MIT License. The
standard MIT terms are reproduced below for the distributed React runtime:

```
MIT License

Copyright (c) Meta Platforms, Inc. and affiliates.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```

## 2. Build and development tooling (not distributed)

These are resolved by npm during development and the build. They never appear in
`public/app/` or in any release artifact, so they are listed for transparency
rather than as redistributed code. Notable non-MIT licenses are called out.

| Package | Version | License |
| --- | --- | --- |
| vite | 6.4.6 | MIT |
| @vitejs/plugin-react | 4.7.0 | MIT |
| typescript | 5.9.3 | Apache-2.0 |
| esbuild | 0.25.12 | MIT |
| rollup | 4.68.2 | MIT |
| @babel/core and other @babel/* | 7.29.x | MIT |
| postcss | 8.5.32 | MIT |
| @types/react, @types/react-dom | 18.3.x | MIT |
| caniuse-lite | 1.0.x | CC-BY-4.0 (browser support data) |
| source-map-js | 1.2.2 | BSD-3-Clause |
| electron-to-chromium | 1.5.x | ISC |
| semver, lru-cache, yallist, ms, picocolors | — | ISC |
| baseline-browser-mapping | 2.11.37 | Apache-2.0 |

## 3. Recovering the full list

The authoritative, complete list lives in `web/package-lock.json`, which records
every resolved package and its `license` field:

```bash
cd web && npm ls --all --json
```

If dependencies change, regenerate this summary from the lockfile rather than
editing it by hand.

## 4. Not third-party

- The fourteen scenarios and all `content/` overlays are authored work for this
  project, marked `SIMULATION_ASSUMPTION`.
- The legal citations inside the regulatory register are hyperlinks to official
  publications (EUR-Lex, NIST, ENISA, Greek authorities), not redistributed text.
- No font files, icon packs, or CSS frameworks are used; the SPA stylesheet is
  original.

A `NOTICE` file is deliberately **not** included: Apache-2.0 requires one only
when redistributing code that already carries a `NOTICE`, and none of the
third-party components here (all MIT/ISC/BSD/CC or build-only Apache-2.0 tooling)
ships one. If a future dependency does carry a `NOTICE`, add it alongside this file.

## 5. Where a browser receives these notices

`LICENSE` and this file travel with the source, which a browser never sees. The
origin therefore also serves `public/licences.txt` at `/licences.txt`: the same
attributions and the same MIT text, in plain text, with the Apache-2.0 statement
for BoardSim itself. `bin/build-deploy.php` copies it into the document root,
`public/.htaccess` states its type, and the site footer and every error page link
to it.

The bundled JavaScript carries its own copy as well: Vite preserves legal
comments, so the served `app/assets/index-<hash>.js` still contains
`@license React … Copyright (c) Facebook, Inc. and its affiliates`, which is the
MIT notice travelling with the code it covers.
