import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

// Generates the README screenshots in docs/images/. Not a test suite: run it with
// playwright.screenshots.config.ts (see README.md next to this file). The content comes from the
// browser fixtures: SvgShowcasePage seeds a "Showcase" folder (SVGs and raster images) and the
// /svg-showcase page whose hero image is an SVG.

const IMAGES = path.join(__dirname, '..', '..', '..', 'docs', 'images');

/**
 * Hide what only the test host has: the browser-test fixtures' menu entry ("SVG browser test") and
 * page ("SVG variants"). Presentation only; nothing about the module's own UI is changed.
 */
async function hideFixtureChrome(page: Page): Promise<void> {
    await page.addStyleTag({ content: '.cms-menu li:has(> a[href*="admin/svgb"]) { display: none !important; }' });
    // CSS cannot match on text, so the fixture page's tree node is hidden by its label here.
    await page.evaluate(() => {
        document.querySelectorAll<HTMLElement>('.cms-tree li').forEach((li) => {
            const label = li.querySelector(':scope > a')?.textContent?.trim();
            if (label === 'SVG variants') {
                li.style.display = 'none';
            }
        });
    });
}

/** Wait until every <img> on the page has loaded (CSS background thumbnails are checked separately). */
async function imagesLoaded(page: Page): Promise<void> {
    await expect
        .poll(() => page.evaluate(() => Array.from(document.images).every((i) => i.complete && i.naturalWidth > 0)), {
            message: 'all images loaded',
        })
        .toBe(true);
}

/** The ID of a Files record, looked up through the CMS (IDs differ per host). */
async function folderIdFromGallery(page: Page, title: string): Promise<string> {
    await page.goto('/admin/assets');
    const tile = page.locator('.gallery-item--folder').filter({ hasText: title });
    await tile.click();
    await expect(page).toHaveURL(/\/admin\/assets\/show\/\d+/);
    return page.url().match(/\/show\/(\d+)/)![1];
}

test('Files: SVG thumbnails next to raster images, an SVG open in the editor', async ({ page }) => {
    const folderId = await folderIdFromGallery(page, 'Showcase');
    await page.goto(`/admin/assets/show/${folderId}`);
    await hideFixtureChrome(page);
    await expect(page.locator('.gallery-item--image')).toHaveCount(6);

    // Open the landscape SVG in the editor panel: preview, type and the size read from the file.
    await page.locator('.gallery-item', { has: page.getByText('landscape', { exact: true }) }).click();
    const editor = page.locator('#Form_fileEditForm');
    await expect(editor.locator('.editor__specs')).toContainText('640x400px');
    await imagesLoaded(page);
    // Gallery thumbnails are CSS backgrounds: every tile must have one (an empty tile means a
    // raster thumbnail was never generated, see SvgShowcasePage::generateThumbnails()).
    await expect
        .poll(() =>
            page.$$eval('.gallery-item--image .gallery-item__thumbnail', (els) =>
                els.every((e) => getComputedStyle(e).backgroundImage.startsWith('url(')),
            ),
        )
        .toBe(true);
    // No hover overlay on the tile just clicked, no focus highlight in the Title field.
    await page.mouse.move(1270, 790);
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await page.waitForTimeout(500);

    await page.screenshot({ path: path.join(IMAGES, 'files-admin.png') });
});

test('Page editor: an SVG in an Image UploadField', async ({ page }) => {
    await page.goto('/admin/pages');
    await page.locator('.cms-tree a').filter({ hasText: 'SVG showcase' }).first().click();
    const form = page.locator('#Form_EditForm');
    const field = form.locator('#Form_EditForm_HeroImage_Holder');
    await expect(field.locator('.uploadfield-item--image')).toBeVisible();
    await hideFixtureChrome(page);
    await imagesLoaded(page);
    await page.waitForTimeout(500);

    // The top of the window down to just below the upload field: the empty Content field
    // underneath adds nothing.
    const box = (await field.boundingBox())!;
    await page.screenshot({
        path: path.join(IMAGES, 'page-uploadfield.png'),
        clip: { x: 0, y: 0, width: 1280, height: Math.ceil(box.y + box.height + 1) },
    });
});

test('Front end: vector variants of one SVG', async ({ page }) => {
    await page.goto('/svg-showcase');
    await imagesLoaded(page);
    await page.locator('#showcase').screenshot({ path: path.join(IMAGES, 'frontend-variants.png') });
});
