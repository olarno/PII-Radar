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
            throw new ArbitrationFailed(\sprintf('L\'agent n\'a pas pu arbitrer les candidats : %s%s', $exception->getMessage(), null === $providerError ? '' : \sprintf(' (réponse du fournisseur : %s)', $providerError)), $exception instanceof AuthenticationException, $exception);
        } finally {
            $this->session->end();
        }
        $verdicts = [];
        foreach ($result->verdicts as $verdict) {
            $verdicts[trim($verdict->field)] = $verdict;
        }

        return new Arbitration(array_map(static fn (PiiCandidate $candidate): ClassifiedField => isset($verdicts[(string) $candidate->column->reference])
            ? self::decision($candidate, $verdicts[(string) $candidate->column->reference])
            : new ClassifiedField(
                $candidate->column,
                $candidate->confidence >= 0.5,
                $candidate->category,
                $candidate->sensitivity,
                $candidate->confidence,
                'L\'agent n\'a pas rendu de verdict : décision de l\'heuristique. '.$candidate->reason,
                DecisionSource::Rules,
            ), $candidates), $usage);
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
            '' === $justification ? 'Décision de l\'agent, sans justification.' : $justification,
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
