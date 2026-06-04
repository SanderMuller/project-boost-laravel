<?php declare(strict_types=1);

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

    // Match an `@api` docblock TAG line (` * @api …`), not a prose mention of
    // "@api" elsewhere in the file — so an @internal class that merely references
    // @api in its docs isn't mistaken for part of the frozen surface.
    $apiTag = '/^[ \t]*\*[ \t]*@api\b/m';

    $notApi = [];
    foreach ($imports as $fqcn) {
        $file = boostCoreSourceFor($fqcn);
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false || preg_match($apiTag, $contents) !== 1) {
            $notApi[] = $fqcn;
        }
    }

    // A non-empty list names @internal/unmarked symbols: re-point them onto
    // boost-core's @api surface (PUBLIC_API.md) before depending on them.
    expect($notApi)->toBeEmpty();
});

test('the @api scan resolves real boost-core source files (guards against a vendor layout shift)', function (): void {
    // If boost-core's `src/` layout moved, every file-read in the closure test
    // would miss and the scan would pass for the wrong reason. Pin one known
    // @api class through reflection so a layout shift fails loudly here instead.
    $file = (new ReflectionClass(FrontmatterParser::class))->getFileName();

    expect($file)->toBeString()
        ->and(file_get_contents((string) $file))->toContain('@api');
});
