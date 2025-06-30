<?php

// src/Controller/MaintenanceController.php
namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class MaintenanceController extends AbstractController
{
    #[Route('/maintenance/cleanup/microdata', name: 'cleanup_microdata')]
    public function cleanupMicroData(Connection $connection): JsonResponse
    {
        $query = "
            DELETE FROM micro_data
            WHERE timestamp < NOW() - INTERVAL 30 DAY
              AND id NOT IN (
                SELECT id FROM (
                  SELECT MIN(id) as id
                  FROM micro_data
                  WHERE timestamp < NOW() - INTERVAL 30 DAY
                  GROUP BY DATE(timestamp), HOUR(timestamp), device_id
                ) as subquery
              );
        ";

        $connection->executeStatement($query);

        return new JsonResponse(['status' => 'done']);
    }
}
