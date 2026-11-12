<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Rendering;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Boost\Concerns\RendersBladeGuidelines;
use Override;
use RuntimeException;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;

/**
 * Renders `*.blade.php` skills via laravel/boost's own `RendersBladeGuidelines`
 * trait. That trait handles:
 *  - `@boostsnippet()` directive protection (saves code blocks before Blade
 *    sees them, restores after).
 *  - Backtick / `<?php` / Blade-component / Volt placeholder substitution
 *    so PHP code examples in template bodies survive Blade compilation.
 *  - `$assist = app(GuidelineAssist::class)` runtime binding — the var
 *    laravel/boost's bundled `pest-testing`, `livewire-development`,
 *    etc. templates reference.
 *  - `html_entity_decode` on the rendered output.
 *  - `@scoped([...])` … `@endscoped` markers. Blade has no such directive, so
 *    without laravel/boost's marker pass they reach the output as literal text.
 *
 * Constructor must remain parameterless (boost-core's plugin contract).
 * The `GuidelineAssist` resolver runs via Laravel's container at render
 * time, so it has full app context.
 *
 * @internal This package's `SkillRenderer` implementation. It is auto-registered
 *           on the `project-boost:sync` path — consumers never name it — and it
 *           requires a bootstrapped Laravel `Application` (bare `vendor/bin/boost
 *           sync` can't render Blade through it; use the artisan command). The
 *           render contract is boost-core's `@api` `SkillRenderer`, not ours.
 */
final class BladeRenderer implements SkillRenderer
{
    use RendersBladeGuidelines;

    private int $scopedBlockCount = 0;

    #[Override]
    public function extensions(): array
    {
        return ['blade.php'];
    }

    #[Override]
    public function render(string $raw, RenderContext $ctx): string
    {
        // Container-bootstrap guard. RendersBladeGuidelines calls
        // Container::path() (an Application method, not Container) — which
        // throws "undefined method" if the global container isn't a fully
        // bootstrapped Laravel Application. That happens when bare
        // `vendor/bin/boost sync` invokes this renderer via the consumer's
        // boost.php `withSkillRenderers(BladeRenderer::class)` declaration
        // without the artisan kernel having bootstrapped the framework.
        //
        // Fail fast with an actionable message rather than producing a
        // cryptic Container::path() error mid-render (which historically
        // resulted in partial managed-region writes before boost-core 0.9.3
        // added the render-fail-then-write safety gate).
        $container = Container::getInstance();
        if (! $container instanceof Application) {
            throw new RuntimeException(
                'BladeRenderer requires a bootstrapped Laravel Application; '
                . 'the current container is a bare Illuminate\Container. This '
                . 'typically happens when running `vendor/bin/boost sync` in '
                . 'a Laravel project. Use `php artisan project-boost:sync` '
                . 'instead — the artisan entry point bootstraps the framework '
                . 'before invoking the renderer. To skip Blade rendering '
                . 'entirely, remove BladeRenderer from your boost.php '
                . 'withSkillRenderers() declaration.'
            );
        }

        // laravel/boost's own string entry point: snippet protection, the
        // `@scoped` marker pass, the Blade render, then snippet restore. Calling
        // it, rather than re-sequencing those steps here, keeps this renderer in
        // step with laravel/boost releases that change the order.
        //
        // `stripScoped: false` is laravel/boost's scoping-off output: the
        // markers go, the block bodies stay inline. Its scoping-on mode moves
        // the bodies to `.ai/rules/boost/`, which this package does not write —
        // `scopedBlockCount()` lets the sync say so.
        $result = $this->renderBladeStringWithScopedBlocks($raw, $ctx->sourcePath, [], false);

        $this->scopedBlockCount += count($result['blocks']);

        return $result['content'];
    }

    /**
     * How many `@scoped` blocks this instance rendered inline so far.
     */
    public function scopedBlockCount(): int
    {
        return $this->scopedBlockCount;
    }
}
