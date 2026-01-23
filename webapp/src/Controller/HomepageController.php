<?php

namespace App\Controller;

use App\Entity\Faq;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomepageController extends AbstractController
{
    #[Route('/', name: 'app_homepage')]
    public function index(): Response
    {
        // Redirect logged-in users to their main dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Show marketing homepage for non-authenticated users
        return $this->render('homepage/index.html.twig', [
            'controller_name' => 'HomepageController',
        ]);
    }

    #[Route('/product', name: 'product_details')]
    public function product(): Response
    {
        return $this->render('homepage/product.html.twig', [
            'controller_name' => 'HomepageController',
        ]);
    }

    #[Route('/terms', name: 'terms_of_service')]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }

    #[Route('/privacy', name: 'privacy_policy')]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    #[Route('/faq', name: 'faq')]
    public function faq(EntityManagerInterface $entityManager): Response
    {
        // Get all active FAQs, ordered by category and sort order
        $faqs = $entityManager->getRepository(Faq::class)->findBy(
            ['isActive' => true],
            ['category' => 'ASC', 'sortOrder' => 'ASC']
        );

        // Group FAQs by category
        $faqsByCategory = [];
        foreach ($faqs as $faq) {
            $faqsByCategory[$faq->getCategory()][] = $faq;
        }

        return $this->render('support/faq.html.twig', [
            'faqsByCategory' => $faqsByCategory
        ]);
    }
}
