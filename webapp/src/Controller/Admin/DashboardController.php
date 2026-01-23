<?php
// src/Admin/DashboardController

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Entity\Faq;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\Settings;
use App\Entity\Order;
use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Controller\Admin\UserCrudController;
use App\Entity\AxleGroup;
use App\Entity\Calibration;
use App\Entity\Firmware;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;


//use Symfony\Component\Security\Http\Attribute\IsGranted;

//#[IsGranted("ROLE_ADMIN")]
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        //return parent::index();

        // Option 1. You can make your dashboard redirect to some common page of your backend
        //
        // 1.1) If you have enabled the "pretty URLs" feature:
        //return $this->redirectToRoute('admin_user_index');
        //
        // 1.2) Same example but using the "ugly URLs" that were used in previous EasyAdmin versions:
        //$adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);
        return $this->render('admin/dashboard.html.twig');
        //return $this->redirect($adminUrlGenerator->setController(UserCrudController::class)->generateUrl());

        // Option 2. You can make your dashboard redirect to different pages depending on the user
        //
        // if ('jane' === $this->getUser()->getUsername()) {
        //     return $this->redirectToRoute('...');
        // }

        // Option 3. You can render some custom template to display a proper dashboard with widgets, etc.
        // (tip: it's easier if your template extends from @EasyAdmin/page/content.html.twig)
        //
        // return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Air Scales Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToUrl('Home', 'fa fa-home', $this->generateUrl('app_homepage'));
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-dashboard');
        yield MenuItem::section('Sales & Billing');
        yield MenuItem::linkToCrud('Orders', 'fas fa-box', Order::class);
        yield MenuItem::linkToCrud('Products', 'fas fa-tag', Product::class);
        yield MenuItem::linkToCrud('Pricing & Settings', 'fas fa-dollar-sign', Settings::class);
        yield MenuItem::section('Content Management');
        yield MenuItem::linkToCrud('FAQ Sections', 'fas fa-question-circle', Faq::class);
        yield MenuItem::section('User Management');
        yield MenuItem::linkToCrud('Users', 'fas fa-users', User::class);
        yield MenuItem::section('Hardware');
        yield MenuItem::linkToCrud('Devices','fa-solid fa-microchip', entityFqcn: Device::class);
        yield MenuItem::linkToCrud('Firmware', 'fa-solid fa-download', Firmware::class);
        yield MenuItem::linkToCrud('Vehicle','fas fa-truck', Vehicle::class);
        yield MenuItem::linkToCrud('Calibration','fa-solid fa-scale-unbalanced-flip', Calibration::class);
        yield MenuItem::linkToCrud('Axle Group','fa-solid fa-arrows-down-to-line', AxleGroup::class);
    }

}