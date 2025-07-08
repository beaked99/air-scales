<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AppController extends AbstractController
{
    #[Route('/app', name: 'app_index', methods: ['GET'])]
    public function index(): Response
    {
        $versionFile = __DIR__ . '/../../public/app/version.json';
        $versionData = [
            'version' => 'dev',
            'build_date' => (new \DateTime())->format('Y-m-d'),
        ];

        if (file_exists($versionFile)) {
            $json = file_get_contents($versionFile);
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $versionData = array_merge($versionData, $decoded);
            }
        }

        return $this->render('app/index.html.twig', [
            'app_version' => $versionData['version'],
            'build_date' => $versionData['build_date'],
        ]);
    }
    #[Route('/app/version.json', name: 'app_version_json', methods: ['GET'])]
    public function versionJson(): Response
    {
        $versionFile = __DIR__ . '/../../public/app/version.json';
        $versionData = [
            'version' => 'dev',
            'build_date' => (new \DateTime())->format('Y-m-d'),
        ];

        if (file_exists($versionFile)) {
            $json = file_get_contents($versionFile);
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $versionData = array_merge($versionData, $decoded);
            }
        }

        return $this->json($versionData);
    }
}
