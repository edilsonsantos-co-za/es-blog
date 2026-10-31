<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LogtoClient;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(LogtoClient $logto): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        // Sign-in only happens on the SSO page; problems are handled there (see SsoController::fail)
        if (!$logto->isEnabled()) {
            return new Response('Sign-in is not set up: this blog signs in through the SSO (LOGTO_ENDPOINT, LOGTO_APP_ID, LOGTO_APP_SECRET).', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->redirectToRoute('app_login_sso');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        // Handled by Symfony Security firewall — this method is never called.
        throw new \LogicException('This method should not be reached.');
    }

    #[Route('/2fa', name: 'app_2fa_form')]
    public function twoFactor(): Response
    {
        return $this->render('auth/2fa.html.twig');
    }

    /**
     * Enable TOTP MFA for the currently logged-in user.
     * Generates a secret, shows the QR code URI for the authenticator app.
     */
    #[Route('/admin/profile/mfa/enable', name: 'app_mfa_enable', methods: ['GET', 'POST'])]
    public function enableMfa(
        Request $request,
        TotpAuthenticatorInterface $totpAuthenticator,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $code = $request->request->get('code', '');
            if ($totpAuthenticator->checkCode($user, $code)) {
                $em->flush();
                $this->addFlash('success', 'Two-factor authentication has been enabled.');

                return $this->redirectToRoute('app_admin_profile');
            }
            $this->addFlash('error', 'Invalid code. Please try again.');
        }

        if (!$user->isMfaEnabled()) {
            $user->setMfaSecret($totpAuthenticator->generateSecret());
            $em->flush();
        }

        return $this->render('auth/mfa_enable.html.twig', [
            'qr_code_uri' => $totpAuthenticator->getQRContent($user),
            'secret'      => $user->getMfaSecret(),
        ]);
    }

    /**
     * Disable TOTP MFA. Requires password confirmation for safety.
     */
    #[Route('/admin/profile/mfa/disable', name: 'app_mfa_disable', methods: ['POST'])]
    public function disableMfa(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('mfa_disable', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_admin_profile');
        }

        $password = $request->request->get('password', '');
        if (!$hasher->isPasswordValid($user, $password)) {
            $this->addFlash('error', 'Incorrect password.');

            return $this->redirectToRoute('app_admin_profile');
        }

        $user->setMfaSecret('disabled');
        $em->flush();
        $this->addFlash('success', 'Two-factor authentication has been disabled.');

        return $this->redirectToRoute('app_admin_profile');
    }
}
