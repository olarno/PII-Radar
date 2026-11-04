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

#[AsCommand(name: 'pii:register', description: 'Génère le brouillon de registre (Loi 25 ou RGPD) à partir du dernier scan.')]
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
        #[Argument(description: 'Dossier de l\'application auditée (par défaut PII_RADAR_PROJECT)')] ?string $path = null,
        #[Option(description: 'Cadre : loi25 ou rgpd')] string $format = 'loi25',
        #[Option(description: 'Fichier Markdown à écrire (sinon, sortie standard)')] ?string $output = null,
        #[Option(description: 'Nom de l\'application dans le registre (par défaut, le nom du dossier)')] ?string $name = null,
    ): int {
        $registerFormat = RegisterFormat::tryFrom($format);
        if (null === $registerFormat) {
            $io->error(\sprintf('Cadre « %s » inconnu : utilisez loi25 ou rgpd.', $format));

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
            $io->error(\sprintf('Aucun scan de %s : lancez d\'abord « bin/console pii:scan %s ».', $projectPath, $projectPath));

            return Command::FAILURE;
        }

        $markdown = $this->renderer->render($this->builder->build($stored, $name), $registerFormat);
        if (null === $output) {
            $io->writeln($markdown, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        if (false === file_put_contents($output, $markdown)) {
            $io->error(\sprintf('Impossible d\'écrire %s.', $output));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Registre %s écrit dans %s.', 'rgpd' === $registerFormat->value ? 'RGPD' : 'Loi 25', $output));

        return Command::SUCCESS;
    }
}
