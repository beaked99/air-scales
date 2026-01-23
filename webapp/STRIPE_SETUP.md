# Stripe Setup Guide

## Products and Prices to Create in Stripe Dashboard

Go to: https://dashboard.stripe.com/test/products

### 1. Subscription Products

#### Monthly Subscription
- **Product Name**: Air Scales Monthly Subscription
- **Description**: Monthly access to Air Scales weight readings
- **Pricing**:
  - Type: Recurring
  - Price: $5.00 USD
  - Billing Period: Monthly
- **After creating, copy the Price ID** (starts with `price_...`)
- Add to Settings table as `stripe_price_monthly`

#### Yearly Subscription
- **Product Name**: Air Scales Yearly Subscription
- **Description**: Yearly access to Air Scales weight readings (save $10!)
- **Pricing**:
  - Type: Recurring
  - Price: $50.00 USD
  - Billing Period: Yearly
- **After creating, copy the Price ID** (starts with `price_...`)
- Add to Settings table as `stripe_price_yearly`

### 2. Device Products (One-time payments)

#### Single Device
- **Product Name**: Air Scales Device - Single
- **Description**: One Air Scales pressure sensor device
- **Pricing**:
  - Type: One-time
  - Price: $150.00 USD
- **After creating, copy the Price ID**
- Add to Settings table as `stripe_price_device_single`

#### 2-Pack Devices
- **Product Name**: Air Scales Device - 2 Pack
- **Description**: Two Air Scales pressure sensor devices
- **Pricing**:
  - Type: One-time
  - Price: $250.00 USD
- **After creating, copy the Price ID**
- Add to Settings table as `stripe_price_device_2pack`

#### 3-Pack Devices
- **Product Name**: Air Scales Device - 3 Pack
- **Description**: Three Air Scales pressure sensor devices
- **Pricing**:
  - Type: One-time
  - Price: $300.00 USD
- **After creating, copy the Price ID**
- Add to Settings table as `stripe_price_device_3pack`

## Add Price IDs to Database

After creating all products in Stripe, run this SQL:

```sql
-- Update these with your actual Stripe price IDs
INSERT INTO settings (setting_key, setting_value, description) VALUES
('stripe_price_monthly', 'price_XXXXXXXXXXXXX', 'Monthly subscription price ID'),
('stripe_price_yearly', 'price_XXXXXXXXXXXXX', 'Yearly subscription price ID'),
('stripe_price_device_single', 'price_XXXXXXXXXXXXX', 'Single device price ID'),
('stripe_price_device_2pack', 'price_XXXXXXXXXXXXX', '2-pack device price ID'),
('stripe_price_device_3pack', 'price_XXXXXXXXXXXXX', '3-pack device price ID')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
```

## Set Up Webhooks (Next Step)

Once products are created, we'll need to set up webhooks to sync subscription events from Stripe back to your database.

Webhook URL will be: `https://beaker.ca/api/stripe/webhook`

Events to listen for:
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `checkout.session.completed`
- `invoice.payment_succeeded`
- `invoice.payment_failed`

## Testing with Stripe Test Cards

Use these test cards in Stripe Checkout:
- **Success**: 4242 4242 4242 4242
- **Decline**: 4000 0000 0000 0002
- **3D Secure**: 4000 0027 6000 3184

Use any future expiry date and any 3-digit CVC.
