<?php declare(strict_types=1);

test('php runtime is available', function (): void {
    expect(getmypid())->toBeInt();
});
