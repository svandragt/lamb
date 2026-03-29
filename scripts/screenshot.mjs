#!/usr/bin/env node
/**
 * Take before/after nav screenshots at mobile + desktop viewports.
 *
 * Usage:
 *   node scripts/screenshot.mjs [path] [outdir]
 *
 * Defaults:
 *   path   = /          (relative to baseURL)
 *   outdir = /tmp/shots
 *
 * Requires the dev server to be running:
 *   composer serve
 *
 * Before/after comparison:
 *   git stash
 *   node scripts/screenshot.mjs / /tmp/shots/before
 *   git stash pop
 *   node scripts/screenshot.mjs / /tmp/shots/after
 */

import { chromium } from '@playwright/test';
import { mkdir } from 'fs/promises';
import { join } from 'path';

const BASE_URL = process.env.SITE_URL ?? 'http://localhost:8747';
const urlPath  = process.argv[2] ?? '/';
const outDir   = process.argv[3] ?? '/tmp/shots';

const viewports = [
  { label: 'mobile',   width: 390,  height: 844 },
  { label: 'tablet',   width: 768,  height: 1024 },
  { label: 'desktop',  width: 1280, height: 800 },
];

await mkdir(outDir, { recursive: true });

// Use PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH or fall back to known cached path.
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
  ?? (await chromium.executablePath().catch(() => null));

const browser = await chromium.launch({ executablePath });

for (const { label, width, height } of viewports) {
  const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 2 });
  await page.goto(BASE_URL + urlPath, { waitUntil: 'networkidle' });
  const file = join(outDir, `${label}.png`);
  await page.screenshot({ path: file, fullPage: false });
  console.log(`  ${label.padEnd(8)} → ${file}`);
  await page.close();
}

await browser.close();
