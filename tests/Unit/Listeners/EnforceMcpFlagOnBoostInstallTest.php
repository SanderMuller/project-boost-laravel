<?php declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use SanderMuller\ProjectBoostLaravel\Listeners\EnforceMcpFlagOnBoostInstall;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

function makeBoostInstallEvent(string $command = 'boost:install', bool $mcpAlreadySet = false): CommandStarting
{
    $definition = new InputDefinition([
        new InputOption('mcp', null, InputOption::VALUE_NONE, 'Install MCP server configuration'),
    ]);
    $input = new ArrayInput($mcpAlreadySet ? ['--mcp' => true] : [], $definition);

    return new CommandStarting($command, $input, new NullOutput());
}

describe('EnforceMcpFlagOnBoostInstall', function (): void {
    $enabled = fn (): bool => true;
    $disabled = fn (): bool => false;

    it('forces --mcp on boost:install when flag is true and --mcp is missing', function () use ($enabled): void {
        $event = makeBoostInstallEvent();

        (new EnforceMcpFlagOnBoostInstall($enabled))->handle($event);

        expect($event->input->getOption('mcp'))->toBeTrue();
    });

    it('leaves --mcp as-is when already passed', function () use ($enabled): void {
        $event = makeBoostInstallEvent(mcpAlreadySet: true);

        (new EnforceMcpFlagOnBoostInstall($enabled))->handle($event);

        expect($event->input->getOption('mcp'))->toBeTrue();
    });

    it('does nothing when the flag is false (default)', function () use ($disabled): void {
        $event = makeBoostInstallEvent();

        (new EnforceMcpFlagOnBoostInstall($disabled))->handle($event);

        expect($event->input->getOption('mcp'))->toBeFalse();
    });

    it('does nothing for commands other than boost:install', function () use ($enabled): void {
        $event = makeBoostInstallEvent(command: 'migrate');

        (new EnforceMcpFlagOnBoostInstall($enabled))->handle($event);

        expect($event->input->getOption('mcp'))->toBeFalse();
    });

    it('does nothing when the command lacks an --mcp option (defensive)', function () use ($enabled): void {
        // Future laravel/boost release renames or drops the option.
        $definition = new InputDefinition([]);
        $input = new ArrayInput([], $definition);
        $event = new CommandStarting('boost:install', $input, new NullOutput());

        (new EnforceMcpFlagOnBoostInstall($enabled))->handle($event);

        expect($input->hasOption('mcp'))->toBeFalse();
    });

});
