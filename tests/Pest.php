<?php declare(strict_types=1);
use SanderMuller\ProjectBoostLaravel\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a
| specific PHPUnit test case class. By default, that class is
| `PHPUnit\Framework\TestCase`. For Laravel-aware packages, the bootstrap
| phase replaces this with `Orchestra\Testbench\TestCase` for Feature
| tests, or a project-specific test case.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Project-specific custom expectations go here. Pest's documentation has
| examples: https://pestphp.com/docs/custom-expectations
|
*/

// expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Global helper functions used across multiple test files go here. Prefer
| Pest's higher-order test syntax over loose helpers where possible.
|
*/

// function something(): void { /* ... */ }
