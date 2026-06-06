#!/usr/bin/env node
/**
 * Take a light-mode screenshot of vandragt.com for the project README.
 *
 * Usage:
 *   node scripts/screenshot-vandragt.mjs [outpath]
 *
 * Defaults to docs/demo-vandragt.png.
 */

import { chromium } from '@playwright/test';

const URL = process.env.SCREENSHOT_URL ?? 'https://vandragt.com/';
const OUT = process.argv[2] ?? 'docs/demo-vandragt.png';

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
await page.screenshot({ path: OUT, fullPage: false });
console.log(`Saved ${OUT}`);
await browser.close();
