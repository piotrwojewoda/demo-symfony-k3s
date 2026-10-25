<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * The start page. Shows only the visited domain name and its mascot:
     * kreciowo.pl -> a little mole with a house; bibop.pl -> a weird creature.
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $site = str_contains($request->getHost(), 'bibop') ? 'bibop' : 'kreciowo';

        return $this->render('home.html.twig', [
            'site' => $site,
            'domain' => $site.'.pl',
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
