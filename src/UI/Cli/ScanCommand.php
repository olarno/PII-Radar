<?php

declare(strict_types=1);

namespace App\UI\Cli;

use App\Application\DetectPiiCandidates;
use App\Domain\Pii\PiiCandidate;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pii:scan', description: 'Scanne une application PHP et liste où elle stocke des données personnelles.')]
final readonly class ScanCommand
{
    public function __construct(
        private DetectPiiCandidates $detectPiiCandidates,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Dossier racine de l\'application auditée')] string $path,
        #[Option(description: 'Format de sortie : table ou json')] string $format = 'table',
    ): int {
        if (!\in_array($format, ['table', 'json'], true)) {
            $io->error(\sprintf('Format "%s" inconnu : utilisez table ou json.', $format));

            return Command::INVALID;
        }

        try {
            $result = ($this->detectPiiCandidates)($path);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $tableCount = \count($result->schema->tables());
        $columnCount = \count($result->schema->columns());

        if ('json' === $format) {
            $io->writeln(json_encode([
                'schema' => ['tables' => $tableCount, 'columns' => $columnCount],
                'candidates' => array_map(static fn (PiiCandidate $candidate): array => [
                    'field' => (string) $candidate->column->reference,
                    'type' => $candidate->column->type,
                    'category' => $candidate->category->value,
                    'sensitivity' => $candidate->sensitivity->value,
                    'confidence' => $candidate->confidence,
                    'location' => ['file' => $candidate->column->location->file, 'line' => $candidate->column->location->line],
                    'reason' => $candidate->reason,
                ], $result->candidates),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->title('PII Radar · schéma');
        $io->text(\sprintf('%d tables, %d colonnes lues. %d colonnes candidates.', $tableCount, $columnCount, \count($result->candidates)));
        $io->table(
            ['Champ', 'Catégorie', 'Sensibilité', 'Confiance', 'Emplacement'],
            array_map(static fn (PiiCandidate $candidate): array => [
                (string) $candidate->column->reference,
                $candidate->category->label(),
                $candidate->sensitivity->label(),
                \sprintf('%d %%', (int) round($candidate->confidence * 100)),
                (string) $candidate->column->location,
            ], $result->candidates),
        );
        $io->note('Candidats issus des noms de colonnes : l\'agent les confirmera ou les écartera (étape 4).');

        return Command::SUCCESS;
    }
}
