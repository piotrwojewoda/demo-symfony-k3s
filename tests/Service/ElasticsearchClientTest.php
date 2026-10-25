<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ElasticsearchClient;
use App\Service\ElasticsearchUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for the Elasticsearch client (spec 008). No real ES needed:
 * every HTTP call goes through MockHttpClient.
 */
final class ElasticsearchClientTest extends TestCase
{
    private string $passwordFile;
    private string $caFile;

    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->passwordFile = (string) tempnam(sys_get_temp_dir(), 'es-pass');
        $this->caFile = (string) tempnam(sys_get_temp_dir(), 'es-ca');
        file_put_contents($this->passwordFile, "s3cret\n");
        file_put_contents($this->caFile, "-----BEGIN CERTIFICATE-----\n");
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->passwordFile);
        @unlink($this->caFile);
    }

    public function testSearchCreatesIndexSeedsDocumentsAndReturnsHits(): void
    {
        $client = $this->client(static function (string $method, string $url): MockResponse {
            if ('HEAD' === $method) {
                return new MockResponse('', ['http_code' => 404]);
            }
            if ('PUT' === $method && str_ends_with($url, '/documents')) {
                return new MockResponse('{"acknowledged":true}');
            }
            if ('PUT' === $method) {
                return new MockResponse('{"result":"created"}', ['http_code' => 201]);
            }

            return new MockResponse(json_encode([
                'hits' => [
                    'total' => ['value' => 1, 'relation' => 'eq'],
                    'hits' => [[
                        '_id' => 'malina',
                        '_score' => 1.5,
                        '_source' => ['title' => 'malina', 'body' => 'A Raspberry Pi 4 running k3s.'],
                    ]],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $result = $client->search('malina');

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['hits']);
        self::assertSame('malina', $result['hits'][0]['title']);
        self::assertSame(1.5, $result['hits'][0]['score']);

        // 1x index template + 1x HEAD + 1x index creation + 4 seed documents + 1x search
        self::assertCount(8, $this->requests);
        self::assertSame('PUT', $this->requests[0]['method']);
        self::assertStringEndsWith('/_index_template/demo-documents', $this->requests[0]['url']);
        self::assertSame('HEAD', $this->requests[1]['method']);
        self::assertStringEndsWith('/documents/_search', $this->requests[7]['url']);
    }

    public function testSearchDoesNotReseedAnExistingIndex(): void
    {
        $client = $this->client(static function (string $method, string $url): MockResponse {
            if ('HEAD' === $method) {
                return new MockResponse('', ['http_code' => 200]);
            }
            if ('GET' === $method && str_contains($url, '_settings')) {
                return new MockResponse('{"documents":{"settings":{"index":{"number_of_replicas":"0"}}}}');
            }

            return new MockResponse('{"hits":{"total":{"value":0},"hits":[]}}');
        });

        $result = $client->search('nothing');

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['hits']);
        self::assertCount(4, $this->requests, 'existing index must not be seeded again');
        $puts = array_filter($this->requests, static fn (array $r): bool => 'PUT' === $r['method'] && str_contains($r['url'], '_settings'));
        self::assertSame([], $puts, 'a replica-free index needs no settings update');
    }

    public function testExistingIndexWithReplicasIsHealedToZeroReplicas(): void
    {
        $client = $this->client(static function (string $method, string $url): MockResponse {
            if ('HEAD' === $method) {
                return new MockResponse('', ['http_code' => 200]);
            }
            if ('GET' === $method && str_contains($url, '_settings')) {
                return new MockResponse('{"documents":{"settings":{"index":{"number_of_replicas":"1"}}}}');
            }
            if ('PUT' === $method) {
                return new MockResponse('{"acknowledged":true}');
            }

            return new MockResponse('{"hits":{"total":{"value":0},"hits":[]}}');
        });

        $client->search('nothing');

        $settingsPuts = array_values(array_filter(
            $this->requests,
            static fn (array $r): bool => 'PUT' === $r['method'] && str_ends_with($r['url'], '/documents/_settings'),
        ));
        self::assertCount(1, $settingsPuts, 'single-node cluster must run replica-free');
    }

    public function testUnconfiguredClientRefusesToCallElasticsearch(): void
    {
        $client = $this->client(
            static fn (): MockResponse => new MockResponse(''),
            url: '',
        );

        $this->expectException(ElasticsearchUnavailable::class);
        $client->search('malina');
    }

    public function testHttpErrorIsTranslatedToUnavailableException(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]));

        $this->expectException(ElasticsearchUnavailable::class);
        $this->expectExceptionMessage('HTTP 401');
        $client->search('malina');
    }

    public function testInfoReturnsClusterPayload(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse(
            '{"cluster_name":"demo","version":{"number":"9.5.5"}}',
        ));

        $info = $client->info();

        self::assertSame('demo', $info['cluster_name']);
        self::assertSame('9.5.5', $info['version']['number']);
    }

    /**
     * @param callable(string, string): MockResponse $handler
     */
    private function client(callable $handler, string $url = 'https://demo-es-http:9200'): ElasticsearchClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($handler): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url];

            return $handler($method, $url);
        });

        return new ElasticsearchClient($httpClient, $url, 'elastic', $this->passwordFile, $this->caFile);
    }
}
