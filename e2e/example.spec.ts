import { test, expect } from '@playwright/test';

test('has title', async ({ page }) => {
  await page.goto('http://localhost:8747');

  // Expect a title "to contain" a substring.
  await expect(page).toHaveTitle(/Sander's thoughts/);
});

test('navigation works', async ({page}) => {
  await page.goto('http://localhost:8747');

  // Click a navigation link.
  await page.getByRole('link', {name: 'Test', exact: true}).click();

  // Expects page to have a heading.
  await expect(page.getByRole('heading', {name: 'about us'})).toBeVisible();
});
