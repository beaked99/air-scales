<?php
// Quick script to sync subscriptions from Stripe to database
// Run this ONCE to sync existing Stripe subscriptions to your database

require __DIR__ . '/vendor/autoload.php';

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Database configuration
$dbParams = [
    'driver' => 'pdo_mysql',
    'host' => $_ENV['DATABASE_HOST'] ?? 'localhost',
    'dbname' => 'air_scales2',
    'user' => 'root',
    'password' => $_ENV['DATABASE_PASSWORD'] ?? '',
    'charset' => 'utf8mb4',
];

$config = Setup::createAttributeMetadataConfiguration(
    [__DIR__ . '/src/Entity'],
    true
);

$entityManager = EntityManager::create($dbParams, $config);

// Set up Stripe
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

echo "Fetching all customers with subscriptions from Stripe...\n\n";

try {
    // Get all customers
    $customers = \Stripe\Customer::all(['limit' => 100]);

    foreach ($customers->data as $customer) {
        echo "Customer: {$customer->email} ({$customer->id})\n";

        // Find user in database
        $user = $entityManager->getRepository(App\Entity\User::class)
            ->findOneBy(['stripeCustomerId' => $customer->id]);

        if (!$user) {
            echo "  ❌ User not found in database\n\n";
            continue;
        }

        echo "  ✓ Found user: {$user->getEmail()} (ID: {$user->getId()})\n";

        // Get customer's subscriptions
        $subscriptions = \Stripe\Subscription::all([
            'customer' => $customer->id,
            'status' => 'active',
        ]);

        if (count($subscriptions->data) === 0) {
            echo "  No active subscriptions\n\n";
            continue;
        }

        foreach ($subscriptions->data as $stripeSub) {
            echo "  Subscription: {$stripeSub->id}\n";
            echo "    Status: {$stripeSub->status}\n";
            echo "    Period: " . date('Y-m-d', $stripeSub->current_period_start) . " to " . date('Y-m-d', $stripeSub->current_period_end) . "\n";

            // Check if subscription already exists in database
            $existingSub = $entityManager->getRepository(App\Entity\Subscription::class)
                ->findOneBy(['stripeSubscriptionId' => $stripeSub->id]);

            if ($existingSub) {
                echo "    ✓ Already in database (ID: {$existingSub->getId()})\n";

                // Update it
                $existingSub->setStatus($stripeSub->status);
                $existingSub->setCurrentPeriodStart(
                    (new DateTimeImmutable())->setTimestamp($stripeSub->current_period_start)
                );
                $existingSub->setCurrentPeriodEnd(
                    (new DateTimeImmutable())->setTimestamp($stripeSub->current_period_end)
                );
                $entityManager->flush();
                echo "    ✓ Updated\n";
            } else {
                // Create new subscription
                $newSub = new App\Entity\Subscription();
                $newSub->setUser($user);
                $newSub->setStripeSubscriptionId($stripeSub->id);
                $newSub->setStatus($stripeSub->status);
                $newSub->setCurrentPeriodStart(
                    (new DateTimeImmutable())->setTimestamp($stripeSub->current_period_start)
                );
                $newSub->setCurrentPeriodEnd(
                    (new DateTimeImmutable())->setTimestamp($stripeSub->current_period_end)
                );

                $entityManager->persist($newSub);
                $entityManager->flush();

                echo "    ✓ Created in database (ID: {$newSub->getId()})\n";
            }
        }

        echo "\n";
    }

    echo "\n✅ Sync complete!\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
