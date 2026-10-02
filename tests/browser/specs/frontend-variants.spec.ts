import { test, expect, fetchSvg, naturalSize } from './support';

// Vector manipulation: variants of a published SVG are SVG documents with new dimensions, served
// publicly and rendered at the requested size by the browser.

const VARIANTS = ['original', 'scalewidth', 'fill', 'fit', 'pad'] as const;

test('SVG variants render at their requested sizes and stay vector', async ({ page }) => {
    const response = await page.goto('/svgb-page');
    expect(response?.status()).toBe(200);

    for (const name of VARIANTS) {
        const img = page.locator(`#svgb-${name}`);
        const expected = {
            width: Number(await img.getAttribute('data-expect-width')),
            height: Number(await img.getAttribute('data-expect-height')),
        };
        // The browser's own reading of the served file.
        expect(await naturalSize(img), `${name} renders at its size`).toEqual(expected);

        // Served as SVG (not rasterised), with the new size in its root element.
        const src = (await img.getAttribute('src'))!;
        if (name !== 'original') {
            expect(src, `${name} is a variant file`).toMatch(/__[A-Za-z0-9]+\.svg$/);
        }
        const svg = await fetchSvg(page, src);
        expect(svg, `${name} keeps a viewBox`).toMatch(/viewBox=/);
    }

    // Published, so public: an anonymous visitor gets the variant too.
    const anon = await page.context().browser()!.newContext({ storageState: { cookies: [], origins: [] } });
    const res = await anon.request.get(new URL((await page.locator('#svgb-fill').getAttribute('src'))!, page.url()).toString());
    expect(res.status(), 'variant served to an anonymous visitor').toBe(200);
    await anon.close();
});
