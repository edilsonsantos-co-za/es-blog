<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendWelcomeEmailMessage;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final class SendWelcomeEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepo,
    ) {}

    public function __invoke(SendWelcomeEmailMessage $message): void
    {
        $user = $this->userRepo->find($message->userId);

        if ($user === null) {
            return;
        }

        $html = sprintf(
            '<h1>Welcome to ES Blog, %s!</h1>'
            . '<p>Your account has been created successfully.</p>'
            . '<p>We\'re thrilled to have you. Start exploring posts or create your own!</p>'
            . '<p>— The ES Blog Team</p>',
            htmlspecialchars($message->username, ENT_QUOTES, 'UTF-8'),
        );

        $email = (new Email())
            ->to($message->email)
            ->subject('Welcome to ES Blog!')
            ->html($html)
            ->text(sprintf(
                "Welcome to ES Blog, %s!\n\nYour account has been created successfully.\n\nWe're thrilled to have you.\n\n— The ES Blog Team",
                $message->username,
            ));

        $this->mailer->send($email);
    }
}
