<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Port\ArbitrationFailed;
use App\Application\Port\CandidateArbiter;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\DecisionSource;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Pii\PiiCategory;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\Schema;
use App\Domain\Schema\Table;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Asks the "arbiter" agent (config/packages/ai.yaml) to confirm or reject ambiguous candidates.
 *
 * The prompt carries only metadata: table and column names, SQL types, the heuristic's guess.
 */
final readonly class SymfonyAiCandidateArbiter implements CandidateArbiter
{
    public function __construct(
        private AgentInterface $arbiter,
        private AuditSession $session,
    ) {
    }

    public function arbitrate(string $projectPath, Schema $schema, array $flows, array $candidates): array
    {
        if ([] === $candidates) {
            return [];
        }

        $this->session->start($projectPath, $schema, $flows);
        try {
            $result = $this->arbiter
                ->call(new MessageBag(Message::ofUser(self::prompt($schema, $candidates))), ['response_format' => ArbitrationResult::class])
                ->asObject(ArbitrationResult::class);
        } catch (\Throwable $exception) {
            throw new ArbitrationFailed(\sprintf('L\'agent n\'a pas pu arbitrer les candidats : %s', $exception->getMessage()), previous: $exception);
        } finally {
            $this->session->end();
        }
        $verdicts = [];
        foreach ($result->verdicts as $verdict) {
            $verdicts[trim($verdict->field)] = $verdict;
        }

        return array_map(static fn (PiiCandidate $candidate): ClassifiedField => isset($verdicts[(string) $candidate->column->reference])
            ? self::decision($candidate, $verdicts[(string) $candidate->column->reference])
            : new ClassifiedField(
                $candidate->column,
                $candidate->confidence >= 0.5,
                $candidate->category,
                $candidate->sensitivity,
                $candidate->confidence,
                'L\'agent n\'a pas rendu de verdict : décision de l\'heuristique. '.$candidate->reason,
                DecisionSource::Rules,
            ), $candidates);
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
