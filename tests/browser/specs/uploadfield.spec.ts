import { test, expect, backgroundUrl, fetchSvg, svgUpload } from './support';

// An SVG uploaded through a has_one Image relation's UploadField: accepted, previewed, and stored
// as an SVGImage after the record is saved (SVGImageExtension corrects the relation's class).

test('an SVG uploaded into an Image relation is previewed and stored as SVGImage', async ({ page }) => {
    await page.goto('/admin/svgb');
    const grid = page.locator('#Form_EditForm_records');
    await grid.locator('tr.ss-gridfield-item', { hasText: 'Upload target' }).click();
    const form = page.locator('#Form_ItemEditForm');
    await expect(form.locator('#Form_ItemEditForm_Image_Holder')).toBeVisible();

    // A has_one field takes one file: an earlier run (or --repeat-each) left one, so remove it
    // first, as an editor replacing the image would. The field then offers its dropzone again.
    const existing = form.locator('.uploadfield-item');
    if (await existing.count()) {
        await existing.locator('.uploadfield-item__remove-btn').click();
        await expect(existing).toHaveCount(0);
    }

    const { file, title } = svgUpload('svgb-clean', 'svgb-rel');
    const uploaded = page.waitForResponse((r) => r.request().method() === 'POST' && /\/field\/Image\/upload/.test(r.url()));
    await page.locator('input.dz-hidden-input').setInputFiles(file);
    expect((await uploaded).status(), 'upload accepted').toBe(200);

    // The field lists it as an image, with an SVG thumbnail that loads.
    const item = form.locator('.uploadfield-item', { hasText: title });
    await expect(item).toHaveClass(/uploadfield-item--image/);
    await fetchSvg(page, await backgroundUrl(item.locator('.uploadfield-item__thumbnail')));

    // Save (an AJAX form submission in the CMS).
    const saved = page.waitForResponse((r) => r.request().method() === 'POST' && /\/ItemEditForm$/.test(r.url()));
    await form.locator('[name="action_doSave"]').click();
    const saveResponse = await saved;
    expect(saveResponse.status(), 'save accepted').toBe(200);
    expect(['xhr', 'fetch']).toContain(saveResponse.request().resourceType());

    // The list says which class the stored file has: the relation's Image became an SVGImage.
    await page.goto('/admin/svgb');
    const row = grid.locator('tr.ss-gridfield-item', { hasText: 'Upload target' });
    await expect(row.locator('td.col-ImageClass')).toHaveText('Restruct\\Silverstripe\\SVG\\SVGImage');

    // Reopened, the field still previews it (now from a resized SVG variant).
    await row.click();
    const again = page.locator('#Form_ItemEditForm .uploadfield-item', { hasText: title });
    await expect(again).toHaveClass(/uploadfield-item--image/);
    const variant = await backgroundUrl(again.locator('.uploadfield-item__thumbnail'));
    expect(variant, 'the preview is an SVG').toMatch(/\.svg(\?|$)/);
    await fetchSvg(page, variant);
});
