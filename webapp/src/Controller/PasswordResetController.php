<?php

namespace App\Controller;

use App\Entity\User;
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

class PasswordResetController extends AbstractController
{
    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function request(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        #[Autowire(service: 'limiter.registration')]
        RateLimiterFactoryInterface $rateLimiter
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = null;
        $success = false;

        if ($request->isMethod('POST')) {
            // Rate limiting check (reuse registration limiter - 3 attempts per 15 minutes)
            $limiter = $rateLimiter->create($request->getClientIp());
            if (false === $limiter->consume(1)->isAccepted()) {
                $error = 'Too many password reset attempts. Please try again in 15 minutes.';
                return $this->render('security/forgot_password.html.twig', ['error' => $error]);
            }

            // Honeypot check
            $honeypot = $request->request->get('website');
            if (!empty($honeypot)) {
                // Bot detected - fake success
                $success = true;
                return $this->render('security/forgot_password.html.twig', ['success' => $success]);
            }

            $email = $request->request->get('email');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

                // Always show success message (don't reveal if email exists)
                $success = true;

                if ($user) {
                    // Generate reset token
                    $resetToken = bin2hex(random_bytes(32));
                    $user->setResetToken($resetToken);
                    $user->setResetTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
                    $entityManager->flush();

                    // Send reset email
                    $resetUrl = $this->generateUrl('app_reset_password', [
                        'token' => $resetToken
                    ], UrlGeneratorInterface::ABSOLUTE_URL);

                    $emailMessage = (new TemplatedEmail())
                        ->from(new Address(
                            $_ENV['MAILER_FROM_EMAIL'] ?? 'noreply@airscales.com',
                            $_ENV['MAILER_FROM_NAME'] ?? 'AirScales'
                        ))
                        ->to($user->getEmail())
                        ->subject('Reset your AirScales password')
                        ->htmlTemplate('emails/password_reset.html.twig')
                        ->context([
                            'user' => $user,
                            'resetUrl' => $resetUrl
                        ]);

                    try {
                        $mailer->send($emailMessage);
                    } catch (\Exception $e) {
                        // Silently fail - still show success to user
                    }
                }
            }
        }

        return $this->render('security/forgot_password.html.twig', [
            'error' => $error,
            'success' => $success
        ]);
    }

    #[Route('/reset-password', name: 'app_reset_password')]
    public function reset(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $token = $request->query->get('token');
        $error = null;

        if (!$token) {
            $this->addFlash('error', 'Invalid password reset link.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(['resetToken' => $token]);

        if (!$user || !$user->isResetTokenValid()) {
            $this->addFlash('error', 'This password reset link has expired or is invalid. Please request a new one.');
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');
            $passwordConfirm = $request->request->get('password_confirm');

            if (empty($password)) {
                $error = 'Password is required.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long.';
            } elseif ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
            } else {
                // Update password
                $hashedPassword = $passwordHasher->hashPassword($user, $password);
                $user->setPassword($hashedPassword);
                $user->setResetToken(null);
                $user->setResetTokenExpiresAt(null);
                $entityManager->flush();

                $this->addFlash('success', 'Your password has been reset successfully. You can now log in.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
            'error' => $error
        ]);
    }
}
