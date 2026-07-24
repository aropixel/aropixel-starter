<?php

namespace catalog;

use Castor\Attribute\AsTask;

use function Castor\http_request;
use function Castor\io;
use function Castor\variable;

/**
 * Rebuilds the self-contained component catalogue published on GitHub.
 *
 * `admin-bundle/docs/catalog.html` (served at the GitHub Pages root as `index.html`) is a
 * single, dependency-free file: 0 external requests, Poppins embedded as base64, Bootstrap
 * wrapped in `@layer bootstrap`, and every bundle stylesheet inlined. That is what lets the
 * catalogue be browsed straight from the repo, without installing anything.
 *
 * The one thing that must never drift is the embedded CSS: if a component's stylesheet
 * changes and this file is not rebuilt, the published catalogue shows the *old* admin. So
 * `catalog:build` regenerates the embedded `<style>` from source, in the exact order
 * `style.css` loads it, then re-shoots the README preview.
 *
 * Division of labour:
 *   - The **markup** of the standalone lives in `docs/catalog.html` itself and is edited by
 *     hand, in step with the live `catalog/index.html.twig`. Adding a new component means
 *     touching both — the Twig (for `/admin/_catalog`) and the standalone.
 *   - The **CSS** and the **screenshot** are mechanical, and this task owns them.
 *
 * `catalog:build` needs no running app (it reads CSS from disk). `catalog:shot` renders the
 * finished file in Browserless, so it needs `castor up` — same service as the visual harness.
 */
const BROWSERLESS = 'http://localhost:9222';
const LAUNCH_ARGS = ['--ignore-certificate-errors', '--no-sandbox'];

/** The README hero is cropped to this band — colours through badges, a clean section edge. */
const PREVIEW_WIDTH = 1440;
const PREVIEW_HEIGHT = 1790;

#[AsTask(description: 'Rebuild the self-contained GitHub catalogue and its preview', namespace: 'catalog', name: 'build', aliases: ['catalog'])]
function build(bool $noShot = false): int
{
    io()->title('Catalogue — static export');

    $root = variable('root_dir');
    $cssDir = $root . '/admin-bundle/src/Resources/public/css';
    $htmlFile = $root . '/admin-bundle/docs/catalog.html';
    $indexFile = $root . '/admin-bundle/docs/index.html';

    if (!is_file($htmlFile)) {
        io()->error("Missing {$htmlFile}. The standalone shell is authored by hand — this task rebuilds its CSS, it does not create the file.");

        return 1;
    }

    $css = buildBundleCss($cssDir);
    io()->writeln(\sprintf('  <info>✓</info> bundle CSS assembled (%d KB)', (int) (\strlen($css) / 1024)));

    $html = (string) file_get_contents($htmlFile);

    // Replace only the first <style> — the embedded bundle CSS in <head>. The second
    // <style>, in <body>, is the catalogue's own chrome and is not ours to touch.
    $replaced = preg_replace(
        '#<style>.*?</style>#s',
        "<style>\n" . $css . "\n</style>",
        $html,
        1,
        $count,
    );

    if (1 !== $count || null === $replaced) {
        io()->error('Could not locate the embedded <style> block to replace in docs/catalog.html.');

        return 1;
    }

    file_put_contents($htmlFile, $replaced);
    file_put_contents($indexFile, $replaced);
    io()->success('docs/catalog.html and docs/index.html rebuilt.');

    if ($noShot) {
        io()->note('Skipped the preview screenshot (--no-shot). Run `castor catalog:shot` when the app is up.');

        return 0;
    }

    return shot();
}

#[AsTask(description: 'Re-shoot the README catalogue preview from docs/catalog.html', namespace: 'catalog', name: 'shot')]
function shot(): int
{
    $root = variable('root_dir');
    $htmlFile = $root . '/admin-bundle/docs/catalog.html';
    $previewFile = $root . '/admin-bundle/doc/assets/catalog-preview.png';

    if (!ping()) {
        io()->error('Browserless is unreachable on ' . BROWSERLESS);
        io()->note('Run `castor chrome` once to add the service, then `castor up`.');

        return 1;
    }

    $png = renderPreview((string) file_get_contents($htmlFile));

    if ('' === $png) {
        io()->error('Screenshot came back empty.');

        return 1;
    }

    file_put_contents($previewFile, $png);
    io()->success(\sprintf('doc/assets/catalog-preview.png rebuilt (%d KB).', (int) (\strlen($png) / 1024)));

    return 0;
}

/**
 * Assembles the embedded stylesheet, in the exact order the admin loads it:
 * fonts (inlined) → Bootstrap (layered) → everything style.css imports → style.css tail.
 */
function buildBundleCss(string $cssDir): string
{
    $parts = [embedFonts($cssDir), layeredBootstrap($cssDir), resolveManifest($cssDir)];

    return implode("\n\n", $parts) . "\n";
}

/**
 * Reads foundations/_fonts.css and inlines each Poppins woff2 as a base64 data URI, so the
 * published page makes zero font requests (and carries no GDPR-relevant call to a CDN).
 */
