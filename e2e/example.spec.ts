import { test, expect } from '@playwright/test';

test('homepage has expected structure', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('nav')).toBeVisible();
    await expect(page.locator('h1')).toBeVisible();
    await expect(page.locator('footer small')).toContainText('Powered by');
});

test('login link is visible when logged out', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Login' })).toBeVisible();
});

test('search form submits and navigates to results', async ({ page }) => {
    await page.goto('/');
    await page.locator('input[name="s"]').fill('test');
    await page.locator('form.form-search input[type="submit"]').click();
    await expect(page).toHaveURL(/\/search/);
});

test('atom feed is accessible', async ({ page }) => {
    const response = await page.request.get('/feed');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/atom+xml');
});

test('login page has password form', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('input[type="password"][name="password"]')).toBeVisible();
    await expect(page.locator('input[type="submit"]')).toBeVisible();
});

test('wrong password is rejected', async ({ page }) => {
    await page.goto('/login');
    await page.locator('input[name="password"]').fill('wrongpassword');
    await page.locator('input[type="submit"]').click();
    // Bad credentials redirect to home but user remains logged out
    await expect(page.getByRole('link', { name: 'Login' })).toBeVisible();
});

test('non-existent page returns 404', async ({ page }) => {
    const response = await page.goto('/this-page-does-not-exist-xyz123');
    expect(response?.status()).toBe(404);
});
