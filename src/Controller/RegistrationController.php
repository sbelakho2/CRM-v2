<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class RegistrationController extends AbstractController
{
    public function __construct(
        private bool $publicRegistrationEnabled,
    ) {}

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, TranslatorInterface $translator, MailerInterface $mailer, UriSigner $uriSigner, RateLimiterFactory $registrationLimiter, RateLimiterFactory $registrationIpLimiter): Response
    {
        // Internal CRM: public self-registration is disabled unless
        // ALLOW_PUBLIC_REGISTRATION=1 is explicitly set. User lifecycle is
        // admin-driven; an invitation flow replaces open signup.
        if (!$this->publicRegistrationEnabled) {
            $this->addFlash('error', $translator->trans('registration.flash.disabled'));

            return $this->redirectToRoute('app_login');
        }

        // If already logged in, redirect
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        // Rate limit registration attempts per email and per IP to prevent
        // mass account creation (same pattern as password reset limiting)
        if ($request->isMethod('POST')) {
            $clientIp = $request->getClientIp() ?? 'unknown';
            $submitted = $request->request->all($form->getName());
            $email = trim((string) ($submitted['email'] ?? ''));

            $emailLimiter = $registrationLimiter->create('registration_' . md5(strtolower($email)));
            if (!$emailLimiter->consume()->isAccepted()) {
                $this->addFlash('error', 'Too many registration attempts for this email. Please try again later.');
                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }

            $ipLimiter = $registrationIpLimiter->create('registration_ip_' . $clientIp);
            if (!$ipLimiter->consume()->isAccepted()) {
                $this->addFlash('error', 'Too many registration attempts from your location. Please try again later.');
                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = $form->get('plainPassword')->getData();
            $hashed = $passwordHasher->hashPassword($user, $plain);
            $user->setPassword($hashed);
            // default role
            $user->setRoles(['ROLE_USER']);
            // New registrations must verify their email before logging in
            $user->setIsVerified(false);

            $em->persist($user);
            $em->flush();

            // Build a signed, expiring verification link (HMAC-signed by UriSigner)
            $verificationUrl = $this->generateUrl('app_verify_email', [
                'id' => $user->getId(),
                'expires' => (new \DateTime('+7 days'))->getTimestamp(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);
            $verificationUrl = $uriSigner->sign($verificationUrl);

            try {
                $emailMessage = (new Email())
                    ->from($_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@starzelectronics.site')
                    ->to($user->getEmail())
                    ->subject('Verify your email - STARZ Morocco CRM')
                    ->html($this->renderView('emails/verification.html.twig', [
                        'user' => $user,
                        'verificationUrl' => $verificationUrl,
                    ]));

                $mailer->send($emailMessage);
            } catch (\Exception $e) {
                // Account is created; verification email failure is logged server-side only
                error_log('Failed to send verification email: ' . $e->getMessage());
            }

            $this->addFlash('success', $translator->trans('registration.flash.created'));
            $this->addFlash('info', 'A verification link has been sent to your email address. Please verify your email before logging in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/verify-email/{id}', name: 'app_verify_email')]
    public function verifyEmail(int $id, Request $request, EntityManagerInterface $em, UriSigner $uriSigner): Response
    {
        if (!$uriSigner->check($request->getUri())) {
            $this->addFlash('error', 'The verification link is invalid. Please try again.');
            return $this->redirectToRoute('app_login');
        }

        $expires = (int) $request->query->get('expires', 0);
        if ($expires > 0 && time() > $expires) {
            $this->addFlash('error', 'The verification link has expired. Request a new one with "Resend verification email" on the login page.');
            return $this->redirectToRoute('app_login');
        }

        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            $this->addFlash('error', 'The verification link is invalid. Please try again.');
            return $this->redirectToRoute('app_login');
        }

        $user->setIsVerified(true);
        $em->flush();

        $this->addFlash('success', 'Your email address has been verified. You can now log in.');

        return $this->redirectToRoute('app_login');
    }

    /**
     * Resend the verification email for an existing (unverified) account.
     *
     * Never creates a second account and never reveals whether an email is
     * registered: the response is identical either way. Rate limited per IP.
     */
    #[Route('/resend-verification', name: 'app_resend_verification', methods: ['POST'])]
    public function resendVerification(
        Request $request,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        UriSigner $uriSigner,
        RateLimiterFactory $registrationIpLimiter,
    ): Response {
        $clientIp = $request->getClientIp() ?? 'unknown';
        $limiter = $registrationIpLimiter->create('resend_verification_ip_' . $clientIp);
        if (!$limiter->consume()->isAccepted()) {
            $this->addFlash('error', 'Too many requests. Please try again later.');

            return $this->redirectToRoute('app_login', [], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $email = trim((string) $request->request->get('email', ''));
        if ($email !== '') {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user !== null && !$user->isVerified()) {
                $verificationUrl = $this->generateUrl('app_verify_email', [
                    'id' => $user->getId(),
                    'expires' => (new \DateTime('+7 days'))->getTimestamp(),
                ], UrlGeneratorInterface::ABSOLUTE_URL);
                $verificationUrl = $uriSigner->sign($verificationUrl);

                try {
                    $emailMessage = (new Email())
                        ->from($_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@starzelectronics.site')
                        ->to($user->getEmail())
                        ->subject('Verify your email - STARZ Morocco CRM')
                        ->html($this->renderView('emails/verification.html.twig', [
                            'user' => $user,
                            'verificationUrl' => $verificationUrl,
                        ]));

                    $mailer->send($emailMessage);
                } catch (\Exception $e) {
                    error_log('Failed to send verification email (resend): ' . $e->getMessage());
                }
            }
        }

        // Identical message whether or not the account exists — no account
        // enumeration.
        $this->addFlash('info', 'If that email belongs to an unverified account, a new verification link has been sent.');

        return $this->redirectToRoute('app_login');
    }
}
