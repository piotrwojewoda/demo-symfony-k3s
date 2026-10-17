<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Kernel;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageShowsVersionTable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to');

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('Symfony', $content);
        self::assertStringContainsString(Kernel::VERSION, $content);
        self::assertStringContainsString(PHP_VERSION, $content);
        self::assertStringContainsString('Deployed release', $content);
        self::assertStringContainsString('Database', $content);
    }

    public function testHomePageWorksWhenDatabaseIsDown(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'unavailable',
            (string) $client->getResponse()->getContent(),
            'The start page must render even when the database connection fails',
        );
    }

    public function testHealthzReturnsPlainOk(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $client->getResponse()->getContent());
    }
}
