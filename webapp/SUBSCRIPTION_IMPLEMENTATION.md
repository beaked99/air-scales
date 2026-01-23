# Subscription & Weight Masking Implementation

## Overview

This implementation adds subscription management and weight masking for free users. Free users see masked weight values (XX,220 lbs) while subscribed users see full weights (15,220 lbs).

## What's Been Implemented

### 1. Database Entities

#### **Subscription Entity** (`src/Entity/Subscription.php`)
Tracks user subscription status with:
- Stripe subscription ID
- Status (active, past_due, canceled, trialing, inactive)
- Plan type (monthly, yearly)
- Current period start/end dates
- Device trial tracking (6 months free with purchase)
- Promotional/legacy user flag (unlimited free access)
- 7-day grace period after cancellation

#### **Settings Entity** (`src/Entity/Settings.php`)
Key-value store for pricing and configuration:
- Device prices (single, 2-pack, 3-pack)
- Subscription prices (monthly, yearly)
- Trial periods
- Stripe API keys
- Stripe product/price IDs

#### **User Entity Updates** (`src/Entity/User.php`)
- Added OneToOne relationship with Subscription
- Added `hasActiveSubscription()` helper method

### 2. Admin Interface (EasyAdmin)

#### **Settings CRUD** (`src/Controller/Admin/SettingsCrudController.php`)
- Manage pricing in admin panel at `/admin`
- Edit device prices, subscription prices, trial periods
- Configure Stripe keys and product IDs

**Current Prices (as configured):**
- Single device: $150
- 2-pack: $250
- 3-pack: $300
- Monthly subscription: $5/month
- Yearly subscription: $50/year

### 3. Weight Masking System

#### **Twig Extension** (`src/Twig/WeightExtension.php`)
- `mask_weight(weight, hasSubscription)` function
- Masks weights >= 1000 lbs as "XX,XXX" for free users
- Weights under 1000 lbs shown in full (so users can verify device works)

#### **JavaScript Helper** (`public/js/weight-masking.js`)
- `maskWeight(weight, hasSubscription)` function
- Client-side weight masking for live BLE data
- Consistent formatting with server-side

#### **Dashboard Integration**
- Dashboard controller checks subscription status via `User::hasActiveSubscription()`
- Weight masking applied to:
  - Individual device weights
  - Total weight display
  - Live BLE updates
- Subscription status passed to JavaScript as `window.hasActiveSubscription`

## Subscription Access Logic

Users have access if ANY of these are true:

1. **Promotional/Legacy User** (`is_promotional = true`)
   - Unlimited free access
   - Set manually in admin

2. **Device Trial** (6 months from purchase)
   - `has_device_trial = true`
   - `device_trial_ends_at > now()`
   - Automatically granted on device purchase

3. **Active Paid Subscription**
   - `status IN ('active', 'trialing')`
   - Managed via Stripe webhooks

4. **Grace Period** (7 days after cancellation)
   - Subscription expired/canceled
   - Within 7 days of `current_period_end`

## Weight Masking Examples

| Weight | Free User Sees | Subscribed User Sees |
|--------|----------------|---------------------|
| 15,220 lbs | XX,220 lbs | 15,220 lbs |
| 8,450 lbs | XX,450 lbs | 8,450 lbs |
| 950 lbs | 950 lbs | 950 lbs |
| 12 lbs | 12 lbs | 12 lbs |

## Database Setup

### 1. Create Tables

Run Doctrine migrations to create `subscription` and `settings` tables:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

### 2. Initialize Settings

Run the initial settings SQL:

```bash
# On Windows with MySQL
mysql -u root -p air_scales < migrations/initial_settings.sql

# Or import via PHPMyAdmin
```

This creates default pricing settings that can be edited in `/admin`.

### 3. Add Subscription to Existing Users

For testing, manually create subscription records:

```sql
-- Give user ID 1 promotional access (free forever)
INSERT INTO subscription (user_id, status, is_promotional, created_at)
VALUES (1, 'active', 1, NOW());

-- Give user ID 2 a device trial (6 months)
INSERT INTO subscription (user_id, status, has_device_trial, device_trial_ends_at, created_at)
VALUES (2, 'trialing', 1, DATE_ADD(NOW(), INTERVAL 6 MONTH), NOW());
```

## Admin Usage

### Managing Pricing

1. Go to `/admin`
2. Click "Pricing & Settings"
3. Edit any setting value
4. Values update immediately

### Managing User Subscriptions

1. Go to `/admin`
2. Click "Users"
3. Edit user
4. (Future: Add subscription management interface)

## Next Steps (Not Yet Implemented)

### Stripe Device Checkout
- [ ] Create device purchase page
- [ ] Stripe Checkout integration
- [ ] Order fulfillment system
- [ ] Auto-create subscription with 6-month trial on purchase

### Stripe Subscription Checkout
- [ ] Create subscription plans page
- [ ] Stripe Checkout for subscriptions
- [ ] Stripe Customer Portal integration
- [ ] Webhook handlers for subscription events

### User Profile Page
- [ ] View subscription status
- [ ] Manage subscription (upgrade/cancel)
- [ ] View device trial expiration
- [ ] Purchase history

## Testing

### Test Weight Masking

1. **As Free User:**
   - Ensure user has NO subscription record (or `status = 'inactive'`)
   - Dashboard shows "XX,XXX lbs" for weights >= 1000
   - Full weights shown for < 1000 lbs

2. **As Subscribed User:**
   - Create subscription with `status = 'active'` OR `is_promotional = true`
   - Dashboard shows full weights

3. **Test BLE Live Updates:**
   - Connect device via BLE
   - Watch weight values update with proper masking

### Test Subscription Status

```php
// In any controller
$user = $this->getUser();
$hasAccess = $user->hasActiveSubscription();

// Check days until expiration
$subscription = $user->getSubscription();
$daysLeft = $subscription?->getDaysUntilExpiration(); // null = never expires
```

## File Reference

### New Files Created
- `src/Entity/Subscription.php`
- `src/Entity/Settings.php`
- `src/Twig/WeightExtension.php`
- `src/Controller/Admin/SettingsCrudController.php`
- `public/js/weight-masking.js`
- `migrations/initial_settings.sql`

### Modified Files
- `src/Entity/User.php` - Added subscription relationship
- `src/Controller/DashboardController.php` - Subscription check
- `src/Controller/Admin/DashboardController.php` - Added Settings menu
- `templates/dashboard/index.html.twig` - Added subscription status JS variable
- `public/js/dashboard.js` - Weight masking integration

## Notes

- Weight masking is purely cosmetic - all calculations use real values
- Subscription checks happen server-side for security
- JavaScript masking prevents console inspection of real values
- Pressure and temperature data always shown in full (not gated by subscription)
