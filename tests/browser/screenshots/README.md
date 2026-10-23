# README screenshots

`readme.shot.ts` writes the PNGs in `docs/images/` that the module README shows. It is not a test
suite: it runs with its own config, `playwright.screenshots.config.ts`, which the browser tests and
CI never load, so a normal test run never rewrites the images. Never edit the PNGs by hand;
regenerate them.

What it needs:

- a Silverstripe 6 host with this module installed and the browser fixtures from
  `tests/browser/fixtures/` copied into its `app/src/`, then the database built. `SvgShowcasePage`
  seeds the content: a `Showcase` folder in Files (SVGs from `fixtures/files/showcase/` plus two
  raster images drawn with GD) and a published `/svg-showcase` page whose hero image is an SVG;
- `BROWSER_TARGET_URLS` pointing at that host (only the `ss6` target is used, else the first).

The shared runner in the maintainers' workspace does all of that:

```bash
BROWSER_ONLY=ss6 tools/browser/run.sh silverstripe-svg-images --config=playwright.screenshots.config.ts
```

Against a host you started yourself, from this directory:

```bash
npm ci
BROWSER_TARGET_URLS="ss6=http://127.0.0.1:8906" npx playwright test --config=playwright.screenshots.config.ts
```

The Files screenshot shows a Focus Point field because the host it was taken on had
`jonom/focuspoint` installed; without it, that field is absent.
