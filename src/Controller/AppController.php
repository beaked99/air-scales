<?php

// src/Controller/AppController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AppController extends AbstractController
{
    #[Route('/app', name: 'pwa_app')]
    public function index(): Response
    {
        return $this->render('app/index.html.twig');
    }
}
