<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ManifestController
{
    #[Route('/pwa/manifest.json', name: 'pwa_manifest', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        return new JsonResponse([
            'name' => 'Air Scales',
            'short_name' => 'Scales',
            'start_url' => '/dashboard',
            'display' => 'standalone',
            'background_color' => '#000000',
            'theme_color' => '#000000',
            'icons' => [
                [
                    'src' => '/pwa/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => '/pwa/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json'
        ]);
    }
}
