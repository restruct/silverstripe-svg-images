import { test, expect, backgroundUrl } from './support';

// #23: an SVG the sanitizer cannot parse used to be stored as uploaded, and its script ran when
// the file URL was opened. Now such an upload is refused with a message, nothing is stored, and
// every SVG PHP serves carries a Content-Security-Policy with `sandbox`, so even a file stored
// before the fix cannot run script on the site's origin.

// The two reproductions from the issue. Both make the sanitizer give up; both run their script in
// Chromium when served as image/svg+xml without protection.
const PAYLOADS = {
    entity:
        '<!DOCTYPE svg [<!ENTITY x "y">]>\n' +
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>window.svgbPwned = 1</script><text>&x;</text></svg>',
    deep:
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>window.svgbPwned = 1</script>' +
        '<g>'.repeat(300) + '</g>'.repeat(300) + '</svg>',
};

// The refused uploads below answer 403 (Files) and 400 (UploadField) on purpose.
test.use({
    allowedConsoleErrors:
        /Failed to load resource: the server responded with a status of (403 .*\/admin\/assets\/api\/createFile|400 .*\/field\/Image\/upload)\)$/,
});

function upload(kind: keyof typeof PAYLOADS, prefix: string) {
    const name = `${prefix}-${kind}-${Date.now()}${Math.floor(Math.random() * 1000)}.svg`;
    return { name, mimeType: 'image/svg+xml', buffer: Buffer.from(PAYLOADS[kind]) };
}

for (const kind of Object.keys(PAYLOADS) as (keyof typeof PAYLOADS)[]) {
    test(`Files: an unparsable SVG (${kind}) is refused and not stored`, async ({ page }) => {
        await page.goto('/admin/assets');
        await expect(page.locator('#upload-button')).toBeVisible();

        const file = upload(kind, 'svgb-unsafe');
        const created = page.waitForResponse((r) => r.request().method() === 'POST' && /\/admin\/assets\/api\/createFile/.test(r.url()));
        await page.locator('input.dz-hidden-input').setInputFiles(file);

        // Refused by the write (LeftAndMain turns the ValidationException into a 403 whose status
        // text carries the message), never a 200 with a stored file.
        const response = await created;
        expect(response.status(), 'upload refused').toBeGreaterThanOrEqual(400);
        const reason = decodeURIComponent(response.headers()['x-status'] ?? '') + (await response.text());
        expect(reason, 'the uploader is told why').toMatch(kind === 'entity' ? /DOCTYPE/ : /could not be checked/);

        // The gallery shows the failed upload with the reason, and after a reload it is gone.
        const title = file.name.replace(/\.svg$/, '');
        const tile = page.locator('.gallery-item').filter({ hasText: title });
        await expect(tile).toHaveCount(1);
        await expect(tile).toContainText(kind === 'entity' ? /DOCTYPE/ : /could not be checked/);
        await page.reload();
        await expect(page.locator('#upload-button')).toBeVisible();
        await expect(page.locator('.gallery-item').filter({ hasText: title })).toHaveCount(0);
    });
}

test('UploadField: an unparsable SVG is refused with the reason', async ({ page }) => {
    await page.goto('/admin/svgb');
    await page.locator('#Form_EditForm_records tr.ss-gridfield-item', { hasText: 'Upload target' }).click();
    const form = page.locator('#Form_ItemEditForm');
    await expect(form.locator('#Form_ItemEditForm_Image_Holder')).toBeVisible();
    const existing = form.locator('.uploadfield-item');
    if (await existing.count()) {
        await existing.locator('.uploadfield-item__remove-btn').click();
        await expect(existing).toHaveCount(0);
    }

    const file = upload('entity', 'svgb-unsafe-rel');
    const uploaded = page.waitForResponse((r) => r.request().method() === 'POST' && /\/field\/Image\/upload/.test(r.url()));
    await page.locator('input.dz-hidden-input').setInputFiles(file);
    const response = await uploaded;

    // FileUploadReceiver catches the write's exception and returns its message as the error.
    const body = await response.text();
    expect(body, 'the reason reaches the field').toMatch(/DOCTYPE/);
    const item = form.locator('.uploadfield-item', { hasText: file.name.replace(/\.svg$/, '') });
    await expect(item).toContainText(/DOCTYPE/);
    // No thumbnail URL was ever produced for it.
    expect(await backgroundUrl(item.locator('.uploadfield-item__thumbnail'))).not.toMatch(/\.svg/);
});

test('an unsanitized SVG already in the store cannot run script when opened', async ({ page, context }) => {
    await page.goto('/svgb-page');
    const href = await page.locator('#svgb-legacy-dirty').getAttribute('href');
    expect(href, 'premise: the seeded legacy SVG exists').toBeTruthy();
    const url = new URL(href!, page.url()).toString();

    // Served with the policy (draft file, so through PHP: ProtectedFileController).
    const res = await page.request.get(url);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-security-policy'] ?? '').toBe("default-src 'none'; style-src 'unsafe-inline'; sandbox");
    expect(res.headers()['x-content-type-options']).toBe('nosniff');
    expect(await res.text(), 'premise: the stored file still has its script').toMatch(/<script/);

    // Opened as a document, in a page outside the console guard: Chromium logs the blocked
    // script as a console error, which is the expected outcome here.
    const doc = await context.newPage();
    await doc.goto(url);
    expect(await doc.evaluate(() => (window as any).svgbScript ?? null), 'inline <script> blocked').toBeNull();
    expect(await doc.evaluate(() => (window as any).svgbOnload ?? null), 'onload handler blocked').toBeNull();

    // Control: the same bytes without the policy DO run, so the check above can fail.
    const control = await context.newPage();
    await control.route(url, async (route) => {
        const upstream = await route.fetch();
        const headers = { ...upstream.headers() };
        delete headers['content-security-policy'];
        await route.fulfill({ response: upstream, headers });
    });
    await control.goto(url);
    await expect.poll(() => control.evaluate(() => (window as any).svgbScript ?? null), { message: 'script runs without the policy' }).toBe(1);

    await doc.close();
    await control.close();
});
