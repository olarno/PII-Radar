<?php

declare(strict_types=1);

namespace App\UI\Http;

use App\Application\Port\RegisterRenderer;
use App\Application\Query\AuditQueries;
use App\Application\Register\RegisterBuilder;
use App\Application\Register\RegisterFormat;
use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Infrastructure\Mcp\AuditedProject;
use App\Infrastructure\Mcp\NoReport;
use App\Infrastructure\Report\MermaidFlowMap;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The web report of the last scan: findings, personal data, flow map, the journey of each field, the register.
 */
final readonly class ReportController
{
    public function __construct(
        private AuditedProject $project,
        private AuditQueries $queries,
        private MermaidFlowMap $flowMap,
        private RegisterBuilder $registerBuilder,
        private RegisterRenderer $registerRenderer,
        private Environment $twig,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return new RedirectResponse('/report');
    }

    #[Route('/report', name: 'report', methods: ['GET'])]
    public function index(): Response
    {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            return $this->noScan($exception);
        }
        $report = $stored->report;

        $bySeverity = [];
        foreach (array_reverse(Severity::cases()) as $severity) {
            $bySeverity[$severity->value] = ['label' => $severity->label(), 'count' => \count(array_filter($report->findings, static fn (Finding $finding): bool => $finding->severity === $severity))];
        }

        return $this->render('report/index.html.twig', [
            'stored' => $stored,
            'report' => $report,
            'by_severity' => $bySeverity,
            'personal' => array_values(array_filter($report->classifiedFields, static fn ($field): bool => $field->personal)),
            'rejected' => array_values(array_filter($report->classifiedFields, static fn ($field): bool => !$field->personal)),
            'flow_map' => $this->flowMap->project($report),
        ]);
    }

    #[Route('/report/field/{table}/{column}', name: 'report_field', requirements: ['table' => '\w+', 'column' => '\w+'], methods: ['GET'])]
    public function field(string $table, string $column): Response
    {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            return $this->noScan($exception);
        }

        $columns = $this->queries->resolve($stored->report, $table.'.'.$column);
        if (1 !== \count($columns) || (string) $columns[0]->reference !== $table.'.'.$column) {
            throw new NotFoundHttpException(\sprintf('La colonne %s.%s n\'existe pas dans le schéma audité.', $table, $column));
        }
        $sheet = $this->queries->sheet($stored->report, $columns[0]);

        return $this->render('report/field.html.twig', [
            'stored' => $stored,
            'sheet' => $sheet,
            'can_log' => $this->queries->canLog($stored->report, $columns[0]),
            'flow_map' => $this->flowMap->field($sheet, $stored->report),
        ]);
    }

    #[Route('/report/registre-{format}.md', name: 'report_register', requirements: ['format' => 'loi25|rgpd'], methods: ['GET'])]
    public function register(string $format): Response
    {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            return $this->noScan($exception);
        }

        $registerFormat = RegisterFormat::from($format);

        return new Response($this->registerRenderer->render($this->registerBuilder->build($stored), $registerFormat), Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="registre-%s.md"', $registerFormat->value),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        return new Response($this->twig->render($template, $context));
    }

    private function noScan(NoReport $exception): Response
    {
        return new Response($this->twig->render('report/no_scan.html.twig', ['message' => $exception->getMessage()]));
    }
}
