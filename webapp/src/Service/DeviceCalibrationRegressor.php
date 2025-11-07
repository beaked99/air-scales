<?php

namespace App\Service;

use App\Entity\Calibration;
use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;

class DeviceCalibrationRegressor
{
    public function __construct(private EntityManagerInterface $em) {}

    public function run(Device $device): bool
    {
        $calibrations = $device->getCalibrations()->toArray();

        if (count($calibrations) < 2) {
            return false; // not enough data
        }

        $X = [];
        $y = [];

        foreach ($calibrations as $cal) {
            $X[] = [
                $cal->getAirPressure(),
                $cal->getAmbientAirPressure(),
                $cal->getAirTemperature(),
            ];
            $y[] = $cal->getScaleWeight();
        }

        $n = count($X);
        $xT = $this->transpose($X);
        $xTx = $this->multiply($xT, $X);
        $xTy = $this->multiply($xT, array_map(fn($v) => [$v], $y));
        $xTxInv = $this->invertMatrix($xTx);
        $beta = $this->multiply($xTxInv, $xTy);

        $coeffs = array_column($beta, 0);
        [$b1, $b2, $b3] = $coeffs;
        $intercept = $this->mean($y) - $b1 * $this->mean(array_column($X, 0)) - $b2 * $this->mean(array_column($X, 1)) - $b3 * $this->mean(array_column($X, 2));

        // Predict & evaluate
        $yPred = array_map(function ($x) use ($b1, $b2, $b3, $intercept) {
            return $intercept + $x[0] * $b1 + $x[1] * $b2 + $x[2] * $b3;
        }, $X);

        $rsq = $this->rSquared($y, $yPred);
        $rmse = $this->rmse($y, $yPred);

        // Save to device
        $device->setRegressionIntercept($intercept);
        $device->setRegressionAirPressureCoeff($b1);
        $device->setRegressionAmbientPressureCoeff($b2);
        $device->setRegressionAirTempCoeff($b3);
        $device->setRegressionRsq($rsq);
        $device->setRegressionRmse($rmse);

        $this->em->persist($device);
        $this->em->flush();

        return true;
    }

    private function mean(array $arr): float
    {
        return array_sum($arr) / count($arr);
    }

    private function rSquared(array $actual, array $predicted): float
    {
        $mean = $this->mean($actual);
        $ssTot = array_sum(array_map(fn($a) => pow($a - $mean, 2), $actual));
        $ssRes = array_sum(array_map(fn($a, $p) => pow($a - $p, 2), $actual, $predicted));
        return 1 - $ssRes / $ssTot;
    }

    private function rmse(array $actual, array $predicted): float
    {
        $sum = 0;
        foreach ($actual as $i => $val) {
            $sum += pow($val - $predicted[$i], 2);
        }
        return sqrt($sum / count($actual));
    }

    // Linear algebra helpers (basic matrix ops)

    private function transpose(array $matrix): array
    {
        return array_map(null, ...$matrix);
    }

    private function multiply(array $A, array $B): array
    {
        $result = [];
        $rowsA = count($A);
        $colsA = count($A[0]);
        $colsB = count($B[0]);

        for ($i = 0; $i < $rowsA; $i++) {
            for ($j = 0; $j < $colsB; $j++) {
                $result[$i][$j] = 0;
                for ($k = 0; $k < $colsA; $k++) {
                    $result[$i][$j] += $A[$i][$k] * $B[$k][$j];
                }
            }
        }

        return $result;
    }

    private function invertMatrix(array $matrix): array
    {
        // Very basic matrix inversion for 3x3 only (safe since we only do 3 features)
        $det = $matrix[0][0] * ($matrix[1][1]*$matrix[2][2] - $matrix[1][2]*$matrix[2][1]) -
               $matrix[0][1] * ($matrix[1][0]*$matrix[2][2] - $matrix[1][2]*$matrix[2][0]) +
               $matrix[0][2] * ($matrix[1][0]*$matrix[2][1] - $matrix[1][1]*$matrix[2][0]);

        if (abs($det) < 1e-10) {
            throw new \RuntimeException("Matrix is not invertible");
        }

        $invDet = 1 / $det;
        $m = $matrix;

        return [
            [
                ($m[1][1]*$m[2][2] - $m[1][2]*$m[2][1]) * $invDet,
                ($m[0][2]*$m[2][1] - $m[0][1]*$m[2][2]) * $invDet,
                ($m[0][1]*$m[1][2] - $m[0][2]*$m[1][1]) * $invDet
            ],
            [
                ($m[1][2]*$m[2][0] - $m[1][0]*$m[2][2]) * $invDet,
                ($m[0][0]*$m[2][2] - $m[0][2]*$m[2][0]) * $invDet,
                ($m[0][2]*$m[1][0] - $m[0][0]*$m[1][2]) * $invDet
            ],
            [
                ($m[1][0]*$m[2][1] - $m[1][1]*$m[2][0]) * $invDet,
                ($m[0][1]*$m[2][0] - $m[0][0]*$m[2][1]) * $invDet,
                ($m[0][0]*$m[1][1] - $m[0][1]*$m[1][0]) * $invDet
            ]
        ];
    }
}