function embedFonts(string $cssDir): string
{
    $css = (string) file_get_contents($cssDir . '/foundations/_fonts.css');

    return (string) preg_replace_callback(
        "#url\\(['\"]?\\.\\./\\.\\./fonts/poppins/([^'\")]+\\.woff2)['\"]?\\)#",
        static function (array $m) use ($cssDir): string {
            $path = $cssDir . '/../fonts/poppins/' . $m[1];
            $data = base64_encode((string) file_get_contents($path));

            return "url(data:font/woff2;base64,{$data})";
        },
        $css,
    );
}

/**
 * Wraps Bootstrap in `@layer bootstrap {}` — the same construction as
 * foundations/_bootstrap-layer.css, so our unlayered rules win by cascade order rather
 * than by `!important`. The `@charset` and sourcemap comment are stripped: `@charset` is
 * only valid at byte 0 of a stylesheet, never inside a layer block.
 */
function layeredBootstrap(string $cssDir): string
{
    $bs = (string) file_get_contents($cssDir . '/../modules/bootstrap/css/bootstrap.min.css');
    $bs = (string) preg_replace('/@charset\s+"[^"]*";/', '', $bs);
    $bs = (string) preg_replace('~/\*#\s*sourceMappingURL=[^*]*\*/~', '', $bs);

    return "@layer bootstrap {\n" . trim($bs) . "\n}";
}

/**
 * Inlines every stylesheet style.css @imports, in order, followed by style.css's own
 * trailing rules. _fonts.css is skipped — it is already embedded as base64 by embedFonts().
 * Each file is prefixed with a `/* path *\/` marker so the assembled sheet stays legible.
 */
function resolveManifest(string $cssDir): string
{
    $manifest = (string) file_get_contents($cssDir . '/style.css');
    $out = [];
    $lastImportEnd = 0;

    if (preg_match_all('#@import\s+url\(["\']\./([^"\']+)["\']\);#', $manifest, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as $i => [$relPath]) {
            if ('foundations/_fonts.css' === $relPath) {
                continue;
            }

            $file = $cssDir . '/' . $relPath;
            if (!is_file($file)) {
                io()->warning("style.css imports a missing file: {$relPath}");

                continue;
            }

            $out[] = "/* ./{$relPath} */\n" . rtrim((string) file_get_contents($file));

            // Track where the @import block ends, so the tail below starts after it.
            $fullMatchOffset = $matches[0][$i][1];
            $fullMatchLength = \strlen($matches[0][$i][0]);
            $lastImportEnd = max($lastImportEnd, $fullMatchOffset + $fullMatchLength);
        }
    }

    // style.css carries its own rules after the @import block — keep them (they are the
    // legacy tail scheduled for removal, but until then the admin loads them).
    $tail = trim(substr($manifest, $lastImportEnd));
    if ('' !== $tail) {
        $out[] = "/* ./style.css (tail) */\n" . $tail;
    }

    return implode("\n\n", $out);
}

function ping(): bool
{
    try {
        return 200 === http_request('GET', BROWSERLESS . '/json/version', ['timeout' => 10])->getStatusCode();
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Renders the finished HTML in Browserless via setContent (no server needed — the file is
 * self-contained) and clips the hero band used in the README.
 */
function renderPreview(string $html): string
{
    $code = <<<'JS'
        export default async function ({ page, context }) {
          const { html, width, height } = context;
          await page.setViewport({ width, height });
          await page.setContent(html, { waitUntil: 'networkidle0', timeout: 45000 });
          // Freeze the caret and animations, exactly as the visual harness does, so the
          // preview is byte-stable between rebuilds of an unchanged catalogue.
          await page.addStyleTag({ content: '* { caret-color: transparent !important; } *, *::before, *::after { animation-play-state: paused !important; transition: none !important; }' });
          await page.evaluate(() => document.activeElement && document.activeElement.blur());
          const shot = await page.screenshot({ type: 'png', clip: { x: 0, y: 0, width, height } });
          let binary = '';
          const CHUNK = 0x8000;
          for (let i = 0; i < shot.length; i += CHUNK) {
            binary += String.fromCharCode.apply(null, shot.subarray(i, i + CHUNK));
          }
          return { data: { png: btoa(binary) }, type: 'application/json' };
        }
        JS;

    $response = http_request('POST', endpoint('/function'), [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'code' => $code,
            'context' => ['html' => $html, 'width' => PREVIEW_WIDTH, 'height' => PREVIEW_HEIGHT],
        ], JSON_THROW_ON_ERROR),
        'timeout' => 120,
    ]);

    if (200 !== $response->getStatusCode()) {
        io()->error(trim(substr($response->getContent(false), 0, 200)));

        return '';
    }

    $data = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];

    return base64_decode($data['png'] ?? '', true) ?: '';
}

function endpoint(string $path): string
{
    return \sprintf(
        '%s%s?launch=%s',
        BROWSERLESS,
        $path,
        rawurlencode(json_encode(['args' => LAUNCH_ARGS], JSON_THROW_ON_ERROR)),
    );
}
