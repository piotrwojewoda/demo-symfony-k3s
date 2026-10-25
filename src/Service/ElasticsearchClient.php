<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal Elasticsearch client (spec 008).
 *
 * KISS on purpose: plain HTTP + basic auth instead of the official PHP
 * client, so the image stays small and ARM64-friendly.
 *
 * Settings come from environment (see charts/demo-symfony/templates/
 * deployment.yaml):
 *   ELASTICSEARCH_URL            https://demo-es-http:9200
 *   ELASTICSEARCH_USER           elastic
 *   ELASTICSEARCH_PASSWORD_FILE  path of the ECK-generated password file
 *   ELASTICSEARCH_CA             path of the ECK-generated CA certificate
 *
 * The password and the CA are mounted from Kubernetes Secrets — never in
 * git and never in the image (Constitution III). Both are read at request
 * time, so a rotated Secret is picked up without a pod restart.
 */
final class ElasticsearchClient
{
    private const INDEX = 'documents';

    /**
     * Demo documents seeded on the first search. Stable IDs make the seed
     * idempotent — after a rebuild the index is simply recreated.
     *
     * @var array<string, array{title: string, body: string}>
     */
    private const SEED_DOCUMENTS = [
        'malina' => [
            'title' => 'malina',
            'body' => 'A Raspberry Pi 4 running k3s: Jenkins, ArgoCD, GitOps and a demo Symfony application on a single node.',
        ],
        'kreciowo' => [
            'title' => 'kreciowo.pl',
            'body' => 'The first demo domain. A little mole with a house lives on the start page.',
        ],
        'bibop' => [
            'title' => 'bibop.pl',
            'body' => 'The second demo domain. Home of a weird creature with three eyes and antennae.',
        ],
        'elasticsearch' => [
            'title' => 'Elasticsearch (ECK)',
            'body' => 'A single-node Elasticsearch 9.5.5 cluster managed by the Elastic Cloud on Kubernetes operator, sized for 8 GB of RAM.',
        ],
    ];

    private readonly string $url;
    private readonly string $user;
    private readonly string $passwordFile;
    private readonly string $caFile;

    /**
     * Missing environment variables arrive as null — normalize to "" so the
     * client simply reports itself as not configured.
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::ELASTICSEARCH_URL)%')] ?string $url = null,
        #[Autowire('%env(default::ELASTICSEARCH_USER)%')] ?string $user = null,
        #[Autowire('%env(default::ELASTICSEARCH_PASSWORD_FILE)%')] ?string $passwordFile = null,
        #[Autowire('%env(default::ELASTICSEARCH_CA)%')] ?string $caFile = null,
    ) {
        $this->url = $url ?? '';
        $this->user = $user ?? '';
        $this->passwordFile = $passwordFile ?? '';
        $this->caFile = $caFile ?? '';
    }

    /**
     * True when the connection settings are present AND both mounted
     * Secrets are readable (they appear only once ECK has created them).
     */
    public function isConfigured(): bool
    {
        return '' !== $this->url
            && is_readable($this->passwordFile)
            && is_readable($this->caFile);
    }

    /**
     * Cluster information (GET /), used by /api/elastic.
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return $this->request('GET', '/');
    }

    /**
     * Full-text search over the demo documents.
     *
     * @return array{total: int, hits: list<array{title: string, body: string, score: float}>}
     */
    public function search(string $query, int $limit = 20): array
    {
        $this->ensureIndex();

        $response = $this->request('GET', '/'.self::INDEX.'/_search', [
            'json' => [
                'size' => $limit,
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => ['title^2', 'body'],
                        'fuzziness' => 'AUTO',
                    ],
                ],
            ],
        ]);

        $hits = $response['hits'] ?? [];
        $total = $hits['total'] ?? 0;

        return [
            'total' => is_array($total) ? (int) ($total['value'] ?? 0) : (int) $total,
            'hits' => array_map(
                static fn (array $hit): array => [
                    'title' => (string) ($hit['_source']['title'] ?? $hit['_id']),
                    'body' => (string) ($hit['_source']['body'] ?? ''),
                    'score' => (float) ($hit['_score'] ?? 0),
                ],
                $hits['hits'] ?? [],
            ),
        ];
    }

    /**
     * Creates the index + seed documents when they do not exist yet, and
     * keeps the single-node cluster green.
     *
     * A replica can never be assigned on a one-node cluster, so a default
     * `number_of_replicas: 1` would leave one unassigned shard -> cluster
     * health `yellow` forever (ArgoCD shows the ES app as Progressing).
     * The index template makes every `documents*` index replica-free.
     */
    private function ensureIndex(): void
    {
        $this->request('PUT', '/_index_template/demo-documents', [
            'json' => [
                'index_patterns' => ['documents*'],
                'template' => ['settings' => ['number_of_replicas' => 0]],
            ],
        ]);

        if (!$this->indexExists()) {
            $this->request('PUT', '/'.self::INDEX, [
                'json' => [
                    'settings' => ['number_of_replicas' => 0],
                    'mappings' => [
                        'properties' => [
                            'title' => ['type' => 'text'],
                            'body' => ['type' => 'text'],
                        ],
                    ],
                ],
            ]);

            foreach (self::SEED_DOCUMENTS as $id => $document) {
                $this->request('PUT', '/'.self::INDEX.'/_doc/'.$id.'?refresh=true', [
                    'json' => $document,
                ]);
            }

            return;
        }

        $this->ensureZeroReplicas();
    }

    /**
     * Heals an index that was created before the template existed (e.g. by
     * an older image): flips `number_of_replicas` to 0 exactly once.
     */
    private function ensureZeroReplicas(): void
    {
        $settings = $this->request('GET', '/'.self::INDEX.'/_settings/index.number_of_replicas');
        $replicas = (string) ($settings[self::INDEX]['settings']['index']['number_of_replicas'] ?? '0');

        if ('0' === $replicas) {
            return;
        }

        $this->request('PUT', '/'.self::INDEX.'/_settings', [
            'json' => ['index' => ['number_of_replicas' => 0]],
        ]);
    }

    private function indexExists(): bool
    {
        $this->assertConfigured();

        try {
            $status = $this->httpClient
                ->request('HEAD', $this->endpoint('/'.self::INDEX), $this->options())
                ->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new ElasticsearchUnavailable('Elasticsearch is unreachable: '.$e->getMessage(), 0, $e);
        }

        if (404 === $status) {
            return false;
        }
        if ($status >= 400) {
            throw new ElasticsearchUnavailable(sprintf('Elasticsearch returned HTTP %d', $status));
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $this->assertConfigured();

        try {
            $response = $this->httpClient->request($method, $this->endpoint($path), $options + $this->options());
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new ElasticsearchUnavailable(sprintf('Elasticsearch returned HTTP %d', $status));
            }

            return $response->toArray(false);
        } catch (ElasticsearchUnavailable $e) {
            throw $e;
        } catch (ExceptionInterface $e) {
            throw new ElasticsearchUnavailable('Elasticsearch is unreachable: '.$e->getMessage(), 0, $e);
        }
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new ElasticsearchUnavailable('Elasticsearch is not configured yet (waiting for the ECK Secrets).');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $password = trim((string) @file_get_contents($this->passwordFile));

        return [
            'auth_basic' => ('' !== $this->user ? $this->user : 'elastic').':'.$password,
            'verify_peer' => true,
            'cafile' => $this->caFile,
            'timeout' => 5,
            'max_duration' => 10,
        ];
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->url, '/').$path;
    }
}
