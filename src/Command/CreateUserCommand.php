<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\UserRole;
use App\Service\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create an application user from the console',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserService $userService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username used to sign in')
            ->addArgument('email', InputArgument::REQUIRED, 'User email address')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'User role: admin, editor, or author', UserRole::Admin->value)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Plain-text password. Omit to be prompted securely.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = trim((string) $input->getArgument('username'));
        $email = trim((string) $input->getArgument('email'));
        $roleValue = trim((string) $input->getOption('role'));
        $password = (string) ($input->getOption('password') ?? '');

        if ($username === '' || $email === '') {
            $io->error('Username and email are required.');
            return Command::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Email address is invalid.');
            return Command::FAILURE;
        }

        try {
            $role = UserRole::from($roleValue);
        } catch (\ValueError) {
            $io->error('Role must be one of: admin, editor, author.');
            return Command::FAILURE;
        }

        if ($this->userService->usernameExists($username)) {
            $io->error(sprintf('Username "%s" already exists.', $username));
            return Command::FAILURE;
        }

        if ($this->userService->emailExists($email)) {
            $io->error(sprintf('Email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        if ($password === '') {
            $question = new Question('Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) $this->getHelper('question')->ask($input, $output, $question);
        }

        if ($password === '') {
            $io->error('Password is required.');
            return Command::FAILURE;
        }

        $user = $this->userService->create($username, $password, $role, $email);

        $io->success(sprintf('Created %s user "%s" with id %d.', $role->value, $user->getUsername(), $user->getId()));

        return Command::SUCCESS;
    }
}
