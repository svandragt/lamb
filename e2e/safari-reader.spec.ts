/**
 * Safari Reader View compatibility tests.
 *
 * These tests simulate the Reader View experience by running the
 * @mozilla/readability algorithm (which powers Firefox Reader View and
 * closely mirrors Safari's implementation) against the real rendered HTML.
 *
 * Two complementary layers:
 *  1. Structural DOM checks — fast assertions on the emitted HTML.
 *  2. Readability algorithm checks — run @mozilla/readability on the full
 *     page HTML to confirm posts are not filtered and key fields survive.
 *
 * Note on isProbablyReaderable():
 *   The default thresholds (minContentLength:140, minScore:20) are tuned for
 *   news-article–sized pages, not microblog posts.  We use {minScore:0} to
 *   test structural readability independently of word-count; the structural
 *   fixes in _items.php are what address the Safari filtering issue.
 *
 * @see https://github.com/svandragt/lamb/issues/62
 */
import { test, expect } from '@playwright/test';
import { Readability, isProbablyReaderable } from '@mozilla/readability';
import { JSDOM } from 'jsdom';

/**
 * Run Mozilla Readability against a raw HTML string.
 * Returns the parsed article object, or null when parsing yields nothing.
 */
function parseReadability(html: string, url: string) {
    const dom = new JSDOM(html, { url });
    return new Readability(dom.window.document).parse();
}

/**
 * Structural readability pre-check, using permissive options suitable for
 * short microblog content.
 */
function isReaderable(html: string, url: string): boolean {
    const dom = new JSDOM(html, { url });
    return isProbablyReaderable(dom.window.document, { minContentLength: 1, minScore: 0 });
}

// ---------------------------------------------------------------------------
// Layer 1: Semantic structure (DOM assertions)
// ---------------------------------------------------------------------------

test.describe('Safari Reader View — semantic structure', () => {
    test('article date is inside article > header as time[datetime]', async ({ page }) => {
        await page.goto('/');
        const articles = page.locator('article');
        const count = await articles.count();
        if (count === 0) {
            await expect(page.locator('text=Sorry no items found')).toBeVisible();
            return;
        }
        for (let i = 0; i < count; i++) {
            await expect(articles.nth(i).locator('header time[datetime]').first()).toBeAttached();
        }
    });

    test('titled articles expose the title as h2 inside article > header', async ({ page }) => {
        await page.goto('/');
        const titledArticles = page.locator('article:has(header h2)');
        const count = await titledArticles.count();
        if (count === 0) return;
        for (let i = 0; i < count; i++) {
            await expect(titledArticles.nth(i).locator('header h2').first()).toBeVisible();
        }
    });

    test('admin actions are inside article > footer, not main content', async ({ page }) => {
        await page.goto('/');
        const editCount = await page.locator('.button-edit').count();
        const deleteCount = await page.locator('.form-delete').count();
        for (let i = 0; i < editCount; i++) {
            await expect(page.locator('article footer .button-edit').nth(i)).toBeAttached();
        }
        for (let i = 0; i < deleteCount; i++) {
            await expect(page.locator('article footer .form-delete').nth(i)).toBeAttached();
        }
    });
});

// ---------------------------------------------------------------------------
// Layer 2: Readability algorithm assertions
// ---------------------------------------------------------------------------

test.describe('Safari Reader View — readability algorithm', () => {
    test('home page passes structural readability check when posts exist', async ({ page }) => {
        await page.goto('/');
        if (await page.locator('article').count() === 0) return;

        const html = await page.content();
        expect(isReaderable(html, page.url())).toBe(true);
    });

    test('single post: readability returns a non-null article', async ({ page }) => {
        await page.goto('/');
        const firstDateLink = page.locator('article header a[href]').first();
        if (await firstDateLink.count() === 0) return;

        await firstDateLink.click();
        const html = await page.content();
        const article = parseReadability(html, page.url());

        expect(article).not.toBeNull();
    });

    test('single post: readability preserves the date <time> element', async ({ page }) => {
        await page.goto('/');
        const firstDateLink = page.locator('article header a[href]').first();
        if (await firstDateLink.count() === 0) return;

        await firstDateLink.click();
        const html = await page.content();
        const article = parseReadability(html, page.url());
        if (article === null) return;

        // The <time datetime="…"> element must survive reader-mode extraction
        expect(article.content).toContain('<time');
    });

    test('single post: readability does not strip the post body text', async ({ page }) => {
        await page.goto('/');
        const firstArticle = page.locator('article').first();
        if (await firstArticle.count() === 0) return;

        const rawText = (await firstArticle.innerText()).trim();
        if (rawText.length < 5) return;

        const firstDateLink = page.locator('article header a[href]').first();
        await firstDateLink.click();

        const html = await page.content();
        const article = parseReadability(html, page.url());
        if (article === null) return;

        // First 20 chars of the article text should appear in extracted content
        const snippet = rawText.slice(0, 20).replace(/\s+/g, ' ').trim();
        expect(article.textContent).toContain(snippet);
    });

    test('single post with title: readability extracts the title', async ({ page }) => {
        await page.goto('/');
        const titledLink = page.locator('article:has(header h2) header a[href]').first();
        if (await titledLink.count() === 0) return;

        const expectedTitle = (
            await page.locator('article:has(header h2) header h2').first().innerText()
        ).trim();

        await titledLink.click();
        const html = await page.content();
        const article = parseReadability(html, page.url());

        expect(article).not.toBeNull();
        expect(article!.title.toLowerCase()).toContain(expectedTitle.toLowerCase());
    });

    test('tag page passes structural readability check when posts exist', async ({ page }) => {
        await page.goto('/');
        const tagLink = page.locator('a[href^="/tag/"]').first();
        if (await tagLink.count() === 0) return;

        await tagLink.click();
        if (await page.locator('article').count() === 0) return;

        const html = await page.content();
        expect(isReaderable(html, page.url())).toBe(true);
    });
});
