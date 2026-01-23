<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\LoginAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        UserAuthenticatorInterface $userAuthenticator,
        LoginAuthenticator $authenticator,
        #[Autowire(service: 'limiter.registration')]
        RateLimiterFactoryInterface $rateLimiter,
        MailerInterface $mailer
    ): Response {
        // Redirect if already logged in
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            // Rate limiting check (3 attempts per 15 minutes per IP)
            $limiter = $rateLimiter->create($request->getClientIp());
            if (false === $limiter->consume(1)->isAccepted()) {
                $error = 'Too many registration attempts. Please try again in 15 minutes.';
                return $this->render('security/register.html.twig', ['error' => $error]);
            }

            // Bot detection - Honeypot check
            $honeypot = $request->request->get('website');
            if (!empty($honeypot)) {
                // Bot detected - silently reject by showing fake success
                return $this->render('security/register.html.twig', [
                    'error' => 'Registration successful! Please check your email to verify your account.'
                ]);
            }

            // Bot detection - Time check (minimum 3 seconds to fill form)
            $formStartTime = (int) $request->request->get('form_start_time');
            $timeTaken = time() - $formStartTime;
            if ($timeTaken < 3) {
                $error = 'Please take your time filling out the form.';
                return $this->render('security/register.html.twig', ['error' => $error]);
            }

            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $passwordConfirm = $request->request->get('password_confirm');
            $firstName = $request->request->get('first_name');
            $lastName = $request->request->get('last_name');

            // Validation
            if (empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
                $error = 'All fields are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long.';
            } elseif ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
            } else {
                // Check if email already exists
                $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existingUser) {
                    $error = 'An account with this email already exists.';
                } else {
                    // Create new user
                    $user = new User();
                    $user->setEmail($email);
                    $user->setFirstName($firstName);
                    $user->setLastName($lastName);
                    $user->setRoles(['ROLE_USER']);

                    // Hash password
                    $hashedPassword = $passwordHasher->hashPassword($user, $password);
                    $user->setPassword($hashedPassword);

                    // Set email verification fields
                    $user->setIsVerified(false);
                    $user->setVerificationToken(bin2hex(random_bytes(32)));

                    // Persist to database
                    $entityManager->persist($user);
                    $entityManager->flush();

                    // Send verification email
                    $verificationUrl = $this->generateUrl('app_verify_email', [
                        'token' => $user->getVerificationToken()
                    ], UrlGeneratorInterface::ABSOLUTE_URL);

                    $email = (new TemplatedEmail())
                        ->from(new Address(
                            $_ENV['MAILER_FROM_EMAIL'] ?? 'noreply@airscales.com',
                            $_ENV['MAILER_FROM_NAME'] ?? 'AirScales'
                        ))
                        ->to($user->getEmail())
                        ->subject('Verify your AirScales account')
                        ->htmlTemplate('emails/verification.html.twig')
                        ->context([
                            'user' => $user,
                            'verificationUrl' => $verificationUrl
                        ]);

                    try {
                        $mailer->send($email);
                        $this->addFlash('success', 'Registration successful! Please check your email to verify your account.');
                    } catch (\Exception $e) {
                        $this->addFlash('warning', 'Account created, but we could not send the verification email. Please contact support.');
                    }

                    // Redirect to login instead of auto-login since email needs verification
                    return $this->redirectToRoute('app_login');
                }
            }
        }

        return $this->render('security/register.html.twig', [
            'error' => $error,
        ]);
    }
}
