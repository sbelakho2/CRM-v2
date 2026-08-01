<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserAdminType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    #[Route('', name: 'admin_user_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $search = $request->query->get('search');
        $role = $request->query->get('role');
        $status = $request->query->get('status');

        $qb = $em->getRepository(User::class)->createQueryBuilder('u');

        // Search by name or email
        if ($search) {
            $qb->andWhere('u.email LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filter by role
        if ($role) {
            $qb->andWhere('u.roles LIKE :role')
               ->setParameter('role', '%' . $role . '%');
        }

        // Filter by status
        if ($status === 'active') {
            $qb->andWhere('u.active = true');
        } elseif ($status === 'inactive') {
            $qb->andWhere('u.active = false');
        }

        $users = $qb->orderBy('u.id', 'DESC')->getQuery()->getResult();

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, TranslatorInterface $translator): Response
    {
        $user = new User();
        $form = $this->createForm(UserAdminType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash the plain password
            $plainPassword = $form->get('plainPassword')->getData();
            $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
            
            $em->persist($user);
            $em->flush();
            
            $this->addFlash('success', $translator->trans('administration.users.flash.created'));
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_user_edit', methods: ['GET','POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(UserAdminType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', $translator->trans('administration.users.flash.updated'));
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('delete_user'.$user->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_user_index');
        }

        $conn = $em->getConnection();
        $userId = $user->getId();
        $adminUser = $this->getUser();
        $reassignId = $adminUser ? $adminUser->getId() : 1;

        try {
            $conn->beginTransaction();

            // NULL-out nullable FK columns
            $conn->executeStatement('UPDATE audit_logs SET user_id = NULL WHERE user_id = ?', [$userId]);
            $conn->executeStatement('UPDATE custom_field_definitions SET created_by_id = NULL WHERE created_by_id = ?', [$userId]);
            $conn->executeStatement('UPDATE tasks SET assigned_to_id = NULL WHERE assigned_to_id = ?', [$userId]);

            // Reassign NOT NULL FK columns to the current admin
            $conn->executeStatement('UPDATE activities SET user_id = ? WHERE user_id = ?', [$reassignId, $userId]);
            $conn->executeStatement('UPDATE calendar_event_attendees SET user_id = ? WHERE user_id = ?', [$reassignId, $userId]);
            $conn->executeStatement('UPDATE calendar_events SET organizer_id = ? WHERE organizer_id = ?', [$reassignId, $userId]);
            $conn->executeStatement('UPDATE meeting_slots SET owner_id = ? WHERE owner_id = ?', [$reassignId, $userId]);
            $conn->executeStatement('UPDATE notification SET user_id = ? WHERE user_id = ?', [$reassignId, $userId]);
            $conn->executeStatement('UPDATE report_definitions SET created_by_id = ? WHERE created_by_id = ?', [$reassignId, $userId]);
            $conn->executeStatement('UPDATE tasks SET created_by_id = ? WHERE created_by_id = ?', [$reassignId, $userId]);

            $em->remove($user);
            $em->flush();

            $conn->commit();

            $this->addFlash('success', $translator->trans('administration.users.flash.deleted'));
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $this->redirectToRoute('admin_user_index');
    }
}
