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
 *
 * Constructor must remain parameterless (boost-core's plugin contract).
 * The `GuidelineAssist` resolver runs via Laravel's container at render
 * time, so it has full app context.
 */
final class BladeRenderer implements SkillRenderer
{
    use RendersBladeGuidelines;

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

        // Mirrors RendersBladeGuidelines::renderBladeFile but starts from a
        // string instead of reading the file again. Keeps snippet protection
        // + GuidelineAssist binding intact.
        $content = $this->processBoostSnippets($raw);
        $rendered = $this->renderContent($content, $ctx->sourcePath, []);
        $rendered = strtr($rendered, $this->storedSnippets);

        $this->storedSnippets = [];

        return $rendered;
    }
}
