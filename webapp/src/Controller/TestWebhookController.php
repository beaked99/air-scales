<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

class TestWebhookController extends AbstractController
{
    #[Route('/webhook/stripe/test', name: 'test_webhook', methods: ['POST', 'GET'])]
    public function test(Request $request, LoggerInterface $logger): Response
    {
        $logger->info('Test webhook called', [
            'method' => $request->getMethod(),
            'content' => $request->getContent(),
            'headers' => $request->headers->all()
        ]);

        return new Response('Test webhook received at ' . date('Y-m-d H:i:s'), 200);
    }
}
