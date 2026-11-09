<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\AgentUsage;
use App\Application\Port\Arbitration;
use App\Application\Port\ArbitrationFailed;
use App\Application\Port\CandidateArbiter;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\DecisionSource;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Pii\PiiCategory;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\Schema;
use App\Domain\Schema\Table;
use App\Infrastructure\Ai\Http\ErrorBodyRecordingHttpClient;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Confirms or rejects ambiguous candidates in two steps (config/packages/ai.yaml):
 * the "arbiter" agent investigates with its tools, then the "verdicts" agent writes structured verdicts without tools,
 * since providers reject "tools" and "response_format" in the same request.
 *
 * The prompts carry only metadata (table and column names, SQL types, the heuristic's guess) and masked code.
 */
final readonly class SymfonyAiCandidateArbiter implements CandidateArbiter
{
    /** Caps each answer of the model; leaves room for the reasoning of models such as gpt-oss. */
    private const int MAX_OUTPUT_TOKENS = 4096;

    public function __construct(
        private AgentInterface $arbiter,
        private AgentInterface $verdicts,
        private AuditSession $session,
        private ?ErrorBodyRecordingHttpClient $httpClient = null,
    ) {
    }

    public function arbitrate(string $projectPath, Schema $schema, array $flows, array $candidates): Arbitration
    {
        if ([] === $candidates) {
            return new Arbitration([]);
        }

        $this->session->start($projectPath, $schema, $flows);
        try {
            $prompt = self::prompt($schema, $candidates);
            $investigation = $this->arbiter->call(new MessageBag(Message::ofUser($prompt)), [PlatformOptions::MAX_OUTPUT_TOKENS => self::MAX_OUTPUT_TOKENS]);
            $conclusions = $investigation->asText();

            $structured = $this->verdicts->call(
                new MessageBag(Message::ofUser(\sprintf("%s\n\nInvestigator's conclusions:\n%s", $prompt, $conclusions))),
                ['response_format' => ArbitrationResult::class, PlatformOptions::MAX_OUTPUT_TOKENS => self::MAX_OUTPUT_TOKENS],
            );
            $result = $structured->asObject(ArbitrationResult::class);
            $usage = self::sum(
                self::usage($investigation->getResult()->getMetadata()->get('token_usage')),
                self::usage($structured->getResult()->getMetadata()->get('token_usage')),
            );
        } catch (\Throwable $exception) {
            $providerError = $this->httpClient?->lastError();
            throw new ArbitrationFailed(\sprintf('The agent could not arbitrate the candidates: %s%s', $exception->getMessage(), null === $providerError ? '' : \sprintf(' (provider response: %s)', $providerError)), $exception instanceof AuthenticationException, $exception);
        } finally {
            $this->session->end();
        }
        $byField = [];
        $byColumn = [];
        foreach ($result->verdicts as $verdict) {
            $byField[self::normalize($verdict->field)] = $verdict;
            $byColumn[self::normalize((string) preg_replace('/^.*\./', '', $verdict->field))][] = $verdict;
        }
        $written = self::writtenConclusions($conclusions);

        $decisions = [];
        foreach ($candidates as $candidate) {
            $reference = self::normalize((string) $candidate->column->reference);
            $column = self::normalize($candidate->column->name());
            // An unambiguous bare column name ("motif") is accepted too.
            $verdict = $byField[$reference] ?? (1 === \count($byColumn[$column] ?? []) && 1 === \count(array_filter($candidates, static fn (PiiCandidate $other): bool => self::normalize($other->column->name()) === $column)) ? $byColumn[$column][0] : null);

            $decisions[] = match (true) {
                null !== $verdict => self::decision($candidate, $verdict),
                isset($written[$reference]) => self::decision($candidate, $written[$reference]),
                default => new ClassifiedField(
                    $candidate->column,
                    $candidate->confidence >= 0.5,
                    $candidate->category,
                    $candidate->sensitivity,
                    $candidate->confidence,
                    'The agent gave no verdict: the heuristic decides. '.$candidate->reason,
                    DecisionSource::Rules,
                ),
            };
        }

        $rawVerdicts = array_map(static fn (FieldVerdict $verdict): string => \sprintf('%s → %s', '' === $verdict->field ? '(empty column)' : $verdict->field, $verdict->isPersonalData ? 'yes' : 'no'), $result->verdicts);

        return new Arbitration($decisions, $usage, trim($conclusions), $rawVerdicts);
    }

    /**
     * Reads the conclusion lines the investigator must end with, used when the verdicts agent misses a column:
     * "table.column | personal data: yes | category | sensitivity | confidence | justification".
     *
     * @return array<string, FieldVerdict>
     */
    private static function writtenConclusions(string $conclusions): array
    {
        preg_match_all('/^[^\w\n]*([\w]+\.[\w]+)[^|\n]*\|\s*personal data:\s*(yes|no|oui|non)\s*\|\s*([\w-]+)\s*\|\s*([\w-]+)\s*\|\s*(?:confidence\s*)?([\d.]+)\s*\|\s*(.+?)\s*`?$/mi', $conclusions, $lines, \PREG_SET_ORDER);

        $verdicts = [];
        foreach ($lines as [, $field, $personal, $category, $sensitivity, $confidence, $justification]) {
            $verdict = new FieldVerdict();
            $verdict->field = $field;
            $verdict->isPersonalData = \in_array(strtolower($personal), ['yes', 'oui'], true);
            $verdict->category = strtolower($category);
            $verdict->sensitivity = strtolower($sensitivity);
            $verdict->confidence = (float) $confidence;
            $verdict->justification = $justification;
            $verdicts[self::normalize($field)] = $verdict;
        }

        return $verdicts;
    }

    private static function normalize(string $field): string
    {
        return strtolower(trim($field, " \t\n\r`'\"*."));
    }

    private static function sum(?AgentUsage $first, ?AgentUsage $second): ?AgentUsage
    {
        if (null === $first || null === $second) {
            return $first ?? $second;
        }
        $add = static fn (?int $a, ?int $b): ?int => null === $a && null === $b ? null : (int) $a + (int) $b;

        return new AgentUsage($first->calls + $second->calls, $add($first->inputTokens, $second->inputTokens), $add($first->outputTokens, $second->outputTokens));
    }

    private static function usage(mixed $tokenUsage): ?AgentUsage
    {
        return match (true) {
            $tokenUsage instanceof TokenUsageAggregation => new AgentUsage($tokenUsage->count(), $tokenUsage->getPromptTokens(), $tokenUsage->getCompletionTokens()),
            $tokenUsage instanceof TokenUsageInterface => new AgentUsage(1, $tokenUsage->getPromptTokens(), $tokenUsage->getCompletionTokens()),
            default => null,
        };
    }

    private static function decision(PiiCandidate $candidate, FieldVerdict $verdict): ClassifiedField
    {
        $justification = trim($verdict->justification);

        return new ClassifiedField(
            $candidate->column,
            $verdict->isPersonalData,
            PiiCategory::tryFrom($verdict->category) ?? $candidate->category,
            Sensitivity::tryFrom($verdict->sensitivity) ?? $candidate->sensitivity,
            round(max(0.0, min(1.0, $verdict->confidence)), 2),
            '' === $justification ? 'Decided by the agent, without justification.' : $justification,
            DecisionSource::Agent,
        );
    }

    /**
     * @param list<PiiCandidate> $candidates
     */
    private static function prompt(Schema $schema, array $candidates): string
    {
        $tables = array_map(
            static fn (Table $table): string => \sprintf('- %s (%s)', $table->name, implode(', ', array_map(static fn ($column): string => $column->name(), $table->columns))),
            $schema->tables(),
        );
        $lines = array_map(static fn (PiiCandidate $candidate): string => \sprintf(
            '- %s %s: heuristic guess %s / %s sensitivity, confidence %.2f (%s)',
            $candidate->column->reference,
            $candidate->column->type,
            $candidate->category->value,
            $candidate->sensitivity->value,
            $candidate->confidence,
            $candidate->reason,
        ), $candidates);

        return \sprintf(
            "Tables of the audited application:\n%s\n\nCandidate columns to arbitrate:\n%s",
            implode("\n", $tables),
            implode("\n", $lines),
        );
    }
}
