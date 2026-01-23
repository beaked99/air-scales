<?php

namespace App\Controller\Api;

use App\Entity\Firmware;
use App\Repository\FirmwareRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/firmware', name: 'api_firmware_')]
class FirmwareController extends AbstractController
{
    public function __construct(
        private FirmwareRepository $firmwareRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir
    ) {}

    /**
     * Check for firmware updates
     *
     * POST /api/firmware/check
     * {
     *   "current_version": "1.0.0",
     *   "device_type": "ESP32",
     *   "mac_address": "AA:BB:CC:DD:EE:FF"
     * }
     */
    #[Route('/check', name: 'check', methods: ['POST'])]
    public function checkForUpdate(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }

            $currentVersion = $data['current_version'] ?? null;
            $deviceType = $data['device_type'] ?? null;
            $macAddress = $data['mac_address'] ?? null;

            if (!$currentVersion) {
                return new JsonResponse(['error' => 'current_version is required'], 400);
            }

            $this->logger->info('Firmware update check', [
                'current_version' => $currentVersion,
                'device_type' => $deviceType,
                'mac_address' => $macAddress
            ]);

            // Find available update
            $availableUpdate = $this->firmwareRepository->findUpdateFor($currentVersion, $deviceType);

            if (!$availableUpdate) {
                return new JsonResponse([
                    'update_available' => false,
                    'current_version' => $currentVersion,
                    'message' => 'You are running the latest firmware'
                ]);
            }

            return new JsonResponse([
                'update_available' => true,
                'current_version' => $currentVersion,
                'latest_version' => $availableUpdate->getVersion(),
                'changelog' => $availableUpdate->getChangelog(),
                'file_size' => $availableUpdate->getFileSize(),
                'file_size_formatted' => $availableUpdate->getFileSizeFormatted(),
                'checksum' => $availableUpdate->getChecksum(),
                'released_at' => $availableUpdate->getReleasedAt()?->format('Y-m-d H:i:s'),
                'download_url' => $this->generateUrl('api_firmware_download', [
                    'id' => $availableUpdate->getId()
                ], 0) // 0 = absolute URL
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Firmware check failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Check failed'], 500);
        }
    }

    /**
     * Get latest stable firmware info
     *
     * GET /api/firmware/latest?device_type=ESP32
     */
    #[Route('/latest', name: 'latest', methods: ['GET'])]
    public function getLatest(Request $request): JsonResponse
    {
        $deviceType = $request->query->get('device_type');

        $latest = $this->firmwareRepository->findLatestStable($deviceType);

        if (!$latest) {
            return new JsonResponse([
                'error' => 'No firmware available',
                'device_type' => $deviceType
            ], 404);
        }

        return new JsonResponse([
            'version' => $latest->getVersion(),
            'device_type' => $latest->getDeviceType(),
            'changelog' => $latest->getChangelog(),
            'file_size' => $latest->getFileSize(),
            'file_size_formatted' => $latest->getFileSizeFormatted(),
            'checksum' => $latest->getChecksum(),
            'released_at' => $latest->getReleasedAt()?->format('Y-m-d H:i:s'),
            'download_url' => $this->generateUrl('api_firmware_download', [
                'id' => $latest->getId()
            ], 0)
        ]);
    }

    /**
     * List all available firmware versions
     *
     * GET /api/firmware/list?device_type=ESP32
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function listVersions(Request $request): JsonResponse
    {
        $deviceType = $request->query->get('device_type');

        $versions = $this->firmwareRepository->findAvailableVersions($deviceType);

        $result = [];
        foreach ($versions as $firmware) {
            $result[] = [
                'id' => $firmware->getId(),
                'version' => $firmware->getVersion(),
                'device_type' => $firmware->getDeviceType(),
                'is_stable' => $firmware->isStable(),
                'changelog' => $firmware->getChangelog(),
                'file_size_formatted' => $firmware->getFileSizeFormatted(),
                'released_at' => $firmware->getReleasedAt()?->format('Y-m-d H:i:s'),
                'download_count' => $firmware->getDownloadCount()
            ];
        }

        return new JsonResponse([
            'count' => count($result),
            'versions' => $result
        ]);
    }

    /**
     * Download firmware binary
     *
     * GET /api/firmware/download/{id}
     *
     * For BLE OTA: The phone app downloads this and streams to ESP32
     */
    #[Route('/download/{id}', name: 'download', methods: ['GET'])]
    public function download(int $id): Response
    {
        $firmware = $this->firmwareRepository->find($id);

        if (!$firmware) {
            return new JsonResponse(['error' => 'Firmware not found'], 404);
        }

        if ($firmware->isDeprecated()) {
            return new JsonResponse(['error' => 'This firmware version has been deprecated'], 410);
        }

        $filePath = $this->projectDir . '/public/' . $firmware->getFilePath();

        if (!file_exists($filePath)) {
            $this->logger->error('Firmware file not found', [
                'id' => $id,
                'path' => $filePath
            ]);
            return new JsonResponse(['error' => 'Firmware file not found'], 404);
        }

        // Increment download count
        $firmware->incrementDownloadCount();
        $this->em->flush();

        $this->logger->info('Firmware download started', [
            'id' => $id,
            'version' => $firmware->getVersion(),
            'download_count' => $firmware->getDownloadCount()
        ]);

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $firmware->getFilename()
        );
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Firmware-Version', $firmware->getVersion());
        $response->headers->set('X-Firmware-Checksum', $firmware->getChecksum());

        return $response;
    }

    /**
     * Get firmware info by ID (for pre-download verification)
     *
     * GET /api/firmware/{id}
     */
    #[Route('/{id}', name: 'info', methods: ['GET'])]
    public function getInfo(int $id): JsonResponse
    {
        $firmware = $this->firmwareRepository->find($id);

        if (!$firmware) {
            return new JsonResponse(['error' => 'Firmware not found'], 404);
        }

        return new JsonResponse([
            'id' => $firmware->getId(),
            'version' => $firmware->getVersion(),
            'device_type' => $firmware->getDeviceType(),
            'is_stable' => $firmware->isStable(),
            'is_deprecated' => $firmware->isDeprecated(),
            'changelog' => $firmware->getChangelog(),
            'file_size' => $firmware->getFileSize(),
            'file_size_formatted' => $firmware->getFileSizeFormatted(),
            'checksum' => $firmware->getChecksum(),
            'minimum_previous_version' => $firmware->getMinimumPreviousVersion(),
            'released_at' => $firmware->getReleasedAt()?->format('Y-m-d H:i:s'),
            'download_count' => $firmware->getDownloadCount(),
            'download_url' => $this->generateUrl('api_firmware_download', ['id' => $id], 0)
        ]);
    }
}
