<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendWelcomeEmailMessage;
use App\Repository\UserRepository;
use App\Service\Newsletter\NewsletterMailer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/** Welcome email for a new author account (sent when an admin creates the user). */
#[AsMessageHandler]
final class SendWelcomeEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepo,
        private readonly NewsletterMailer $urls,
        private readonly Environment $twig,
        #[Autowire('%env(default::MAILER_FROM)%')] private readonly ?string $mailerFrom = null,
    ) {}

    public function __invoke(SendWelcomeEmailMessage $message): void
    {
        if ($this->userRepo->find($message->userId) === null || !$this->mailerFrom) {
            return;
        }
        $email = (new Email())
            ->from($this->mailerFrom)
            ->to($message->email)
            ->subject('Welcome to ES Blog');
        $this->mailer->send(self::build($email, $this->twig, $message->username, $message->email, $this->urls->url('app_login')));
    }

    /** Shared with app:mail:gallery so the preview is the real email. */
    public static function build(Email $email, Environment $twig, string $name, string $address, string $loginUrl): Email
    {
        return $email
            ->html($twig->render('emails/welcome.html.twig', [
                'subject' => 'Welcome to ES Blog', 'name' => $name, 'email' => $address, 'login_url' => $loginUrl,
            ]))
            ->text(sprintf(
                "Hi %s, an author account on ES Blog has been created for you.\n\n"
                . "Sign in with your DataSparkLab account using this email address (%s):\n%s\n\n"
                . "If you were not expecting this, you can ignore this email: nobody can sign in as you without your DataSparkLab password.",
                $name, $address, $loginUrl,
            ));
    }
}
