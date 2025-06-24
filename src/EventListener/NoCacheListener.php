<?php

// src/EventListener/NoCacheListener.php
namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

class NoCacheListener
{
    public function onKernelResponse(ResponseEvent $event)
    {
        if ($event->isMainRequest()) {
            $response = $event->getResponse();
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }
    }
}
