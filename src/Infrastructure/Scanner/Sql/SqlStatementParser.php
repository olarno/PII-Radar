<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner\Sql;

use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;

/**
 * Reads the shape of an SQL statement built by PHP code: operation, table, columns, and which values are concatenated.
 *
 * A pragmatic reader for the statements legacy code writes by hand, not a full SQL grammar.
 */
final class SqlStatementParser
{
    private const string MARKER = "\u{1}%d\u{1}";
    private const string MARKER_PATTERN = "/\u{1}(\\d+)\u{1}/";

    private const array OPERATIONS = [
        'SELECT' => FlowOperation::Read,
        'INSERT' => FlowOperation::Store,
        'REPLACE' => FlowOperation::Store,
        'UPDATE' => FlowOperation::Store,
        'DELETE' => FlowOperation::Delete,
    ];

    private const string NOT_AN_ALIAS = '/^(?:WHERE|SET|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|ON|ORDER|GROUP|LIMIT|VALUES|VALUE|SELECT|HAVING|USING|UNION|NATURAL)$/i';

    public function parse(SqlFragment $fragment, Schema $schema): ?SqlStatement
    {
        $placeholders = [];
        $sql = '';
        foreach ($fragment->parts as $part) {
            if (\is_string($part)) {
                $sql .= $part;
            } elseif ($part->safe) {
                $sql .= '0';
            } else {
                $sql .= \sprintf(self::MARKER, \count($placeholders));
                $placeholders[] = $part;
            }
        }
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        if (1 !== preg_match('/^(\w+)/', $sql, $keyword) || !isset(self::OPERATIONS[strtoupper($keyword[1])])) {
            return null;
        }
        $operation = self::OPERATIONS[strtoupper($keyword[1])];

        $aliases = $this->tables($sql, $schema);
        if ([] === $aliases) {
            return null;
        }
        $table = array_values($aliases)[0];

        $fields = [];
        $row = [];
        $concatenated = [];
        switch (strtoupper($keyword[1])) {
            case 'SELECT':
                [$fields, $row] = $this->selectedColumns($sql, $aliases, $schema);
                break;
            case 'INSERT':
            case 'REPLACE':
                if (1 === preg_match('/\bINTO\s+\S+\s*\((.*?)\)\s*VALUES?\s*\((.*)\)/is', $sql, $insert)) {
                    $columns = array_map('trim', $this->splitTopLevel($insert[1]));
                    foreach ($this->splitTopLevel($insert[2]) as $index => $value) {
                        $field = $this->column($columns[$index] ?? '', $aliases, $schema);
                        if (null === $field) {
                            continue;
                        }
                        $fields[] = $field;
                        if (1 === preg_match(self::MARKER_PATTERN, $value)) {
                            $concatenated[] = $field;
                        }
                    }
                    break;
                }
                // INSERT ... SET a = b is written like an UPDATE.
                // no break
            case 'UPDATE':
                if (1 === preg_match('/\bSET\s+(.*?)(?:\bWHERE\b|$)/is', $sql, $set)) {
                    foreach ($this->splitTopLevel($set[1]) as $assignment) {
                        $field = $this->column(trim(explode('=', $assignment, 2)[0]), $aliases, $schema);
                        if (null !== $field) {
                            $fields[] = $field;
                        }
                    }
                }
                break;
            case 'DELETE':
                $fields = $schema->table($table)->columns ?? [];
                $fields = array_map(static fn ($column): FieldReference => $column->reference, $fields);
                break;
        }

        // "column = '<value>'" anywhere (SET, WHERE, ON): the value lands in that column.
        preg_match_all("/([\\w.`]+)\\s*(?:=|<>|!=|<=|>=|<|>|\\bLIKE\\b)\\s*'?\u{1}(\\d+)\u{1}/i", $sql, $comparisons, \PREG_SET_ORDER);
        foreach ($comparisons as $comparison) {
            $field = $this->column($comparison[1], $aliases, $schema);
            if (null !== $field) {
                $concatenated[] = $field;
            }
        }
        foreach ($placeholders as $placeholder) {
            array_push($concatenated, ...$placeholder->fields);
        }

        return new SqlStatement($operation, $table, FlowPoint::unique($fields), $row, FlowPoint::unique($concatenated));
    }

    /**
     * @return array<string, string> alias (or table name) => table name, for tables known to the schema, in order of appearance
     */
    private function tables(string $sql, Schema $schema): array
    {
        preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?(?:\s+(?:AS\s+)?`?(\w+)`?)?/i', $sql, $matches, \PREG_SET_ORDER);

        $aliases = [];
        foreach ($matches as $match) {
            if (null === $schema->table($match[1])) {
                continue;
            }
            $aliases[$match[1]] = $match[1];
            if (isset($match[2]) && 1 !== preg_match(self::NOT_AN_ALIAS, $match[2])) {
                $aliases[$match[2]] = $match[1];
            }
        }

        return $aliases;
    }

    /**
     * @param array<string, string> $aliases
     *
     * @return array{list<FieldReference>, array<string, FieldReference>}
     */
    private function selectedColumns(string $sql, array $aliases, Schema $schema): array
    {
        if (1 !== preg_match('/^SELECT\s+(?:DISTINCT\s+)?(.*?)\s+FROM\s/i', $sql, $select)) {
            return [[], []];
        }

        $row = [];
        foreach ($this->splitTopLevel($select[1]) as $item) {
            $item = trim($item);
            if (1 === preg_match('/^(?:`?(\w+)`?\.)?\*$/', $item, $star)) {
                $tables = '' !== ($star[1] ?? '') ? [$aliases[$star[1]] ?? $star[1]] : array_unique(array_values($aliases));
                foreach ($tables as $table) {
                    foreach ($schema->table($table)->columns ?? [] as $column) {
                        $row[$column->name()] ??= $column->reference;
                    }
                }
                continue;
            }
            if (1 !== preg_match('/^([\w.`]+)(?:\s+(?:AS\s+)?`?(\w+)`?)?$/i', $item, $column)) {
                continue;
            }
            $field = $this->column($column[1], $aliases, $schema);
            if (null !== $field) {
                $row[$column[2] ?? $field->column] = $field;
            }
        }

        return [array_values($row), $row];
    }

    /**
     * @param array<string, string> $aliases
     */
    private function column(string $token, array $aliases, Schema $schema): ?FieldReference
    {
        $token = str_replace('`', '', $token);
        if (str_contains($token, '.')) {
            [$alias, $name] = explode('.', $token, 2);
            $tables = isset($aliases[$alias]) ? [$aliases[$alias]] : [];
        } else {
            $name = $token;
            $tables = array_unique(array_values($aliases));
        }

        foreach ($tables as $table) {
            foreach ($schema->table($table)->columns ?? [] as $column) {
                if ($column->name() === $name) {
                    return $column->reference;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevel(string $list): array
    {
        $items = [];
        $depth = 0;
        $quote = null;
        $current = '';
        for ($i = 0, $length = \strlen($list); $i < $length; ++$i) {
            $char = $list[$i];
            if (null !== $quote) {
                $quote = $char === $quote ? null : $quote;
            } elseif ("'" === $char || '"' === $char) {
                $quote = $char;
            } elseif ('(' === $char) {
                ++$depth;
            } elseif (')' === $char) {
                --$depth;
            } elseif (',' === $char && 0 === $depth) {
                $items[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $items[] = $current;

        return $items;
    }
}
