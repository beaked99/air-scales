<?php
// Quick manual sync for existing Stripe subscription
// Usage: php manual_sync_subscription.php [stripe_customer_id]

require __DIR__ . '/vendor/autoload.php';

// Load environment
if (file_exists(__DIR__ . '/.env.local')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '.env.local');
    $dotenv->load();
} elseif (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

use Doctrine\DBAL\DriverManager;

if ($argc < 2) {
    echo "Usage: php manual_sync_subscription.php [stripe_customer_id]\n";
    echo "Example: php manual_sync_subscription.php cus_TljWAPiWjKmZ3i\n";
    exit(1);
}

$stripeCustomerId = $argv[1];

// Set up Stripe
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

echo "Fetching customer: $stripeCustomerId\n";

try {
    // Get customer's active subscriptions from Stripe
    $subscriptions = \Stripe\Subscription::all([
        'customer' => $stripeCustomerId,
        'status' => 'active',
        'limit' => 10
    ]);

    if (count($subscriptions->data) === 0) {
        echo "❌ No active subscriptions found for this customer in Stripe\n";
        exit(1);
    }

    // Connect to database
    $connectionParams = [
        'dbname' => 'air_scales2',
        'user' => 'root',
        'password' => $_ENV['DATABASE_PASSWORD'] ?? '',
        'host' => $_ENV['DATABASE_HOST'] ?? 'localhost',
        'driver' => 'pdo_mysql',
    ];
    $conn = DriverManager::getConnection($connectionParams);

    // Get user ID from database
    $user = $conn->fetchAssociative(
        'SELECT id, email FROM user WHERE stripe_customer_id = ?',
        [$stripeCustomerId]
    );

    if (!$user) {
        echo "❌ User not found in database for customer: $stripeCustomerId\n";
        exit(1);
    }

    echo "✓ Found user: {$user['email']} (ID: {$user['id']})\n\n";

    foreach ($subscriptions->data as $stripeSub) {
        echo "Subscription: {$stripeSub->id}\n";
        echo "  Status: {$stripeSub->status}\n";
        echo "  Current period: " . date('Y-m-d H:i:s', $stripeSub->current_period_start) .
             " to " . date('Y-m-d H:i:s', $stripeSub->current_period_end) . "\n";

        // Check if already exists
        $existing = $conn->fetchAssociative(
            'SELECT id FROM subscription WHERE stripe_subscription_id = ?',
            [$stripeSub->id]
        );

        if ($existing) {
            echo "  ✓ Already exists in database (ID: {$existing['id']})\n";

            // Update it
            $conn->executeStatement(
                'UPDATE subscription SET
                    status = ?,
                    current_period_start = ?,
                    current_period_end = ?
                WHERE stripe_subscription_id = ?',
                [
                    $stripeSub->status,
                    date('Y-m-d H:i:s', $stripeSub->current_period_start),
                    date('Y-m-d H:i:s', $stripeSub->current_period_end),
                    $stripeSub->id
                ]
            );
            echo "  ✓ Updated\n";
        } else {
            // Create new
            $conn->executeStatement(
                'INSERT INTO subscription
                    (user_id, stripe_subscription_id, status, current_period_start, current_period_end, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())',
                [
                    $user['id'],
                    $stripeSub->id,
                    $stripeSub->status,
                    date('Y-m-d H:i:s', $stripeSub->current_period_start),
                    date('Y-m-d H:i:s', $stripeSub->current_period_end)
                ]
            );
            $newId = $conn->lastInsertId();
            echo "  ✓ Created in database (ID: $newId)\n";
        }
        echo "\n";
    }

    echo "✅ Sync complete!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
