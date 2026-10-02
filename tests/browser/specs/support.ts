import { test as base, expect, type APIResponse, type Locator, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

// Shared fixtures and helpers for the svg-images specs.
//
// Fixtures (tests/browser/fixtures/, copied into the scratch host by the runner):
//  - /admin/svgb: a ModelAdmin over SvgBRecord, which has a has_one Image (an UploadField);
//  - /svgb-page: a front-end page showing a seeded, published SVG and some of its variants;
//  - files/svgb-clean.svg (200 x 150) and files/svgb-dirty.svg (120 x 80, with a <script>, an
//    onload handler, a javascript: link and a remote image reference).

/**
 * test, extended with an automatic console guard: every spec fails if the page logs a console
 * error or throws an uncaught exception at any point (a broken thumbnail or variant URL arrives
 * as "Failed to load resource"). Warnings do not count.
 */
export const test = base.extend<{ consoleGuard: void }>({
    consoleGuard: [
        async ({ page }, use, testInfo) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(`console.error: ${msg.text()} (${msg.location().url})`);
                }
            });
            page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));

            await use();

            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(errors, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

/**
 * A fixture SVG as an upload payload, under a name unique to this run (repeats must not collide
 * with files an earlier repeat uploaded). Returns the payload and the title the CMS derives from
 * the name (dashes become spaces, extension dropped).
 */
export function svgUpload(fixture: 'svgb-clean' | 'svgb-dirty', prefix: string): {
    file: { name: string; mimeType: string; buffer: Buffer };
    title: string;
} {
    const stamp = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
    const name = `${prefix}-${stamp}.svg`;
    return {
        file: {
            name,
            mimeType: 'image/svg+xml',
            buffer: fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'files', `${fixture}.svg`)),
        },
        title: name.replace(/\.svg$/, '').replace(/-/g, ' '),
    };
}

/** The URL inside a CSS background-image value (url("...")), or '' when there is none. */
export async function backgroundUrl(el: Locator): Promise<string> {
    const style = await el.evaluate((e) => getComputedStyle(e).backgroundImage);
    const m = style.match(/url\("?(.*?)"?\)$/);
    return m ? m[1] : '';
}

/**
 * Fetch a URL with the admin session (protected draft files need it) and assert it is served as
 * an SVG document. Returns the body.
 */
export async function fetchSvg(page: Page, url: string): Promise<string> {
    const res: APIResponse = await page.request.get(url);
    expect(res.status(), `GET ${url}`).toBe(200);
    expect(res.headers()['content-type'] ?? '', `content type of ${url}`).toMatch(/^image\/svg\+xml/);
    const body = await res.text();
    expect(body, 'an SVG document').toMatch(/<svg[\s>]/);
    return body;
}

/** Decode an SVG given either as a data: URI (base64 or URL-encoded) or as an http URL. */
export async function svgFromUrl(page: Page, url: string): Promise<string> {
    if (url.startsWith('data:')) {
        // data:image/svg+xml[;charset=...][;base64],<payload>
        const comma = url.indexOf(',');
        const meta = url.slice(0, comma);
        expect(meta, 'an SVG data URI').toMatch(/^data:image\/svg\+xml/);
        const payload = url.slice(comma + 1);
        return /;base64$/.test(meta) ? Buffer.from(payload, 'base64').toString('utf8') : decodeURIComponent(payload);
    }
    return fetchSvg(page, url);
}

/** Wait until an <img> has loaded and return its natural size. */
export async function naturalSize(img: Locator): Promise<{ width: number; height: number }> {
    await expect
        .poll(() => img.evaluate((i: HTMLImageElement) => i.complete && i.naturalWidth > 0), { message: 'image loaded' })
        .toBe(true);
    return img.evaluate((i: HTMLImageElement) => ({ width: i.naturalWidth, height: i.naturalHeight }));
}

/** What sanitization must have removed from the dirty fixture (and what it must keep). */
export function expectSanitized(svg: string): void {
    expect(svg, 'no <script> element').not.toMatch(/<script/i);
    expect(svg, 'no event handler attribute').not.toMatch(/\son[a-z]+\s*=/i);
    expect(svg, 'no javascript: URL').not.toMatch(/javascript:/i);
    expect(svg, 'no remote reference').not.toContain('external.example');
    // The drawing itself survives.
    expect(svg).toMatch(/<rect[\s>]/);
    expect(svg).toMatch(/<circle[\s>]/);
}
