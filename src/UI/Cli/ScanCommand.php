<?php

declare(strict_types=1);

namespace App\UI\Cli;

use App\Application\ScanProject;
use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Schema\FieldReference;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pii:scan', description: 'Scanne une application PHP et liste où elle stocke des données personnelles.')]
final readonly class ScanCommand
{
    public function __construct(
        private ScanProject $scanProject,
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
            $report = ($this->scanProject)($path);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $tableCount = \count($report->schema->tables());
        $columnCount = \count($report->schema->columns());

        if ('json' === $format) {
            $io->writeln(json_encode([
                'schema' => ['tables' => $tableCount, 'columns' => $columnCount],
                'findings' => array_map(static fn (Finding $finding): array => [
                    'rule' => $finding->rule,
                    'severity' => $finding->severity->value,
                    'location' => ['file' => $finding->location->file, 'line' => $finding->location->line],
                    'fields' => self::names($finding->fields),
                    'summary' => $finding->summary,
                    'remediation' => $finding->remediation,
                    'recipient' => $finding->recipient,
                    'jurisdiction' => $finding->jurisdiction,
                ], $report->findings),
                'candidates' => array_map(static fn (PiiCandidate $candidate): array => [
                    'field' => (string) $candidate->column->reference,
                    'type' => $candidate->column->type,
                    'category' => $candidate->category->value,
                    'sensitivity' => $candidate->sensitivity->value,
                    'confidence' => $candidate->confidence,
                    'location' => ['file' => $candidate->column->location->file, 'line' => $candidate->column->location->line],
                    'reason' => $candidate->reason,
                ], $report->candidates),
                'flows' => array_map(static fn (FlowPoint $flow): array => [
                    'operation' => $flow->operation->value,
                    'fields' => self::names($flow->fields),
                    'location' => ['file' => $flow->location->file, 'line' => $flow->location->line],
                    'target' => $flow->target,
                    'concatenated_fields' => self::names($flow->concatenatedFields),
                ], $report->flows),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->title('PII Radar');

        $io->section('Findings');
        $io->text(\sprintf('%d findings.', \count($report->findings)));
        $io->table(
            ['Règle', 'Sévérité', 'Emplacement', 'Constat', 'Correctif'],
            array_map(static fn (Finding $finding): array => [
                $finding->rule,
                $finding->severity->label(),
                (string) $finding->location,
                wordwrap($finding->summary, 60),
                wordwrap($finding->remediation, 50),
            ], $report->findings),
        );

        $io->section('Schéma');
        $io->text(\sprintf('%d tables, %d colonnes lues. %d colonnes candidates.', $tableCount, $columnCount, \count($report->candidates)));
        $io->table(
            ['Champ', 'Catégorie', 'Sensibilité', 'Confiance', 'Emplacement'],
            array_map(static fn (PiiCandidate $candidate): array => [
                (string) $candidate->column->reference,
                $candidate->category->label(),
                $candidate->sensitivity->label(),
                \sprintf('%d %%', (int) round($candidate->confidence * 100)),
                (string) $candidate->column->location,
            ], $report->candidates),
        );

        $io->section('Flux dans le code');
        $io->text(\sprintf('%d points de flux.', \count($report->flows)));
        $io->table(
            ['Opération', 'Emplacement', 'Cible', 'Champs', 'Concaténés en SQL'],
            array_map(static fn (FlowPoint $flow): array => [
                $flow->operation->label(),
                (string) $flow->location,
                $flow->target ?? '',
                implode("\n", self::names($flow->fields)),
                implode("\n", self::names($flow->concatenatedFields)),
            ], $report->flows),
        );
        $io->note('Candidats issus des noms de colonnes : l\'agent les confirmera ou les écartera (étape 4).');

        return Command::SUCCESS;
    }

    /**
     * @param list<FieldReference> $fields
     *
     * @return list<string>
     */
    private static function names(array $fields): array
    {
        return array_map(static fn (FieldReference $field): string => (string) $field, $fields);
    }
}
