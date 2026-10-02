import { test as setup, expect } from '@playwright/test';

// Log in once per target through the real login form, as the default admin the runner configures
// in the host's .env (SS_DEFAULT_ADMIN_USERNAME/PASSWORD = admin/admin), and save the session.
// Every spec of that target starts from this state, so no spec depends on a dev-only autologin.
setup('log in to the CMS', async ({ page }, testInfo) => {
    const target = testInfo.project.name.replace(/-login$/, '');

    await page.goto('/Security/login?BackURL=/admin');
    await page.locator('input[name="Email"]').fill('admin');
    await page.locator('input[name="Password"]').fill('admin');
    await page.locator('[name="action_doLogin"]').click();

    // Landing in the CMS shell is the proof the login worked.
    await expect(page).toHaveURL(/\/admin/);
    await expect(page.locator('.cms-menu')).toBeVisible();

    await page.context().storageState({ path: `.auth/${target}.json` });
});
