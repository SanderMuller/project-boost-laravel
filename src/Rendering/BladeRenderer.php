<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Rendering;

use Laravel\Boost\Concerns\RendersBladeGuidelines;
use Override;
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
