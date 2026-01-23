<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class WeightExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_weight', [$this, 'formatWeight']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mask_weight', [$this, 'maskWeight']),
        ];
    }

    /**
     * Format and mask weight based on subscription status
     *
     * @param float|int|null $weight The weight value in lbs
     * @param bool $hasSubscription Whether user has active subscription
     * @return string Formatted weight string (e.g., "15,220 lbs" or "XX,220 lbs")
     */
    public function maskWeight($weight, bool $hasSubscription): string
    {
        if ($weight === null) {
            return '-- lbs';
        }

        $weight = (int) round($weight);

        // Subscribed users see full weight
        if ($hasSubscription) {
            return number_format($weight) . ' lbs';
        }

        // Free users see masked weight (XX,XXX for values >= 1000)
        if ($weight >= 1000) {
            // Get last 3 digits
            $lastThree = str_pad($weight % 1000, 3, '0', STR_PAD_LEFT);
            return 'XX,' . $lastThree . ' lbs';
        }

        // Under 1000 lbs, show full weight even for free users
        return number_format($weight) . ' lbs';
    }

    /**
     * Simple weight formatting (always show full value)
     */
    public function formatWeight($weight): string
    {
        if ($weight === null) {
            return '-- lbs';
        }

        return number_format((int) round($weight)) . ' lbs';
    }
}
