<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Skills\FrontmatterParser;

/**
 * @api-closure conformance.
 *
 * project-boost-laravel is boost-core's deepest consumer — it drives the sync,
 * implements `BoostWrapperContract`, and ships a `SkillRenderer`. To stay safe
 * under boost-core's 1.0 semver freeze it must depend ONLY on boost-core's
 * frozen `@api` surface (see boost-core's `PUBLIC_API.md`); anything `@internal`
 * may change in any release.
 *
 * This test is the standing proof of that closure. It scans every `use`
 * statement in `src/` and asserts each `SanderMuller\BoostCore\*` symbol it
 * reaches is marked `@api` in the installed boost-core source. If a boost-core
 * bump (or a new `src/` import) ever drags the package onto an `@internal`
 * symbol, this fails — early warning for us, and a downstream conformance
 * signal for boost-core's deepest consumer.
 */

/**
 * The fully-qualified `SanderMuller\BoostCore\*` class names imported anywhere
 * under `src/`.
 *
 * @return list<string>
 */
function boostCoreImportsInSrc(): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS),
    );

    $fqcns = [];
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        if (preg_match_all('/^use (SanderMuller\\\\BoostCore\\\\[A-Za-z0-9_\\\\]+)(?: as [A-Za-z0-9_]+)?;/m', $contents, $matches) > 0) {
            foreach ($matches[1] as $fqcn) {
                $fqcns[$fqcn] = true;
            }
        }
    }

    return array_keys($fqcns);
}

/**
 * Known @internal boost-core symbols `src/` still reaches, each tracked for
 * upstream resolution. Keep this EMPTY whenever possible.
 *
 * - `BoostConfigNotFoundException`: the console commands catch it to turn a
 *   missing boost config into a friendly hint. There is no @api existence
 *   check yet, so the only alternative is re-hardcoding the config paths (the
 *   bug 0.9.0 fixed). Resolution in flight with the boost-core maintainer —
 *   either promote it to @api, or add an @api `BoostConfig::exists()` and drop
 *   the catch. Remove this entry when that lands.
 *
 * @var list<string>
 */
const KNOWN_INTERNAL_IN_FLIGHT = [
    BoostConfigNotFoundException::class,
];

function boostCoreSourceFor(string $fqcn): string
{
    $relative = str_replace(['SanderMuller\\BoostCore\\', '\\'], ['', '/'], $fqcn);

    return dirname(__DIR__, 2) . '/vendor/sandermuller/boost-core/src/' . $relative . '.php';
}

test("every boost-core symbol imported in src/ is part of boost-core's frozen @api surface", function (): void {
    $imports = boostCoreImportsInSrc();

    // Sanity: src/ genuinely drives boost-core — a regex that silently matched
    // nothing would otherwise pass this test vacuously.
    expect($imports)->not->toBeEmpty();

    $notApi = [];
    foreach ($imports as $fqcn) {
        $file = boostCoreSourceFor($fqcn);
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false || ! str_contains($contents, '@api')) {
            $notApi[] = $fqcn;
        }
    }

    // A non-empty list (minus the tracked in-flight allowlist) names offending
    // @internal/unmarked symbols: re-point them onto boost-core's @api surface
    // (PUBLIC_API.md) before depending on them.
    expect(array_values(array_diff($notApi, KNOWN_INTERNAL_IN_FLIGHT)))
        ->toBeEmpty();
});

test('the in-flight @internal allowlist has no stale entries', function (): void {
    // Each allowlisted symbol MUST still be non-@api. Once the maintainer
    // resolves one (promotes it, or supplies an @api replacement we adopt),
    // this fails — forcing the entry's removal so the allowlist never rots.
    foreach (KNOWN_INTERNAL_IN_FLIGHT as $fqcn) {
        $file = boostCoreSourceFor($fqcn);
        $raw = is_file($file) ? file_get_contents($file) : '';
        $contents = $raw === false ? '' : $raw;

        expect(str_contains($contents, '@api'))->toBeFalse(
            "{$fqcn} is now @api (or gone) — remove it from KNOWN_INTERNAL_IN_FLIGHT.",
        );
    }
});

test('the @api scan resolves real boost-core source files (guards against a vendor layout shift)', function (): void {
    // If boost-core's `src/` layout moved, every file-read in the closure test
    // would miss and the scan would pass for the wrong reason. Pin one known
    // @api class through reflection so a layout shift fails loudly here instead.
    $file = (new ReflectionClass(FrontmatterParser::class))->getFileName();

    expect($file)->toBeString()
        ->and(file_get_contents((string) $file))->toContain('@api');
});
