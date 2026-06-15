<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Security\Voter\UserVoter;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UserController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    #[Route('/admin/users', name: 'app_admin_users', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function list(): Response
    {
        return $this->render('admin/user/list.html.twig', [
            'users' => $this->userService->findAll(),
        ]);
    }

    #[Route('/admin/user/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_user_new');
            }

            $username = trim($request->request->get('username', ''));
            $email    = trim($request->request->get('email', ''));
            $password = $request->request->get('password', '');
            $roleStr  = $request->request->get('role', UserRole::Author->value);

            if ($username === '' || $email === '' || $password === '') {
                $this->addFlash('error', 'Username, email and password are required.');
                return $this->redirectToRoute('app_admin_user_new');
            }

            if ($this->userService->usernameExists($username)) {
                $this->addFlash('error', 'Username already taken.');
                return $this->redirectToRoute('app_admin_user_new');
            }

            if ($this->userService->emailExists($email)) {
                $this->addFlash('error', 'Email already registered.');
                return $this->redirectToRoute('app_admin_user_new');
            }

            try {
                $role = UserRole::from($roleStr);
                $this->userService->create($username, $password, $role, $email);
                $this->addFlash('success', 'User created successfully.');
                return $this->redirectToRoute('app_admin_users');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating user: ' . $e->getMessage());
            }
        }

        return $this->render('admin/user/form.html.twig', [
            'user'       => null,
            'is_new'     => true,
        ]);
    }

    #[Route('/admin/user/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->userService->findById($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_user_edit', ['id' => $id]);
            }

            try {
                $email = trim($request->request->get('email', ''));
                if ($email !== '' && $this->userService->emailExists($email, $user)) {
                    $this->addFlash('error', 'Email already registered to another user.');
                    return $this->redirectToRoute('app_admin_user_edit', ['id' => $id]);
                }

                $this->userService->updateProfile($user, [
                    'email' => $email,
                ]);

                // Role change (admin only)
                $roleStr = $request->request->get('role', '');
                if ($roleStr !== '') {
                    $role = UserRole::from($roleStr);
                    $this->denyAccessUnlessGranted(UserVoter::CHANGE_ROLE, $user);
                    $this->userService->changeRole($user, $role);
                }

                // Password change
                $newPassword = $request->request->get('new_password', '');
                if ($newPassword !== '') {
                    $this->userService->changePassword($user, $newPassword);
                }

                $this->addFlash('success', 'User updated successfully.');
                return $this->redirectToRoute('app_admin_user_edit', ['id' => $id]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating user: ' . $e->getMessage());
            }
        }

        return $this->render('admin/user/form.html.twig', [
            'user'   => $user,
            'is_new' => false,
        ]);
    }

    #[Route('/admin/user/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id, Request $request): Response
    {
        $user = $this->userService->findById($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        $this->denyAccessUnlessGranted(UserVoter::DELETE, $user);

        if (!$this->isCsrfTokenValid('user_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_users');
        }

        try {
            $this->userService->delete($user);
            $this->addFlash('success', 'User deleted.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Cannot delete user: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/admin/profile', name: 'app_admin_profile', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function profile(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_profile');
            }

            try {
                $profileData = [
                    'email'     => trim($request->request->get('email', '')),
                    'about'     => $request->request->get('about', ''),
                    'avatar'    => trim($request->request->get('avatar', '')),
                    'facebook'  => trim($request->request->get('facebook', '')),
                    'twitter'   => trim($request->request->get('twitter', '')),
                    'instagram' => trim($request->request->get('instagram', '')),
                    'bluesky'   => trim($request->request->get('bluesky', '')),
                    'linkedin'  => trim($request->request->get('linkedin', '')),
                    'github'    => trim($request->request->get('github', '')),
                ];

                $this->userService->updateProfile($user, $profileData);

                $newPassword = $request->request->get('new_password', '');
                if ($newPassword !== '') {
                    $this->userService->changePassword($user, $newPassword);
                }

                $this->addFlash('success', 'Profile updated successfully.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error: ' . $e->getMessage());
            }

            return $this->redirectToRoute('app_admin_profile');
        }

        return $this->render('admin/user/profile.html.twig', [
            'user' => $user,
        ]);
    }
}
