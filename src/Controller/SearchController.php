<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ElasticsearchClient;
use App\Service\ElasticsearchUnavailable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Search demo backed by Elasticsearch (spec 008).
 *
 * Both endpoints degrade gracefully: when ES (or its Secrets) is not
 * available the visitor gets a friendly message / HTTP 503, never a stack
 * trace, and the start page is unaffected.
 */
final class SearchController extends AbstractController
{
    public function __construct(private readonly ElasticsearchClient $elasticsearch)
    {
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $hits = [];
        $total = 0;
        $error = null;

        if ('' !== $query) {
            try {
                $result = $this->elasticsearch->search($query);
                $hits = $result['hits'];
                $total = $result['total'];
            } catch (ElasticsearchUnavailable $e) {
                $error = 'Search is temporarily unavailable. Please try again in a moment.';
            }
        }

        return $this->render('search.html.twig', [
            'site' => str_contains($request->getHost(), 'bibop') ? 'bibop' : 'kreciowo',
            'domain' => str_contains($request->getHost(), 'bibop') ? 'bibop.pl' : 'kreciowo.pl',
            'query' => $query,
            'hits' => $hits,
            'total' => $total,
            'error' => $error,
        ]);
    }

    /**
     * JSON status of the backing cluster — handy for demos and smoke checks.
     */
    #[Route('/api/elastic', name: 'api_elastic', methods: ['GET'])]
    public function status(): JsonResponse
    {
        if (!$this->elasticsearch->isConfigured()) {
            return $this->json([
                'status' => 'unavailable',
                'error' => 'Elasticsearch is not configured yet.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $info = $this->elasticsearch->info();
        } catch (ElasticsearchUnavailable $e) {
            return $this->json([
                'status' => 'unavailable',
                'error' => 'Elasticsearch is unreachable.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'status' => 'ok',
            'cluster' => $info['cluster_name'] ?? 'unknown',
            'version' => $info['version']['number'] ?? 'unknown',
        ]);
    }
}
