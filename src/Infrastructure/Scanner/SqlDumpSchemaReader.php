<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner;

use App\Application\Port\SchemaReader;
use App\Domain\Schema\Column;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;
use App\Domain\Schema\Table;
use App\Domain\SourceLocation;
use Symfony\Component\Finder\Finder;

/**
 * Reads CREATE TABLE statements from the .sql files of the audited project (hand-written schemas or mysqldump output).
 */
final class SqlDumpSchemaReader implements SchemaReader
{
    private const string CREATE_TABLE = '/CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:[`"]?\w+[`"]?\.)?[`"]?(\w+)[`"]?\s*\(/i';

    /** Table-level definitions that are not columns. */
    private const string CONSTRAINT = '/^(?:PRIMARY|KEY|INDEX|UNIQUE|CONSTRAINT|FOREIGN|FULLTEXT|SPATIAL|CHECK)\b/i';

    private const string COLUMN = '/^[`"]?(\w+)[`"]?\s+(\w+(?:\s*\([^)]*\))?(?:\s+UNSIGNED)?)/i';

    public function read(string $projectPath): Schema
    {
        if (!is_dir($projectPath)) {
            throw new \InvalidArgumentException(\sprintf('The project directory "%s" does not exist.', $projectPath));
        }

        $files = (new Finder())
            ->files()
            ->in($projectPath)
            ->name('*.sql')
            ->exclude(['vendor', 'node_modules'])
            ->sortByName();

        $tables = [];
        foreach ($files as $file) {
            array_push($tables, ...$this->parse($file->getContents(), str_replace('\\', '/', $file->getRelativePathname())));
        }

        return new Schema($tables);
    }

    /**
     * @return list<Table>
     */
    public function parse(string $sql, string $file): array
    {
        preg_match_all(self::CREATE_TABLE, $sql, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        $tables = [];
        foreach ($matches as $match) {
            [$statement, $offset] = $match[0];
            $name = $match[1][0];
            $bodyStart = $offset + \strlen($statement);
            $bodyEnd = $this->closingParenthesis($sql, $bodyStart);
            if (null === $bodyEnd) {
                continue;
            }

            $columns = [];
            foreach ($this->splitDefinitions($sql, $bodyStart, $bodyEnd) as [$definition, $definitionOffset]) {
                if (1 === preg_match(self::CONSTRAINT, $definition) || 1 !== preg_match(self::COLUMN, $definition, $column)) {
                    continue;
                }

                $columns[] = new Column(
                    new FieldReference($name, $column[1]),
                    strtoupper((string) preg_replace('/\s+/', ' ', $column[2])),
                    1 !== preg_match('/\bNOT\s+NULL\b|\bPRIMARY\s+KEY\b/i', $definition),
                    new SourceLocation($file, self::lineAt($sql, $definitionOffset)),
                );
            }

            $tables[] = new Table($name, $columns, new SourceLocation($file, self::lineAt($sql, $offset)));
        }

        return $tables;
    }

    /**
     * Finds the parenthesis closing the table body, ignoring parentheses inside quotes and comments.
     */
    private function closingParenthesis(string $sql, int $from): ?int
    {
        $depth = 1;
        for ($i = $from, $length = \strlen($sql); $i < $length; ++$i) {
            $skipped = $this->skipQuotedOrComment($sql, $i);
            if (null !== $skipped) {
                $i = $skipped;
                continue;
            }
            if ('(' === $sql[$i]) {
                ++$depth;
            } elseif (')' === $sql[$i] && 0 === --$depth) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Splits the table body on top-level commas.
     *
     * @return list<array{string, int}> each definition, trimmed and without comments, with the offset where it starts
     */
    private function splitDefinitions(string $sql, int $from, int $to): array
    {
        $definitions = [];
        $start = $from;
        $depth = 0;
        for ($i = $from; $i <= $to; ++$i) {
            $skipped = $i < $to ? $this->skipQuotedOrComment($sql, $i) : null;
            if (null !== $skipped) {
                $i = $skipped;
                continue;
            }
            $char = $i < $to ? $sql[$i] : ',';
            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char) {
                --$depth;
            } elseif (',' === $char && 0 === $depth) {
                $definition = $this->definitionAt($sql, $start, $i);
                if (null !== $definition) {
                    $definitions[] = $definition;
                }
                $start = $i + 1;
            }
        }

        return $definitions;
    }

    /**
     * @return array{string, int}|null
     */
    private function definitionAt(string $sql, int $start, int $end): ?array
    {
        $raw = substr($sql, $start, $end - $start);
        // Leading comments and blank lines are not part of the definition.
        $cleaned = (string) preg_replace('/^(?:\s+|--[^\n]*\n|#[^\n]*\n|\/\*.*?\*\/)*/s', '', $raw);
        if ('' === trim($cleaned)) {
            return null;
        }

        return [trim($cleaned), $start + \strlen($raw) - \strlen($cleaned)];
    }

    /**
     * When a quoted string, an identifier or a comment starts at $i, returns the offset of its last character.
     */
    private function skipQuotedOrComment(string $sql, int $i): ?int
    {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ("'" === $char || '"' === $char || '`' === $char) {
            for ($j = $i + 1, $length = \strlen($sql); $j < $length; ++$j) {
                if ('\\' === $sql[$j] && '`' !== $char) {
                    ++$j;
                } elseif ($sql[$j] === $char) {
                    return $j;
                }
            }

            return \strlen($sql) - 1;
        }
        if (('-' === $char && '-' === $next) || '#' === $char) {
            $end = strpos($sql, "\n", $i);

            return false === $end ? \strlen($sql) - 1 : $end;
        }
        if ('/' === $char && '*' === $next) {
            $end = strpos($sql, '*/', $i + 2);

            return false === $end ? \strlen($sql) - 1 : $end + 1;
        }

        return null;
    }

    private static function lineAt(string $sql, int $offset): int
    {
        return substr_count($sql, "\n", 0, $offset) + 1;
    }
}
