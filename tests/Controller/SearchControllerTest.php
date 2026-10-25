<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Graceful-degradation tests for the search demo (spec 008). The test
 * environment has no Elasticsearch, so both endpoints must say so nicely.
 */
final class SearchControllerTest extends WebTestCase
{
    public function testSearchPageRendersTheForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Search the demo documents', (string) $client->getResponse()->getContent());
    }

    public function testSearchPageShowsNoticeWhenElasticsearchIsUnavailable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/search?q=malina');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Search is temporarily unavailable', (string) $client->getResponse()->getContent());
    }

    public function testApiElasticReturns503WithoutConfiguration(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/elastic');

        self::assertResponseStatusCodeSame(503);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('unavailable', $payload['status']);
    }

    public function testStartPageDoesNotDependOnElasticsearch(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }
}
