#!/usr/bin/env node
/**
 * Take a light-mode screenshot of vandragt.com for the project README.
 *
 * Usage:
 *   node scripts/screenshot-vandragt.mjs [outpath]
 *
 * Defaults to docs/demo-vandragt.webp. When the output path ends in .webp the
 * screenshot is captured as a temporary PNG and re-encoded to WebP via PHP's GD
 * extension (Playwright itself only writes PNG/JPEG), matching how Lamb stores
 * uploads.
 */

import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { unlinkSync } from 'node:fs';

const URL = process.env.SCREENSHOT_URL ?? 'https://vandragt.com/';
const OUT = process.argv[2] ?? 'docs/demo-vandragt.webp';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
  ?? (await chromium.executablePath().catch(() => null));

const browser = await chromium.launch({ executablePath });
const context = await browser.newContext({
  viewport: { width: 1280, height: 900 },
  deviceScaleFactor: 2,
  colorScheme: 'light',
});
const page = await context.newPage();
await page.goto(URL, { waitUntil: 'networkidle' });
// Give web fonts a moment to settle.
await page.waitForTimeout(800);

const toWebp = OUT.toLowerCase().endsWith('.webp');
const shotPath = toWebp ? `${OUT}.tmp.png` : OUT;
await page.screenshot({ path: shotPath, fullPage: false });
await browser.close();

if (toWebp) {
  // Re-encode PNG → WebP (quality 82, preserve alpha) using GD, then drop the temp.
  execFileSync('php', ['-r', [
    '$i = imagecreatefrompng($argv[1]);',
    'imagepalettetotruecolor($i);',
    'imagealphablending($i, false);',
    'imagesavealpha($i, true);',
    'imagewebp($i, $argv[2], 82);',
  ].join(''), shotPath, OUT]);
  unlinkSync(shotPath);
}
console.log(`Saved ${OUT}`);
