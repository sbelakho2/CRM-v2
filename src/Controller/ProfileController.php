<?php

namespace App\Controller;

use App\Form\ChangePasswordType;
use App\Form\CurrencyPreferenceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/profile')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'profile_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = $this->getUser();
        
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        $preferenceForm = $this->createForm(CurrencyPreferenceType::class, $user);
        $preferenceForm->handleRequest($request);

        if ($preferenceForm->isSubmitted() && $preferenceForm->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Currency preferences updated successfully!');
            return $this->redirectToRoute('profile_index');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Verify current password
            $currentPassword = $form->get('currentPassword')->getData();
            
            if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('error', 'Current password is incorrect.');
                return $this->redirectToRoute('profile_index');
            }

            // Update password
            $newPassword = $form->get('newPassword')->getData();
            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->setPassword($hashedPassword);
            
            $em->flush();
            
            $this->addFlash('success', 'Password updated successfully!');
            return $this->redirectToRoute('profile_index');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'preferenceForm' => $preferenceForm->createView(),
        ]);
    }
}
