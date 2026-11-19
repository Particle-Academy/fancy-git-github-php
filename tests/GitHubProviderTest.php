<?php

declare(strict_types=1);

namespace FancyGit\GitHub\Tests;

use FancyGit\GitHub\GitHubProvider;
use Github\Client;

test('it identifies github remotes', function () {
    $provider = new GitHubProvider(new Client);

    self::assertSame([
        'provider' => 'github',
        'owner' => 'Particle-Academy',
        'name' => 'fancy-git-php',
    ], $provider->identify([
        'name' => 'origin',
        'fetchUrl' => 'git@github.com:Particle-Academy/fancy-git-php.git',
    ]));
});

test('it rejects other hosts', function () {
    self::assertNull((new GitHubProvider(new Client))->identify([
        'name' => 'origin',
        'fetchUrl' => 'https://gitlab.com/acme/app.git',
    ]));
});
