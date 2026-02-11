<?php

namespace App\Controller;

use App\Form\ChangePasswordType;
use App\Form\CurrencyPreferenceType;
use App\Form\ProfileAccountType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/profile')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'profile_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, TranslatorInterface $translator, UserRepository $userRepository): Response
    {
        $user = $this->getUser();
        
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        $accountForm = $this->createForm(ProfileAccountType::class, $user);
        $accountForm->handleRequest($request);

        $preferenceForm = $this->createForm(CurrencyPreferenceType::class, $user);
        $preferenceForm->handleRequest($request);

        if ($accountForm->isSubmitted() && $accountForm->isValid()) {
            $existing = $userRepository->findOneBy(['email' => $user->getEmail()]);
            if ($existing && $existing->getId() !== $user->getId()) {
                $this->addFlash('error', 'user.flash.email_taken');
                return $this->redirectToRoute('profile_index');
            }

            $em->flush();
            $this->addFlash('success', 'user.flash.updated');
            return $this->redirectToRoute('profile_index');
        }

        if ($preferenceForm->isSubmitted() && $preferenceForm->isValid()) {
            $em->flush();
            $preferredLocale = $user->getPreferredLocale();
            if ($preferredLocale) {
                $request->setLocale($preferredLocale);
                if ($request->hasSession()) {
                    $request->getSession()->set('_locale', $preferredLocale);
                }
            }
            $this->addFlash('success', 'profile.flash.preferences_updated');
            return $this->redirectToRoute('profile_index');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Verify current password
            $currentPassword = $form->get('currentPassword')->getData();
            
            if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('error', 'profile.flash.current_password_incorrect');
                return $this->redirectToRoute('profile_index');
            }

            // Update password
            $newPassword = $form->get('newPassword')->getData();
            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->setPassword($hashedPassword);
            
            $em->flush();
            
            $this->addFlash('success', 'profile.flash.password_updated');
            return $this->redirectToRoute('profile_index');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'accountForm' => $accountForm->createView(),
            'preferenceForm' => $preferenceForm->createView(),
        ]);
    }
}
