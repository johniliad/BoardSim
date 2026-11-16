#!/usr/bin/env node
/**
 * Subresource integrity: pin every emitted script and stylesheet.
 *
 * Every document this origin serves must reference its same-origin scripts and
 * stylesheets with an integrity attribute whose digest matches the bytes on
 * disk — ZAP flags any script or stylesheet that ships unpinned ("Sub
 * Resource Integrity Attribute Missing"), and a stale pin is worse than none,
 * because the browser refuses the asset at load time. This script is the
 * single owner of those pins:
 *
 *   - the built SPA shell (public/app/index.html), whose Vite-emitted asset
 *     names change on every build — which is exactly why the pin cannot be
 *     hand-written once;
 *   - the five branded error pages, which all style themselves from
 *     public/errors/error.css (a tracked, hand-edited file).
 *
 * The build runs it right after vite (`npm run build`), and bin/verify-meta.php
 * re-hashes the built shell independently, so a pin that drifts from its asset
 * fails the front-end job instead of a visitor's browser.
 *
 * Usage:
 *   node scripts/sri.mjs            # add or refresh every pin, then report
 *   node scripts/sri.mjs --check    # verify only; exit 1 on anything unpinned
 */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const checkOnly = process.argv.includes('--check');
const ALGORITHM = 'sha384';

// The documents whose subresources must be pinned. URL paths are
// document-root paths, and every asset this origin serves lives under
// public/, which is what the document root is built from.
const DOCUMENTS = [
  'public/app/index.html',
  'public/errors/400.html',
  'public/errors/403.html',
  'public/errors/404.html',
  'public/errors/500.html',
  'public/errors/503.html',
];

// One pass per element kind the integrity spec covers: scripts, stylesheets
// and module preloads (a preload is a script fetch, so it is pinned too).
const TAG_PATTERNS = [
  /<script\b[^>]*?\bsrc="[^"]*"[^>]*>/gi,
  /<link\b[^>]*?\brel="stylesheet"[^>]*>/gi,
  /<link\b[^>]*?\brel="modulepreload"[^>]*>/gi,
];

const digestOf = (file) =>
  `${ALGORITHM}-${createHash(ALGORITHM).update(readFileSync(file)).digest('base64')}`;

const escapeRegex = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Return the tag with a correct integrity attribute, or throw with a message
 * that names the document and the reference.
 */
function pinTag(tag) {
  const url = tag.match(/\b(?:src|href)="([^"]+)"/)?.[1];

  if (url === undefined) {
    throw new Error('tag carries no src/href');
  }

  if (!url.startsWith('/')) {
    throw new Error(`not same-origin and must not ship unpinned: ${url}`);
  }

  const file = join(root, 'public', url.slice(1));

  if (!existsSync(file)) {
    throw new Error(`referenced file is not on disk: ${url}`);
  }

  const digest = digestOf(file);
  const current = tag.match(/integrity="([^"]*)"/)?.[1];

  if (current === digest) {
    return { tag, url, digest, changed: false };
  }

  if (current !== undefined) {
    return { tag: tag.replace(/integrity="[^"]*"/, `integrity="${digest}"`), url, digest, changed: true };
  }

  const anchored = tag.match(new RegExp(`\\b(?:src|href)="${escapeRegex(url)}"`));

  if (anchored === null) {
    throw new Error(`no src/href attribute to anchor the pin for ${url}`);
  }

  return {
    tag: tag.replace(new RegExp(`\\b(?:src|href)="${escapeRegex(url)}"`), `${anchored[0]} integrity="${digest}"`),
    url,
    digest,
    changed: true,
  };
}

const problems = [];
let pinned = 0;
let stale = 0;

for (const relative of DOCUMENTS) {
  const path = join(root, relative);

  if (!existsSync(path)) {
    problems.push(
      `${relative} does not exist${relative.includes('app/index.html') ? ' — run cd web && npm run build first' : ''}`
    );
    continue;
  }

  const original = readFileSync(path, 'utf8');
  let html = original;

  for (const pattern of TAG_PATTERNS) {
    html = html.replace(pattern, (tag) => {
      try {
        const result = pinTag(tag);
        pinned += 1;

        if (result.changed) {
          stale += 1;
          console.log(`  ${checkOnly ? 'STALE' : 'pin  '}  ${relative}: ${result.url} -> ${result.digest}`);
        }

        return result.tag;
      } catch (error) {
        problems.push(`${relative}: ${error.message}`);
        return tag;
      }
    });
  }

  if (!checkOnly && html !== original) {
    writeFileSync(path, html);
  }
}

if (problems.length > 0) {
  for (const problem of problems) {
    console.error(`  error  ${problem}`);
  }

  console.error(`\n${problems.length} problem(s): subresource integrity is not complete.`);
  process.exit(1);
}

console.log(`\n${pinned} subresource(s) pinned with ${ALGORITHM}; ${stale} pin(s) ${checkOnly ? 'stale' : 'written'}.`);

if (checkOnly && stale > 0) {
  process.exit(1);
}