<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\DeviceChannel;
use Doctrine\ORM\EntityManagerInterface;

class DeviceCalibrationRegressor
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Run regression for all channels on a device
     */
    public function run(Device $device): bool
    {
        $success = false;

        // Run regression for each channel separately
        foreach ($device->getDeviceChannels() as $channel) {
            if ($this->runForChannel($channel)) {
                $success = true;
            }
        }

        return $success;
    }

    /**
     * Run regression analysis for a specific channel
     */
    public function runForChannel(DeviceChannel $channel): bool
    {
        // Get all calibrations for this specific channel
        $calibrations = $this->em->getRepository(\App\Entity\Calibration::class)
            ->createQueryBuilder('c')
            ->where('c.deviceChannel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getResult();

        $rows = $this->extractValidRows($calibrations);

        $n = count($rows);
        if ($n < 1) {
            return false;
        }

        if ($n < 5) {
            return $this->runSimpleDifferentialCalibration($channel, $rows);
        }

        return $this->runDifferentialWithTemperature($channel, $rows);
    }

    /**
     * Extract and sanitize calibration rows.
     * Returns array of rows: ['w'=>float,'pb'=>float,'pa'=>float,'t'=>float,'pg'=>float]
     */
    private function extractValidRows(array $calibrations): array
    {
        $rows = [];

        foreach ($calibrations as $cal) {
            $w  = (float) $cal->getScaleWeight();
            $pb = (float) $cal->getAirPressure();          // bag abs
            $pa = (float) $cal->getAmbientAirPressure();   // ambient abs
            $t  = (float) $cal->getAirTemperature();

            // Derived gauge
            $pg = $pb - $pa;

            // Basic sanity filters:
            // - require positive weight (you can loosen if you want to allow "tare" points)
            // - require positive gauge pressure (bag > ambient) to avoid garbage points
            if ($w <= 0) continue;
            if ($pg <= 0.01) continue; // 0.01 psi deadband

            $rows[] = ['w'=>$w, 'pb'=>$pb, 'pa'=>$pa, 't'=>$t, 'pg'=>$pg];
        }

        return $rows;
    }

    /**
     * < 5 points: Force through zero on gauge pressure.
     * W = m * Pg
     */
    private function runSimpleDifferentialCalibration(DeviceChannel $channel, array $rows): bool
    {
        $scaleFactors = [];

        foreach ($rows as $r) {
            $pg = $r['pg'];
            if (abs($pg) < 1e-6) continue;
            $scaleFactors[] = $r['w'] / $pg;
        }

        if (!$scaleFactors) return false;

        $m = array_sum($scaleFactors) / count($scaleFactors);

        // Store as: W = 0 + m*Pbag + (-m)*Pamb + 0*T
        $channel->setRegressionIntercept(0.0);
        $channel->setRegressionAirPressureCoeff($m);
        $channel->setRegressionAmbientPressureCoeff(-$m);
        $channel->setRegressionAirTempCoeff(0.0);

        // These aren't truly "perfect"; set something honest.
        $channel->setRegressionRsq(null);
        $channel->setRegressionRmse(null);

        $this->em->persist($channel);
        $this->em->flush();

        return true;
    }

    /**
     * >= 5 points: Ridge regression on [1, Pg, (T - T0)]
     * W = b + m*Pg + c*dT
     *
     * Temperature term is:
     *  - shrunk with ridge (stronger at low N)
     *  - faded-in from N=5..20
     *  - clamped so max temp correction stays within gamma(N) of typical weight
     */
    private function runDifferentialWithTemperature(DeviceChannel $channel, array $rows): bool
    {
        $n = count($rows);

        $y = [];
        $pg = [];
        $t  = [];

        foreach ($rows as $r) {
            $y[]  = $r['w'];
            $pg[] = $r['pg'];
            $t[]  = $r['t'];
        }

        $t0 = $this->mean($t);

        // Build design matrix X with columns: [1, Pg, dT]
        $X = [];
        for ($i = 0; $i < $n; $i++) {
            $dT = $t[$i] - $t0;
            $X[] = [1.0, $pg[$i], $dT];
        }

        // Ridge penalties:
        // - no penalty on intercept
        // - light penalty on pressure slope (optional)
        // - strong penalty on temperature slope, decreasing with N
        $lambdaP = 0.0; // you can set small like 1e-6 if you want extra stability
        $lambdaT = $this->temperatureLambda($n); // strong at low N, relaxes later

        $beta = $this->ridgeSolve3($X, $y, $lambdaP, $lambdaT);
        [$b, $m, $c] = $beta;

        // Fade-in temperature coefficient from N=5..20
        $g = $this->tempRamp($n); // 0..1
        $c *= $g;

        // Clamp temperature effect to a small fraction of typical weight
        // maxEffect = |c| * max|dT|
        $maxAbsDT = $this->maxAbsDelta($t, $t0);
        if ($maxAbsDT > 0) {
            $typicalW = $this->meanAbs($y);
            $gamma = $this->tempMaxFraction($n); // e.g. up to 1%
            $allowed = $gamma * max($typicalW, 1.0);

            $maxEffect = abs($c) * $maxAbsDT;
            if ($maxEffect > $allowed && $maxEffect > 0) {
                $c *= ($allowed / $maxEffect);
            }
        } else {
            // No temperature variation in data -> temp term meaningless
            $c = 0.0;
        }

        // Compute fit metrics on training data (optional but useful)
        $pred = [];
        for ($i = 0; $i < $n; $i++) {
            $pred[] = $b + $m*$pg[$i] + $c*($t[$i]-$t0);
        }
        $rsq = $this->rSquaredSafe($y, $pred);
        $rmse = $this->rmse($y, $pred);

        // Store in your existing "bag/ambient/temp" form:
        // W = b + m*Pbag + (-m)*Pamb + c*(T - T0)
        //
        // BUT you only store raw temp coeff; prediction code must also subtract T0.
        // If your runtime prediction is W = b + m*Pbag + n*Pamb + c*T,
        // then bake T0 into intercept: b' = b - c*T0 and store temp as c.
        $bBaked = $b - $c*$t0;

        $channel->setRegressionIntercept($bBaked);
        $channel->setRegressionAirPressureCoeff($m);
        $channel->setRegressionAmbientPressureCoeff(-$m);
        $channel->setRegressionAirTempCoeff($c);
        $channel->setRegressionRsq($rsq);
        $channel->setRegressionRmse($rmse);

        $this->em->persist($channel);
        $this->em->flush();

        return true;
    }

    /**
     * Ridge solve for 3 params: (X^T X + diag([0,lambdaP,lambdaT]))^-1 X^T y
     * X is Nx3, y is Nx1.
     */
    private function ridgeSolve3(array $X, array $y, float $lambdaP, float $lambdaT): array
    {
        $Xt = $this->transpose($X);         // 3xN
        $XtX = $this->multiply($Xt, $X);    // 3x3
        $Xty = $this->multiply($Xt, array_map(fn($v)=>[$v], $y)); // 3x1

        // Add ridge to diagonal (except intercept)
        $XtX[1][1] += $lambdaP;
        $XtX[2][2] += $lambdaT;

        $inv = $this->invertMatrix3($XtX);
        $beta = $this->multiply($inv, $Xty); // 3x1

        return [$beta[0][0], $beta[1][0], $beta[2][0]];
    }

    private function temperatureLambda(int $n): float
    {
        // Strong at small N; relax as N grows.
        // Tune these numbers later based on real data.
        // Units are "lbs per degree" stabilization, not physical.
        $base = 1e4; // stronger -> temp shrinks harder
        $scale = max(5, min($n, 50));
        return $base * (20.0 / $scale); // at N=5 => 4x base, at N=20 => 1x base
    }

    private function tempRamp(int $n): float
    {
        // 0 below 5 points, ramps to 1 by 20 points
        if ($n < 5) return 0.0;
        if ($n >= 20) return 1.0;
        return ($n - 5) / 15.0;
    }

    private function tempMaxFraction(int $n): float
    {
        // Max fraction of typical weight temperature correction is allowed to contribute.
        // Example: 0% <5, 0.5% at 10, 1% at 20+ (your idea, but as a clamp).
        if ($n < 5) return 0.0;
        if ($n >= 20) return 0.01;
        // linear 0..1% from 5..20
        return 0.01 * (($n - 5) / 15.0);
    }

    private function mean(array $arr): float
    {
        return array_sum($arr) / max(1, count($arr));
    }

    private function meanAbs(array $arr): float
    {
        $s = 0.0;
        foreach ($arr as $v) $s += abs($v);
        return $s / max(1, count($arr));
    }

    private function maxAbsDelta(array $arr, float $center): float
    {
        $m = 0.0;
        foreach ($arr as $v) {
            $m = max($m, abs($v - $center));
        }
        return $m;
    }

    private function rSquaredSafe(array $actual, array $predicted): ?float
    {
        $mean = $this->mean($actual);
        $ssTot = 0.0;
        $ssRes = 0.0;

        foreach ($actual as $i => $a) {
            $ssTot += ($a - $mean) * ($a - $mean);
            $e = $a - $predicted[$i];
            $ssRes += $e * $e;
        }

        if ($ssTot < 1e-9) return null; // undefined if all weights are identical
        return 1.0 - ($ssRes / $ssTot);
    }

    private function rmse(array $actual, array $predicted): float
    {
        $sum = 0.0;
        $n = count($actual);
        for ($i = 0; $i < $n; $i++) {
            $d = $actual[$i] - $predicted[$i];
            $sum += $d * $d;
        }
        return sqrt($sum / max(1, $n));
    }

    // Matrix ops

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
                $sum = 0.0;
                for ($k = 0; $k < $colsA; $k++) {
                    $sum += $A[$i][$k] * $B[$k][$j];
                }
                $result[$i][$j] = $sum;
            }
        }

        return $result;
    }

    private function invertMatrix3(array $m): array
    {
        $det =
            $m[0][0] * ($m[1][1]*$m[2][2] - $m[1][2]*$m[2][1]) -
            $m[0][1] * ($m[1][0]*$m[2][2] - $m[1][2]*$m[2][0]) +
            $m[0][2] * ($m[1][0]*$m[2][1] - $m[1][1]*$m[2][0]);

        if (abs($det) < 1e-12) {
            throw new \RuntimeException("Matrix is not invertible (det≈0).");
        }

        $invDet = 1.0 / $det;

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
