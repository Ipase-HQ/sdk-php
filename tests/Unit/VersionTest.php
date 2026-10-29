<?php

declare(strict_types=1);

use Ipase\Version;

it('declares the spec version composer.json gives CI', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    expect(Version::SPEC_VERSION)->toBe($composer['extra']['ipase']['spec']);
});

it('names itself php with a semver version', function () {
    expect(Version::SDK_NAME)->toBe('php')
        ->and(Version::SDK_VERSION)->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/');
});
