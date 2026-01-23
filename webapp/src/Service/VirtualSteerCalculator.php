<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\Calibration;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for calculating virtual steer axle weights
 * Uses linear regression to learn the relationship between drive axle load and steer axle weight
 */
class VirtualSteerCalculator
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Calculate virtual steer weight based on current drive axle weight
     *
     * Formula: SteerWeight = Intercept + (Coeff × DriveWeight)
     * Where Intercept = empty steer weight, Coeff represents kingpin distance effect
     */
    public function calculateSteerWeight(Device $device, float $driveWeight): ?float
    {
        if (!$device->hasVirtualSteer() || !$device->getVirtualSteerIntercept() || !$device->getVirtualSteerCoeff()) {
            return null;
        }

        $steerWeight = $device->getVirtualSteerIntercept() + ($device->getVirtualSteerCoeff() * $driveWeight);

        // Sanity check: steer weight should be positive and reasonable
        if ($steerWeight < 0 || $steerWeight > 30000) {
            return null;
        }

        return $steerWeight;
    }

    /**
     * Learn regression coefficients from calibration sessions for this device
     * Returns true if successful, false if not enough data
     */
    public function learnFromCalibrations(Device $device): bool
    {
        if (!$device->hasVirtualSteer() || !$device->getWheelbase()) {
            return false;
        }

        // Get all calibrations for this device
        $calibrations = $this->em->getRepository(Calibration::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.calibrationSession', 's')
            ->addSelect('s')
            ->where('c.device = :device')
            ->setParameter('device', $device)
            ->orderBy('c.created_at', 'DESC')
            ->getQuery()
            ->getResult();

        // Group calibrations by session
        $sessions = [];
        foreach ($calibrations as $calibration) {
            $session = $calibration->getCalibrationSession();
            $sessionKey = $session ? $session->getId() : ($calibration->getTicketNumber() ?: $calibration->getCreatedAt()->format('Y-m-d H:i'));

            if (!isset($sessions[$sessionKey])) {
                $sessions[$sessionKey] = [
                    'steer' => 0,
                    'drive' => 0,
                    'session' => $session,
                ];
            }

            $channel = $calibration->getDeviceChannel();
            $weight = $calibration->getScaleWeight() ?: 0;

            if ($channel) {
                $axleGroup = $channel->getAxleGroup();

                // If channel has explicit 'steer' axle group, add to steer weight
                if ($axleGroup && $axleGroup->getName() === 'steer') {
                    $sessions[$sessionKey]['steer'] += $weight;
                } else {
                    // All other channels (including those without axle groups) are drive axles
                    $sessions[$sessionKey]['drive'] += $weight;
                }
            } else {
                // No channel - this is a drive axle calibration, accumulate drive weight
                $sessions[$sessionKey]['drive'] += $weight;
            }
        }

        // Extract virtual steer weights from session notes
        foreach ($sessions as $sessionKey => &$sessionData) {
            if ($sessionData['session']) {
                $notes = $sessionData['session']->getNotes();
                if ($notes && preg_match('/\[VIRTUAL_STEER_WEIGHT:(\d+(?:\.\d+)?)\]/', $notes, $matches)) {
                    $sessionData['steer'] = (float)$matches[1];
                }
            }
        }

        // Build data points from complete sessions
        $dataPoints = [];
        foreach ($sessions as $sessionData) {
            if ($sessionData['steer'] > 0 && $sessionData['drive'] > 0) {
                $dataPoints[] = [
                    'drive' => $sessionData['drive'],
                    'steer' => $sessionData['steer'],
                ];
            }
        }

        // Need at least 3 data points for reliable regression
        if (\count($dataPoints) < 3) {
            return false;
        }

        // Perform linear regression: steer = intercept + (coeff × drive)
        $regression = $this->linearRegression($dataPoints);

        if ($regression) {
            $device->setVirtualSteerIntercept($regression['intercept']);
            $device->setVirtualSteerCoeff($regression['slope']);
            $this->em->flush();
            return true;
        }

        return false;
    }

    /**
     * Perform simple linear regression
     * Returns ['slope' => m, 'intercept' => b] for y = mx + b
     */
    private function linearRegression(array $dataPoints): ?array
    {
        $n = count($dataPoints);
        if ($n < 2) {
            return null;
        }

        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        foreach ($dataPoints as $point) {
            $x = $point['drive'];
            $y = $point['steer'];

            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = ($n * $sumX2) - ($sumX * $sumX);

        if ($denominator == 0) {
            return null; // Cannot divide by zero
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => $this->calculateRSquared($dataPoints, $slope, $intercept),
        ];
    }

    /**
     * Calculate R² (coefficient of determination) to assess fit quality
     */
    private function calculateRSquared(array $dataPoints, float $slope, float $intercept): float
    {
        $n = count($dataPoints);
        if ($n == 0) return 0;

        // Calculate mean of observed values
        $meanY = array_sum(array_column($dataPoints, 'steer')) / $n;

        $ssTotal = 0; // Total sum of squares
        $ssResidual = 0; // Residual sum of squares

        foreach ($dataPoints as $point) {
            $x = $point['drive'];
            $yObserved = $point['steer'];
            $yPredicted = $intercept + ($slope * $x);

            $ssTotal += pow($yObserved - $meanY, 2);
            $ssResidual += pow($yObserved - $yPredicted, 2);
        }

        if ($ssTotal == 0) return 0;

        return 1 - ($ssResidual / $ssTotal);
    }

    /**
     * Get the current regression statistics for display
     */
    public function getRegressionStats(Device $device): ?array
    {
        if (!$device->hasVirtualSteer() || !$device->getVirtualSteerIntercept()) {
            return null;
        }

        // Count calibration data points
        $calibrations = $this->em->getRepository(Calibration::class)
            ->createQueryBuilder('c')
            ->where('c.device = :device')
            ->setParameter('device', $device)
            ->getQuery()
            ->getResult();

        // Group by session
        $sessions = [];
        foreach ($calibrations as $calibration) {
            $key = $calibration->getTicketNumber() ?: $calibration->getCreatedAt()->format('Y-m-d H:i');
            if (!isset($sessions[$key])) {
                $sessions[$key] = ['steer' => false, 'drive' => false];
            }

            $channel = $calibration->getDeviceChannel();
            if (!$channel) continue;

            $axleGroup = $channel->getAxleGroup();
            if (!$axleGroup) continue;

            if ($axleGroup->getName() === 'steer') {
                $sessions[$key]['steer'] = true;
            } elseif ($axleGroup->getName() === 'drive') {
                $sessions[$key]['drive'] = true;
            }
        }

        $dataPointCount = 0;
        foreach ($sessions as $session) {
            if ($session['steer'] && $session['drive']) {
                $dataPointCount++;
            }
        }

        return [
            'intercept' => $device->getVirtualSteerIntercept(),
            'coefficient' => $device->getVirtualSteerCoeff(),
            'wheelbase' => $device->getWheelbase(),
            'kingpin_distance' => $device->getKingpinDistance(),
            'data_points' => $dataPointCount,
            'is_trained' => $dataPointCount >= 3,
        ];
    }
}
