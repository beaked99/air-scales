<?php
// src/Controller/DeviceSetupController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class DeviceSetupController extends AbstractController
{
    #[Route('/setup-device', name: 'setup_device')]
    public function setupDevice(Request $request): Response
    {
        // Check if this is coming from PWA (needs to exit PWA mode)
        $exitPwa = $request->query->get('exit_pwa') === 'true';
        
        return $this->render('setup/device_setup.html.twig', [
            'exit_pwa' => $exitPwa,
            'return_to_pwa' => $request->headers->get('referer')
        ]);
    }
    
    #[Route('/setup-device/configure', name: 'configure_device', methods: ['POST'])]
    public function configureDevice(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        
        // Validate the ESP32 configuration data
        $ssid = $data['ssid'] ?? '';
        $password = $data['password'] ?? '';
        $deviceId = $data['device_id'] ?? '';
        
        if (empty($ssid) || empty($deviceId)) {
            return $this->json(['error' => 'SSID and Device ID are required'], 400);
        }
        
        // Store device configuration in database
        // You'll need to create a Device entity for this
        
        return $this->json([
            'success' => true,
            'message' => 'Device configured successfully',
            'device_id' => $deviceId,
            'next_step' => 'connect_to_wifi'
        ]);
    }
    
    #[Route('/setup-device/qr-config', name: 'qr_config')]
    public function generateQRConfig(Request $request): Response
    {
        $deviceId = $request->query->get('device_id');
        $ssid = $request->query->get('ssid');
        
        // Generate QR code configuration
        $config = [
            'ssid' => $ssid,
            'device_id' => $deviceId,
            'server_url' => 'https://beaker.ca/api/microdata',
            'setup_token' => bin2hex(random_bytes(16))
        ];
        
        // Store setup token temporarily
        $this->storeSetupToken($config['setup_token'], $deviceId);
        
        return $this->json([
            'qr_data' => base64_encode(json_encode($config)),
            'config' => $config
        ]);
    }
    
    private function storeSetupToken(string $token, string $deviceId): void
    {
        // Store in Redis or database with expiration
        // This is a placeholder implementation
    }
}