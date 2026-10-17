<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * The start page. Nothing more, by design.
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Connection $connection): Response
    {
        try {
            $connection->fetchOne('SELECT 1');
            $database = 'connected';
        } catch (\Throwable) {
            $database = 'unavailable';
        }

        return $this->render('home.html.twig', [
            'symfony_version' => Kernel::VERSION,
            'php_version' => PHP_VERSION,
            'environment' => $this->getParameter('kernel.environment'),
            'app_version' => getenv('APP_VERSION') ?: 'dev',
            'database' => $database,
        ]);
    }

    /**
     * Lightweight probe endpoint used by Kubernetes liveness/readiness probes.
     */
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function healthz(): Response
    {
        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
