<?php

declare(strict_types=1);

namespace App\UI\Cli;

use App\Application\Port\RegisterRenderer;
use App\Application\Port\ReportStore;
use App\Application\Register\RegisterBuilder;
use App\Application\Register\RegisterFormat;
use App\Infrastructure\Mcp\AuditedProject;
use App\Infrastructure\Report\InvalidReport;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pii:register', description: 'Writes the draft register (Quebec Law 25 or GDPR) from the last scan.')]
final readonly class RegisterCommand
{
    public function __construct(
        private ReportStore $reports,
        private RegisterBuilder $builder,
        private RegisterRenderer $renderer,
        private AuditedProject $project,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Directory of the audited application (PII_RADAR_PROJECT by default)')] ?string $path = null,
        #[Option(description: 'Framework: law25 or gdpr')] string $format = 'law25',
        #[Option(description: 'Markdown file to write (standard output otherwise)')] ?string $output = null,
        #[Option(description: 'Application name in the register (the directory name by default)')] ?string $name = null,
    ): int {
        $registerFormat = RegisterFormat::tryFrom($format);
        if (null === $registerFormat) {
            $io->error(\sprintf('Unknown framework "%s": use law25 or gdpr.', $format));

            return Command::INVALID;
        }

        $projectPath = $path ?? $this->project->path();
        try {
            $stored = $this->reports->load($projectPath);
        } catch (InvalidReport $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        if (null === $stored) {
            $io->error(\sprintf('No scan of %s: run "bin/console pii:scan %s" first.', $projectPath, $projectPath));

            return Command::FAILURE;
        }

        $markdown = $this->renderer->render($this->builder->build($stored, $name), $registerFormat);
        if (null === $output) {
            $io->writeln($markdown, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        if (false === file_put_contents($output, $markdown)) {
            $io->error(\sprintf('Cannot write %s.', $output));

            return Command::FAILURE;
        }
        $io->success(\sprintf('%s register written to %s.', RegisterFormat::Gdpr === $registerFormat ? 'GDPR' : 'Law 25', $output));

        return Command::SUCCESS;
    }
}
