import { test, expect, backgroundUrl, expectSanitized, fetchSvg, naturalSize, svgFromUrl, svgUpload } from './support';

// Uploading an SVG in the Files section (AssetAdmin): it is accepted, stored as an SVG image with
// its dimensions read from the file, previewed in the gallery and the editor, and sanitized.

test('an uploaded SVG becomes a previewed, sanitized SVG image', async ({ page }) => {
    await page.goto('/admin/assets');
    await expect(page.locator('#upload-button')).toBeVisible();

    const { file, title } = svgUpload('svgb-dirty', 'svgb-asset');
    const created = page.waitForResponse((r) => r.request().method() === 'POST' && /\/admin\/assets\/api\/createFile/.test(r.url()));
    // The gallery's dropzone input (the Upload button opens it).
    await page.locator('input.dz-hidden-input').setInputFiles(file);

    // The upload is accepted and classified as an SVG image, with the size from its viewBox.
    const response = await created;
    expect(response.status(), 'upload accepted').toBe(200);
    const [info] = await response.json();
    expect(info).toMatchObject({ category: 'image', extension: 'svg', width: 120, height: 80 });
    expect(String(info.type)).toMatch(/^SVG image$/i);

    // The gallery shows it as an image tile with an SVG thumbnail, not a generic file icon.
    const tile = page.locator('.gallery-item').filter({ hasText: title });
    await expect(tile).toHaveCount(1);
    await expect(tile).toHaveClass(/gallery-item--image/);
    // While uploading, the tile shows Dropzone's own client-side preview (a PNG data URI drawn
    // from the local file); the server's thumbnail replaces it once the upload is stored.
    const thumbEl = tile.locator('.gallery-item__thumbnail');
    await expect
        .poll(() => backgroundUrl(thumbEl), { message: "the tile's thumbnail from the server" })
        .toMatch(/^(data:image\/svg\+xml|https?:|\/)/);
    const thumb = await backgroundUrl(thumbEl);
    // Whatever form the thumbnail takes (data URI or URL), it is the sanitized drawing.
    expectSanitized(await svgFromUrl(page, thumb));

    // The editor: dimensions in the specs line, and the preview image actually renders.
    await tile.click();
    const editor = page.locator('#Form_fileEditForm');
    await expect(editor.locator('.editor__specs')).toContainText('120x80px');
    const preview = editor.locator('img.editor__thumbnail');
    expect(await naturalSize(preview)).toEqual({ width: 120, height: 80 });

    // The stored file itself (protected draft, fetched with the admin session) is sanitized.
    const src = await preview.getAttribute('src');
    expectSanitized(await fetchSvg(page, src!));
});
