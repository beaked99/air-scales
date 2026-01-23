# Stripe Webhook Setup

## Overview

Webhooks allow Stripe to automatically notify your application when events happen (payments succeed, subscriptions renew, etc.). Without webhooks, your database won't be updated when users pay.

## What Webhooks Do

- **checkout.session.completed** - Creates subscription or order in database when user pays
- **customer.subscription.updated** - Updates subscription renewal dates
- **customer.subscription.deleted** - Marks subscription as expired
- **invoice.payment_succeeded** - Updates subscription on successful renewal
- **invoice.payment_failed** - Marks subscription as past_due when payment fails

## Setup Instructions

### 1. Local Development (Testing)

Use Stripe CLI to forward webhooks to your local machine:

```bash
# Install Stripe CLI
# Download from: https://stripe.com/docs/stripe-cli

# Login to Stripe
stripe login

# Forward webhooks to local endpoint
stripe listen --forward-to http://localhost:8000/webhook/stripe
```

The CLI will output a webhook signing secret like:
```
whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Add this to your `.env` file:
```
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### 2. Production (Live Server)

#### Step 1: Create Webhook Endpoint in Stripe Dashboard

1. Go to https://dashboard.stripe.com/webhooks
2. Click **"Add endpoint"**
3. Enter your webhook URL: `https://beaker.ca/webhook/stripe`
4. Select events to listen to:
   - `checkout.session.completed`
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.payment_succeeded`
   - `invoice.payment_failed`
5. Click **"Add endpoint"**

#### Step 2: Get Webhook Signing Secret

After creating the endpoint, click on it to view details. You'll see:
- **Signing secret:** `whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx`

Add this to your production `.env` file:
```
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

#### Step 3: Configure Email Notifications

Add these to your `.env` file:

```
# Email configuration
MAILER_DSN=smtp://user:pass@smtp.example.com:587
MAILER_FROM=noreply@airscales.com
ADMIN_EMAIL=your-email@example.com
```

When a device order is placed, you'll receive an email notification with:
- Customer name and email
- Product ordered
- Quantity
- Shipping address
- Direct link to view order in admin panel

### 3. Testing Webhooks

#### Local Testing with Stripe CLI

```bash
# Trigger a test checkout.session.completed event
stripe trigger checkout.session.completed

# Trigger a test payment succeeded event
stripe trigger payment_intent.succeeded
```

#### Production Testing

1. Make a test purchase using Stripe test card: `4242 4242 4242 4242`
2. Check your webhook logs in Stripe Dashboard: https://dashboard.stripe.com/webhooks
3. Click on the webhook endpoint to see recent events and their status
4. Look for successful 200 responses

### 4. Monitoring Webhooks

#### Stripe Dashboard

- View webhook logs: https://dashboard.stripe.com/webhooks
- See which events succeeded/failed
- Retry failed webhooks manually

#### Your Application Logs

Check `var/log/prod.log` (or `dev.log`) for webhook processing:

```bash
tail -f var/log/prod.log | grep "Stripe webhook"
```

Look for:
- `Stripe webhook received` - Event was received
- `Subscription created/updated` - Database was updated
- `Device order created` - Order was created
- `Order notification email sent` - Email was sent

### 5. Database Changes

Run these migrations to set up tables:

```bash
# Create product table
mysql -u root air_scales2 < migrations/create_product_table.sql

# Create order table
mysql -u root air_scales2 < migrations/create_order_table.sql
```

### 6. Admin Panel Access

After setup, you can view:

- **Orders:** https://beaker.ca/admin → Orders
  - See pending orders that need shipping
  - Add tracking numbers
  - Mark as shipped/delivered
  - Add internal notes

- **Products:** https://beaker.ca/admin → Products
  - View all products
  - Update Stripe price IDs
  - Enable/disable products

## Troubleshooting

### Webhook Returns 400 "Invalid signature"

- Check that `STRIPE_WEBHOOK_SECRET` in `.env` matches the signing secret from Stripe Dashboard
- Make sure you're using the webhook secret for the correct environment (test vs live)

### Webhook Returns 500 Error

- Check application logs: `var/log/prod.log`
- Ensure database tables exist (run migrations)
- Verify Stripe customer ID is stored in user record

### Not Receiving Webhooks

- Check webhook URL is correct and publicly accessible
- Verify endpoint is listening for the right events
- Check firewall isn't blocking Stripe's IP addresses

### Order Not Created

- Check webhook logs in Stripe Dashboard
- Verify product exists with matching `stripe_price_id`
- Check `checkout.session.completed` event has `mode: 'payment'` for devices

### No Email Notification

- Check `MAILER_DSN` is configured in `.env`
- Check `ADMIN_EMAIL` is set
- Look for email errors in logs: `grep "Failed to send" var/log/prod.log`

## Security Notes

- Never commit webhook secrets to git (use `.env`)
- Always verify webhook signatures (handled automatically)
- Webhook endpoint doesn't require authentication (Stripe signs requests)
- Keep `STRIPE_WEBHOOK_SECRET` private

## Next Steps

After webhook setup:

1. Test subscription purchase end-to-end
2. Test device purchase and verify order appears in admin
3. Test marking order as shipped in admin
4. Configure email server for notifications
5. Add order tracking page for customers (optional)
