<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // If user is already logged in, redirect to dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // This method will be intercepted by the logout key on your firewall.
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        RateLimiterFactory $passwordResetLimiter,
        RateLimiterFactory $passwordResetIpLimiter
    ): Response {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $clientIp = $request->getClientIp() ?? 'unknown';
            
            // Rate limit by email address (prevents spamming a single target)
            $emailLimiter = $passwordResetLimiter->create('password_reset_' . md5($email));
            if (!$emailLimiter->consume()->isAccepted()) {
                $this->logger->warning('Password reset rate limit exceeded for email', [
                    'email' => $email,
                    'ip' => $clientIp
                ]);
                $this->addFlash('error', 'Too many password reset requests. Please try again later.');
                return $this->redirectToRoute('app_forgot_password');
            }
            
            // Rate limit by IP address (prevents mass enumeration attacks)
            $ipLimiter = $passwordResetIpLimiter->create('password_reset_ip_' . $clientIp);
            if (!$ipLimiter->consume()->isAccepted()) {
                $this->logger->warning('Password reset IP rate limit exceeded', [
                    'ip' => $clientIp
                ]);
                $this->addFlash('error', 'Too many password reset requests from your location. Please try again later.');
                return $this->redirectToRoute('app_forgot_password');
            }
            
            $user = $userRepository->findOneBy(['email' => $email]);

            // Always show success message for security (don't reveal if email exists)
            $this->addFlash('success', 'If an account exists with that email, a password reset link has been sent.');

            if ($user) {
                // Generate reset token
                $resetToken = bin2hex(random_bytes(32));
                $user->setResetToken($resetToken);
                $user->setResetTokenExpiresAt(new \DateTime('+1 hour'));
                
                $entityManager->flush();

                // Generate reset URL
                $resetUrl = $this->generateUrl(
                    'app_reset_password',
                    ['token' => $resetToken],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                // Send email
                $emailMessage = (new Email())
                    ->from($_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@starzelectronics.site')
                    ->to($user->getEmail())
                    ->subject('Password Reset Request - STARZ Morocco CRM')
                    ->html($this->renderView('emails/reset_password.html.twig', [
                        'user' => $user,
                        'resetUrl' => $resetUrl,
                    ]));

                try {
                    $mailer->send($emailMessage);
                    $this->logger->info('Password reset email sent', [
                        'user_id' => $user->getId(),
                        'ip' => $clientIp
                    ]);
                } catch (\Exception $e) {
                    // Log error but don't reveal to user
                    $this->logger->error('Failed to send password reset email', [
                        'user_id' => $user->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                // Log attempted reset for non-existent email (potential enumeration)
                $this->logger->info('Password reset attempted for non-existent email', [
                    'email' => $email,
                    'ip' => $clientIp
                ]);
            }

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function resetPassword(
        string $token,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = $userRepository->findOneBy(['resetToken' => $token]);

        if (!$user || !$user->isResetTokenValid()) {
            $this->addFlash('error', 'Invalid or expired password reset token.');
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirm_password');

            if ($password !== $confirmPassword) {
                $this->addFlash('error', 'Passwords do not match.');
                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            if (strlen($password) < 8) {
                $this->addFlash('error', 'Password must be at least 8 characters long.');
                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            // Hash and set new password
            $hashedPassword = $passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
            
            // Clear reset token
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);
            
            $entityManager->flush();

            $this->addFlash('success', 'Your password has been reset successfully. You can now log in.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
        ]);
    }
}
