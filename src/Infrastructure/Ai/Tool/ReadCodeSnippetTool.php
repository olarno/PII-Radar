<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Tool;

use App\Infrastructure\Ai\AuditSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Lets the agent read the code around a line. Personal data values are masked before the excerpt leaves the process (decision 6).
 */
#[AsTool('read_code_snippet', 'Reads a few lines of the audited code around a line. Personal data values are masked.')]
final readonly class ReadCodeSnippetTool
{
    private const int CONTEXT = 6;
    private const array READABLE = ['php', 'sql', 'inc', 'phtml', 'twig'];

    public function __construct(
        private AuditSession $session,
    ) {
    }

    public function __invoke(
        #[Schema(description: 'Path relative to the project root, as returned by find_column_usages, e.g. "public/patient.php"')]
        string $file,
        #[Schema(description: 'Line to center the excerpt on', minimum: 1)]
        int $line,
    ): string {
        $root = realpath($this->session->projectPath());
        $path = false === $root ? false : realpath($root.'/'.$file);
        if (false === $root || false === $path || !str_starts_with($path, $root.\DIRECTORY_SEPARATOR) || !is_file($path)
            || !\in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), self::READABLE, true)) {
            return \sprintf('"%s" is not a readable source file of the project.', $file);
        }

        $lines = file($path, \FILE_IGNORE_NEW_LINES) ?: [];
        $first = max(1, $line - self::CONTEXT);
        $last = min(\count($lines), $line + self::CONTEXT);
        if ($first > $last) {
            return \sprintf('%s has only %d lines.', $file, \count($lines));
        }

        $excerpt = [];
        for ($number = $first; $number <= $last; ++$number) {
            $excerpt[] = \sprintf('%4d | %s', $number, $lines[$number - 1]);
        }

        return $this->session->masker()->mask(\sprintf("%s, lines %d-%d:\n%s", $file, $first, $last, implode("\n", $excerpt)));
    }
}
