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

        // Never let a user deletion destroy data: reassign everything the user
        // owns/created to a target user first (explicit reassign_to, otherwise
        // the acting admin), then remove the account. The DB-level ON DELETE
        // CASCADE rules would otherwise silently delete activities, tasks,
        // calendar events, meeting slots, notifications and report definitions.
        $actingAdmin = $this->getUser();
        $targetId = $request->request->get('reassign_to');
        $target = null;

        if ($targetId !== null && $targetId !== '') {
            $candidate = $em->getRepository(User::class)->find((int) $targetId);
            if ($candidate && $candidate->getId() !== $user->getId()) {
                $target = $candidate;
            }
        }

        if ($target === null && $actingAdmin instanceof User && $actingAdmin->getId() !== $user->getId()) {
            $target = $actingAdmin;
        }

        if ($target === null) {
            $this->addFlash('error', $translator->trans('administration.users.flash.delete_needs_target'));

            return $this->redirectToRoute('admin_user_index');
        }

        $userFullName = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));
        $userId = $user->getId();
        $connection = $em->getConnection();

        $em->wrapInTransaction(function () use ($connection, $userId, $userFullName, $target): void {
            $targetId = $target->getId();

            // Integer foreign keys (cascade or set-null on user delete).
            $connection->executeStatement('UPDATE activities SET user_id = :t WHERE user_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE tasks SET created_by_id = :t WHERE created_by_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE tasks SET assigned_to_id = :t WHERE assigned_to_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE calendar_events SET organizer_id = :t WHERE organizer_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE calendar_event_attendees SET user_id = :t WHERE user_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE meeting_slots SET owner_id = :t WHERE owner_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE notification SET user_id = :t WHERE user_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE report_definitions SET created_by_id = :t WHERE created_by_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE custom_field_definitions SET created_by_id = :t WHERE created_by_id = :u', ['t' => $targetId, 'u' => $userId]);
            $connection->executeStatement('UPDATE audit_logs SET user_id = :t WHERE user_id = :u', ['t' => $targetId, 'u' => $userId]);

            // Textual owner references (stored as display names).
            if ($userFullName !== '') {
                $targetFullName = trim(($target->getFirstName() ?? '') . ' ' . ($target->getLastName() ?? ''));
                foreach (['leads' => 'owner_rep', 'email_segment' => 'created_by', 'email_template' => 'created_by', 'rfq_versions' => 'created_by'] as $table => $column) {
                    $connection->executeStatement(
                        sprintf('UPDATE %s SET %s = :t WHERE %s = :u', $table, $column, $column),
                        ['t' => $targetFullName, 'u' => $userFullName]
                    );
                }
            }
        });

        $em->refresh($user);
        $em->remove($user);
        $em->flush();

        $this->addFlash('success', $translator->trans('administration.users.flash.deleted_reassigned', [
            '%name%' => $userFullName,
            '%target%' => trim(($target->getFirstName() ?? '') . ' ' . ($target->getLastName() ?? '')),
        ]));

        return $this->redirectToRoute('admin_user_index');
    }
}
