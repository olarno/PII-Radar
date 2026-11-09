<?php

declare(strict_types=1);

namespace App\UI\Cli;

use App\Application\Port\ArbitrationFailed;
use App\Application\ScanProject;
use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Schema\FieldReference;
use App\Infrastructure\Ai\AgentReplay;
use App\Infrastructure\Report\ScanReportJson;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pii:scan', description: 'Scans a PHP application and lists where it stores personal data.')]
final readonly class ScanCommand
{
    public function __construct(
        private ScanProject $scanProject,
        private ScanReportJson $json,
        private AgentReplay $replay,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Root folder of the audited application')] string $path,
        #[Option(description: 'Output format: table or json')] string $format = 'table',
        #[Option(name: 'no-ai', description: 'Without the agent: the heuristic alone decides ambiguous candidates')] bool $noAi = false,
        #[Option(description: 'Demo without a token: replays a real recorded Hugging Face call on demo/legacy-app')] bool $replay = false,
    ): int {
        if (!\in_array($format, ['table', 'json'], true)) {
            $io->error(\sprintf('Unknown format "%s": use table or json.', $format));

            return Command::INVALID;
        }

        if ($replay && $noAi) {
            $io->error('--replay replays the agent: it cannot be combined with --no-ai.');

            return Command::INVALID;
        }

        try {
            if ($replay) {
                $this->replay->start($path);
            }
            $report = ($this->scanProject)($path, !$noAi, $replay);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } catch (ArbitrationFailed $exception) {
            $io->error([
                $exception->getMessage(),
                $exception->authentication
                    ? 'Check HUGGINGFACE_API_KEY in .env.local, or run again with --no-ai for a scan without the agent.'
                    : 'The provider rejected the request. Try another model (PII_RADAR_MODEL), or run again with --no-ai for a scan without the agent.',
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
            ['Rule', 'Severity', 'Location', 'Finding', 'Fix'],
            array_map(static fn (Finding $finding): array => [
                $finding->rule,
                $finding->severity->label(),
                (string) $finding->location,
                wordwrap($finding->summary, 60),
                wordwrap($finding->remediation, 50),
            ], $report->findings),
        );

        $io->section('Personal data');
        $io->text(\sprintf(
            '%d tables, %d columns read, %d candidates. %s',
            $tableCount,
            $columnCount,
            \count($report->candidates),
            match (true) {
                $report->replayed => 'Ambiguous candidates were arbitrated by the agent, answers replayed from a real recorded call.',
                $report->arbitrated => 'Ambiguous candidates were arbitrated by the agent.',
                default => 'Without the agent: the heuristic decided alone.',
            },
        ));
        $io->table(
            ['Field', 'Personal', 'Category', 'Sensitivity', 'Confidence', 'Decided by', 'Justification'],
            array_map(static fn (ClassifiedField $field): array => [
                (string) $field->column->reference,
                $field->personal ? 'Yes' : 'No',
                $field->category->label(),
                $field->sensitivity->label(),
                \sprintf('%d%%', (int) round($field->confidence * 100)),
                'agent' === $field->decidedBy->value ? 'Agent' : 'Rules',
                wordwrap($field->justification, 50),
            ], $report->classifiedFields),
        );

        $io->section('Code flows');
        $io->text(\sprintf('%d flow points.', \count($report->flows)));
        $io->table(
            ['Operation', 'Location', 'Target', 'Fields', 'Concatenated in SQL'],
            array_map(static fn (FlowPoint $flow): array => [
                $flow->operation->label(),
                (string) $flow->location,
                $flow->target ?? '',
                implode("\n", self::names($flow->fields)),
                implode("\n", self::names($flow->concatenatedFields)),
            ], $report->flows),
        );

        if (null !== $report->arbitration && $io->isVerbose()) {
            $io->section('Agent: investigation conclusions');
            $io->text('' === (string) $report->arbitration->conclusions ? '(no written conclusion)' : (string) $report->arbitration->conclusions);
            $io->section('Agent: structured verdicts received');
            $io->listing([] === $report->arbitration->rawVerdicts ? ['(no verdict)'] : $report->arbitration->rawVerdicts);
        }

        if ($report->replayed) {
            $io->note(\sprintf('Replay: the model answers come from %s, a real recorded Hugging Face call. No key, no network call, no cost.', basename($this->replay->recording())));
        } elseif (null !== $report->agentUsage) {
            $io->note(\sprintf(
                'Agent: %d model call(s), %s input tokens, %s output tokens.',
                $report->agentUsage->calls,
                null === $report->agentUsage->inputTokens ? '?' : number_format($report->agentUsage->inputTokens),
                null === $report->agentUsage->outputTokens ? '?' : number_format($report->agentUsage->outputTokens),
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
