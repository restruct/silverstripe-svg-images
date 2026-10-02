import { test, expect } from './support';

// /dev/svg-compare (dev mode): applies the same manipulations to an SVG and to a PNG twin and shows
// them side by side. Every SVG result must have the same rendered size as its PNG twin.

test('every SVG manipulation renders at the same size as its PNG twin', async ({ page }) => {
    // The install link asks for confirmation.
    page.on('dialog', (d) => d.accept());
    await page.goto('/dev/svg-compare');
    const install = page.locator('a[href*="install=1"]');
    if (await install.count()) {
        // First run on this host: install the bundled test images (2 published + 2 draft).
        await install.click();
        await page.waitForURL(/\/dev\/svg-compare$/);
    }

    // All images loaded (the console guard catches a broken one as well).
    const imgs = page.locator('img');
    await expect.poll(() => imgs.count(), { message: 'comparison images' }).toBeGreaterThan(20);
    await expect
        .poll(() => imgs.evaluateAll((all) => all.filter((i: any) => !(i.complete && i.naturalWidth > 0)).length), { message: 'images not loaded' })
        .toBe(0);

    // One table row per manipulation (published and draft tables): the SVG result in the first
    // result cell, the PNG result of the same manipulation in the second. A manipulation the
    // module could not apply shows an error cell instead.
    const rows = await page.locator('tr').evaluateAll((trs) =>
        trs
            .filter((tr) => tr.querySelector('td.result-cell, td.text-danger'))
            .map((tr) => {
                const label = (tr.querySelector('td')?.textContent ?? '').replace(/\s+/g, ' ').trim();
                const error = (tr.querySelector('td.text-danger')?.textContent ?? '').trim();
                const imgs = [...tr.querySelectorAll('td.result-cell img')] as HTMLImageElement[];
                return {
                    label,
                    error,
                    results: imgs.map((i) => ({ src: i.getAttribute('src') ?? '', w: i.naturalWidth, h: i.naturalHeight })),
                };
            }),
    );
    expect(rows.length, 'manipulation rows').toBeGreaterThan(10);

    const problems: string[] = [];
    for (const row of rows) {
        if (row.error) {
            problems.push(`${row.label}: ${row.error}`);
            continue;
        }
        const [svg, png] = row.results;
        if (!svg || !png) {
            problems.push(`${row.label}: expected an SVG and a PNG result`);
            continue;
        }
        if (!/\.svg(\?|$)/.test(svg.src)) {
            problems.push(`${row.label}: SVG result is not an SVG (${svg.src})`);
        }
        if (svg.w !== png.w || svg.h !== png.h) {
            problems.push(`${row.label}: svg ${svg.w}x${svg.h}, png ${png.w}x${png.h}`);
        }
    }
    expect(problems, 'every SVG result matches its PNG twin').toEqual([]);
});
