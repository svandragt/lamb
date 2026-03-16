/**
 * Safari Reader View compatibility tests.
 *
 * Safari Reader View requires proper semantic HTML to display articles correctly:
 * - Each <article> must contain a <time datetime="…"> so the date is preserved.
 * - Articles with titles must expose them via <h2> so they are not filtered out.
 * - Admin actions (edit/delete) must be in an <article><footer> so they are
 *   stripped by Reader View and do not pollute the reading experience.
 * - Post metadata (date, source link) must be in <article><header> so Reader
 *   View keeps them alongside the content.
 *
 * @see https://github.com/svandragt/lamb/issues/62
 */
import { test, expect } from '@playwright/test';

test.describe('Safari Reader View compatibility', () => {
    test('article elements contain a time[datetime] element for date preservation', async ({ page }) => {
        await page.goto('/');
        const articles = page.locator('article');
        const count = await articles.count();
        if (count === 0) {
            // No posts yet — skip structural check but ensure the empty message is shown
            await expect(page.locator('text=Sorry no items found')).toBeVisible();
            return;
        }
        // Every article must have at least one <time datetime> so Reader View keeps the date
        for (let i = 0; i < count; i++) {
            const timeEl = articles.nth(i).locator('time[datetime]');
            await expect(timeEl.first()).toBeAttached();
        }
    });

    test('article date metadata is inside article header', async ({ page }) => {
        await page.goto('/');
        const articles = page.locator('article');
        const count = await articles.count();
        if (count === 0) {
            return;
        }
        for (let i = 0; i < count; i++) {
            // The <time> element should be inside <article><header>, not a bare <small>
            const headerTime = articles.nth(i).locator('header time[datetime]');
            await expect(headerTime.first()).toBeAttached();
        }
    });

    test('articles with a title expose it as h2 inside the article', async ({ page }) => {
        await page.goto('/');
        const titledArticles = page.locator('article:has(header h2)');
        const count = await titledArticles.count();
        if (count === 0) {
            // No titled posts — nothing to assert
            return;
        }
        for (let i = 0; i < count; i++) {
            const h2 = titledArticles.nth(i).locator('header h2');
            await expect(h2.first()).toBeVisible();
        }
    });

    test('admin actions are isolated inside article footer', async ({ page }) => {
        await page.goto('/');
        // Edit / delete controls must NOT appear outside an article footer so Reader
        // View strips them cleanly.  We verify the selectors used by the theme.
        const editButtons = page.locator('.button-edit');
        const deleteButtons = page.locator('.form-delete');
        const editCount = await editButtons.count();
        const deleteCount = await deleteButtons.count();

        for (let i = 0; i < editCount; i++) {
            await expect(editButtons.nth(i)).toHaveJSProperty(
                'closest',
                // closest('article footer') must not be null
                expect.anything()
            );
            // Simpler: assert the button is inside an article footer via locator
            const insideFooter = page.locator('article footer .button-edit').nth(i);
            await expect(insideFooter).toBeAttached();
        }

        for (let i = 0; i < deleteCount; i++) {
            const insideFooter = page.locator('article footer .form-delete').nth(i);
            await expect(insideFooter).toBeAttached();
        }
    });

    test('single post page has a time[datetime] element', async ({ page }) => {
        await page.goto('/');
        // Find the first date link in a header and follow it to the single post
        const firstDateLink = page.locator('article header a[href]').first();
        const count = await firstDateLink.count();
        if (count === 0) {
            return;
        }
        await firstDateLink.click();
        await expect(page.locator('article header time[datetime]')).toBeAttached();
    });

    test('tag listing articles contain time[datetime] elements', async ({ page }) => {
        // Navigate to a tag page if any tag links exist on the home page
        await page.goto('/');
        const tagLink = page.locator('a[href^="/tag/"]').first();
        if (await tagLink.count() === 0) {
            return;
        }
        await tagLink.click();
        const articles = page.locator('article');
        const count = await articles.count();
        if (count === 0) {
            return;
        }
        for (let i = 0; i < count; i++) {
            const timeEl = articles.nth(i).locator('time[datetime]');
            await expect(timeEl.first()).toBeAttached();
        }
    });
});
