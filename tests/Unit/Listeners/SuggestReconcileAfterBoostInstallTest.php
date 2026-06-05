<?php declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use SanderMuller\ProjectBoostLaravel\Listeners\SuggestReconcileAfterBoostInstall;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit coverage for the post-`boost:install` reconcile nudge — driven directly
 * with an injected suppression resolver, no application bootstrap.
 */
function fireCommandFinished(string $command, int $exitCode, bool $suppressed, ?InputInterface $input = null): string
{
    $output = new BufferedOutput();
    $event = new CommandFinished($command, $input ?? new ArrayInput([]), $output, $exitCode);

    (new SuggestReconcileAfterBoostInstall(static fn (): bool => $suppressed))->handle($event);

    return $output->fetch();
}

/** An input bound to a `--mcp` flag, as `boost:install` defines it. */
function mcpInput(bool $mcp): ArrayInput
{
    return new ArrayInput(
        $mcp ? ['--mcp' => true] : [],
        new InputDefinition([new InputOption('mcp', null, InputOption::VALUE_NONE)]),
    );
}

it('nudges toward reconcile after a successful standalone boost:install', function (): void {
    $output = fireCommandFinished('boost:install', 0, suppressed: false);

    expect($output)->toContain('project-boost:reconcile')
        ->and($output)->toContain('seeded');
});

it('stays silent for any other command', function (): void {
    expect(fireCommandFinished('project-boost:sync', 0, suppressed: false))
        ->toBeEmpty();
});

it('stays silent when boost:install failed', function (): void {
    expect(fireCommandFinished('boost:install', 1, suppressed: false))
        ->toBeEmpty();
});

it('stays silent while project-boost:install is driving the install', function (): void {
    expect(fireCommandFinished('boost:install', 0, suppressed: true))
        ->toBeEmpty();
});

it('stays silent after boost:install --mcp (no guidance is seeded)', function (): void {
    expect(fireCommandFinished('boost:install', 0, suppressed: false, input: mcpInput(true)))
        ->toBeEmpty();
});

it('still nudges after boost:install without --mcp', function (): void {
    expect(fireCommandFinished('boost:install', 0, suppressed: false, input: mcpInput(false)))
        ->toContain('project-boost:reconcile');
});
