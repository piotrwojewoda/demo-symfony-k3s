<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageShowsKreciowoByDefault(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('kreciowo.pl', $content);
        self::assertStringContainsString('mole', $content, 'kreciowo.pl must show the mole mascot');
        self::assertStringNotContainsString('Database', $content);
        self::assertStringNotContainsString('bibop', $content);
    }

    public function testHomePageShowsBibopForBibopHost(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', [], [], ['HTTP_HOST' => 'bibop.pl']);

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('bibop.pl', $content);
        self::assertStringContainsString('weird creature', $content, 'bibop.pl must show the weird creature mascot');
        self::assertStringContainsString('data-testid="chase-scene"', $content);
        self::assertStringContainsString('@media (max-width: 480px)', $content);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $content);
        self::assertStringNotContainsString('kreciowo', $content);
    }

    public function testHomePageShowsBibopForWwwBibopHost(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', [], [], ['HTTP_HOST' => 'www.bibop.pl']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('bibop.pl', (string) $client->getResponse()->getContent());
    }

    public function testHealthzReturnsPlainOk(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $client->getResponse()->getContent());
    }
}
