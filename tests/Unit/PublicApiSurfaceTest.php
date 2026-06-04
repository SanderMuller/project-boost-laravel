<?php declare(strict_types=1);

/**
 * Surface-boundary guard.
 *
 * Every class/trait under `src/` must be explicitly marked `@api` or `@internal`
 * in its docblock (see PUBLIC_API.md). A class left unmarked freezes by default
 * under our 1.0 semver promise — usually by accident. This test fails the moment
 * a new `src/` type is added without a deliberate classification, so the public
 * surface can't erode silently.
 *
 * The lean target: ZERO `@api` classes — every `src/` type is `@internal`.
 * Consumers never name a class of ours (the package is CLI/artisan-driven), so
 * the frozen contract is entirely CLI / config / behavior, documented in
 * PUBLIC_API.md rather than in class markers.
 */
test('every src/ class is explicitly marked @api or @internal', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS),
    );

    $root = dirname(__DIR__, 2) . '/';
    $declaresType = '/\n(?:final |abstract )*(?:readonly )?(?:class|trait|interface|enum) /';
    $marker = '/^[ \t]*\*[ \t]*@(?:api|internal)\b/m';

    $unmarked = [];
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

        // Only files that declare a type need a classification.
        if (preg_match($declaresType, $contents) !== 1) {
            continue;
        }

        if (preg_match($marker, $contents) !== 1) {
            $unmarked[] = str_replace($root, '', $file->getPathname());
        }
    }

    // A non-empty list names src/ types missing an @api/@internal docblock tag —
    // classify each (almost always @internal) before it freezes by default.
    expect($unmarked)
        ->toBeEmpty();
});
