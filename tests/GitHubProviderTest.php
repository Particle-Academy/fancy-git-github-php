<?php

declare(strict_types=1);

namespace FancyGit\GitHub\Tests;

use FancyGit\GitHub\GitHubProvider;
use Github\Client;
use PHPUnit\Framework\TestCase;

final class GitHubProviderTest extends TestCase
{
    public function test_it_identifies_github_remotes(): void
    {
        $provider = new GitHubProvider(new Client);

        self::assertSame([
            'provider' => 'github',
            'owner' => 'Particle-Academy',
            'name' => 'fancy-git-php',
        ], $provider->identify([
            'name' => 'origin',
            'fetchUrl' => 'git@github.com:Particle-Academy/fancy-git-php.git',
        ]));
    }

    public function test_it_rejects_other_hosts(): void
    {
        self::assertNull((new GitHubProvider(new Client))->identify([
            'name' => 'origin',
            'fetchUrl' => 'https://gitlab.com/acme/app.git',
        ]));
    }
}
