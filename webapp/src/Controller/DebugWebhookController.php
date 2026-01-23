<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class DebugWebhookController extends AbstractController
{
    #[Route('/debug/webhook-config', name: 'debug_webhook_config', methods: ['GET'])]
    public function debugWebhookConfig(): JsonResponse
    {
        $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null;

        return new JsonResponse([
            'webhook_secret_exists' => !empty($webhookSecret),
            'webhook_secret_prefix' => $webhookSecret ? substr($webhookSecret, 0, 10) : 'NOT SET',
            'env_loaded' => !empty($_ENV),
            'stripe_secret_key_exists' => !empty($_ENV['STRIPE_SECRET_KEY'] ?? null),
        ]);
    }
}
