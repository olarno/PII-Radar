<?php

declare(strict_types=1);

namespace App\UI\Cli;

use App\Application\Port\ArbitrationFailed;
use App\Application\ScanProject;
use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Schema\FieldReference;
use App\Infrastructure\Report\ScanReportJson;
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
        private ScanReportJson $json,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Dossier racine de l\'application auditée')] string $path,
        #[Option(description: 'Format de sortie : table ou json')] string $format = 'table',
        #[Option(name: 'no-ai', description: 'Sans l\'agent : l\'heuristique décide seule des candidats ambigus')] bool $noAi = false,
    ): int {
        if (!\in_array($format, ['table', 'json'], true)) {
            $io->error(\sprintf('Format "%s" inconnu : utilisez table ou json.', $format));

            return Command::INVALID;
        }

        try {
            $report = ($this->scanProject)($path, !$noAi);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } catch (ArbitrationFailed $exception) {
            $io->error([
                $exception->getMessage(),
                $exception->authentication
                    ? 'Vérifiez HUGGINGFACE_API_KEY dans .env.local, ou relancez avec --no-ai pour un scan sans agent.'
                    : 'Le fournisseur a refusé la requête. Essayez un autre modèle (PII_RADAR_MODEL), ou relancez avec --no-ai pour un scan sans agent.',
            ]);

            return Command::FAILURE;
        }

        $tableCount = \count($report->schema->tables());
        $columnCount = \count($report->schema->columns());

        if ('json' === $format) {
            $io->writeln(json_encode($this->json->normalize($report), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

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

        $io->section('Données personnelles');
        $io->text(\sprintf(
            '%d tables, %d colonnes lues, %d candidates. %s',
            $tableCount,
            $columnCount,
            \count($report->candidates),
            $report->arbitrated ? 'Les candidats ambigus ont été arbitrés par l\'agent.' : 'Sans agent : l\'heuristique a décidé seule.',
        ));
        $io->table(
            ['Champ', 'Donnée perso.', 'Catégorie', 'Sensibilité', 'Confiance', 'Décidé par', 'Justification'],
            array_map(static fn (ClassifiedField $field): array => [
                (string) $field->column->reference,
                $field->personal ? 'Oui' : 'Non',
                $field->category->label(),
                $field->sensitivity->label(),
                \sprintf('%d %%', (int) round($field->confidence * 100)),
                'agent' === $field->decidedBy->value ? 'Agent' : 'Règles',
                wordwrap($field->justification, 50),
            ], $report->classifiedFields),
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

        if (null !== $report->arbitration && $io->isVerbose()) {
            $io->section('Agent : conclusions de l\'enquête');
            $io->text('' === (string) $report->arbitration->conclusions ? '(aucune conclusion écrite)' : (string) $report->arbitration->conclusions);
            $io->section('Agent : verdicts structurés reçus');
            $io->listing([] === $report->arbitration->rawVerdicts ? ['(aucun verdict)'] : $report->arbitration->rawVerdicts);
        }

        if (null !== $report->agentUsage) {
            $io->note(\sprintf(
                'Agent : %d appel(s) au modèle, %s tokens en entrée, %s en sortie.',
                $report->agentUsage->calls,
                null === $report->agentUsage->inputTokens ? '?' : number_format($report->agentUsage->inputTokens, 0, ',', ' '),
                null === $report->agentUsage->outputTokens ? '?' : number_format($report->agentUsage->outputTokens, 0, ',', ' '),
            ));
        }

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
