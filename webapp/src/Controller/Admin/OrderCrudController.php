<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Order')
            ->setEntityLabelInPlural('Orders')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPageTitle('index', 'Device Orders')
            ->setPageTitle('detail', fn (Order $order) => sprintf('Order #%d', $order->getId()));
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add(EntityFilter::new('user'))
            ->add(EntityFilter::new('product'))
            ->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Order #')
            ->onlyOnIndex();

        // Customer info - handles both users and guests
        yield TextField::new('customerName', 'Customer')
            ->formatValue(function ($value, Order $entity) {
                $name = $entity->getCustomerName();
                $email = $entity->getCustomerEmail();
                $type = $entity->getUser() ? '(Registered)' : '(Guest)';
                return $name . ' ' . $type . ' - ' . $email;
            })
            ->hideOnForm();

        yield TextField::new('guestName', 'Guest Name')
            ->onlyOnDetail()
            ->setHelp('Only for guest orders');

        yield TextField::new('guestEmail', 'Guest Email')
            ->onlyOnDetail()
            ->setHelp('Only for guest orders');

        yield AssociationField::new('user', 'Registered User')
            ->onlyOnDetail();

        // Order Items - display line items from JSON
        if ($pageName === Crud::PAGE_INDEX) {
            yield TextField::new('itemsSummary', 'Items');
        } else {
            yield TextareaField::new('orderItemsDisplay', 'Items Ordered')
                ->hideOnForm()
                ->setTemplatePath('admin/field/order_items_table.html.twig');
        }

        yield MoneyField::new('subtotal', 'Subtotal')
            ->setCurrency('USD')
            ->setStoredAsCents(false)
            ->hideOnIndex()
            ->setHelp('Total before discounts');

        yield MoneyField::new('discountAmount', 'Discount')
            ->setCurrency('USD')
            ->setStoredAsCents(false)
            ->hideOnIndex()
            ->setHelp('Volume discount applied');

        yield MoneyField::new('totalPaid', 'Total Paid')
            ->setCurrency('USD')
            ->setStoredAsCents(false);

        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'Pending' => 'pending',
                'Processing' => 'processing',
                'Shipped' => 'shipped',
                'Delivered' => 'delivered',
                'Canceled' => 'canceled',
            ])
            ->renderAsBadges([
                'pending' => 'warning',
                'processing' => 'info',
                'shipped' => 'primary',
                'delivered' => 'success',
                'canceled' => 'danger',
            ]);

        yield TextareaField::new('shippingAddress', 'Shipping Address')
            ->hideOnIndex();

        yield TextField::new('carrier', 'Carrier')
            ->hideOnIndex();

        yield TextField::new('trackingNumber', 'Tracking #')
            ->setHelp('USPS, UPS, FedEx tracking number');

        yield DateTimeField::new('shippedAt', 'Shipped Date')
            ->hideOnIndex();

        yield DateTimeField::new('deliveredAt', 'Delivered Date')
            ->hideOnIndex();

        yield TextareaField::new('adminNotes', 'Admin Notes')
            ->hideOnIndex()
            ->setHelp('Internal notes (not visible to customer)');

        yield DateTimeField::new('createdAt', 'Order Date')
            ->hideOnForm();

        yield TextField::new('stripeCheckoutSessionId', 'Stripe Session ID')
            ->onlyOnDetail()
            ->setHelp('Stripe Checkout Session ID for this order');

        yield TextField::new('stripePaymentIntentId', 'Stripe Payment ID')
            ->onlyOnDetail()
            ->setHelp('Stripe Payment Intent ID');
    }
}
