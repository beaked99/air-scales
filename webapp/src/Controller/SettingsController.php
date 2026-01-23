<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;

#[IsGranted('ROLE_USER')]
class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings')]
    public function index(): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('settings/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/api/settings/pressure-unit', name: 'settings_pressure_unit', methods: ['POST'])]
    public function setPressureUnit(
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['unit']) || !in_array($data['unit'], ['psi', 'kpa'])) {
            return new JsonResponse(['error' => 'Invalid unit. Must be "psi" or "kpa"'], 400);
        }

        $user->setPressureUnit($data['unit']);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'pressure_unit' => $user->getPressureUnit()
        ]);
    }

    #[Route('/api/settings/weight-unit', name: 'settings_weight_unit', methods: ['POST'])]
    public function setWeightUnit(
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['unit']) || !in_array($data['unit'], ['lbs', 'kg'])) {
            return new JsonResponse(['error' => 'Invalid unit. Must be "lbs" or "kg"'], 400);
        }

        $user->setWeightUnit($data['unit']);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'weight_unit' => $user->getWeightUnit()
        ]);
    }

    #[Route('/api/settings/all', name: 'settings_get_all', methods: ['GET'])]
    public function getAllSettings(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        return new JsonResponse([
            'pressure_unit' => $user->getPressureUnit(),
            'weight_unit' => $user->getWeightUnit(),
            'ble_auto_switch' => $user->getBleAutoSwitch()
        ]);
    }
}
