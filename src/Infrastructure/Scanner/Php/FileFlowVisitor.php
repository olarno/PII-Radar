<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner\Php;

use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;
use App\Domain\SourceLocation;
use App\Infrastructure\Scanner\Sql\SqlFragment;
use App\Infrastructure\Scanner\Sql\SqlPlaceholder;
use App\Infrastructure\Scanner\Sql\SqlStatementParser;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;

/**
 * Follows personal data through one PHP file, in reading order.
 *
 * Deliberately simple (decision 3): variables are tracked within the file, by their last assignment,
 * without following includes, functions or branches.
 */
final class FileFlowVisitor extends NodeVisitorAbstract
{
    private const array REQUEST = ['_POST', '_GET', '_REQUEST', '_COOKIE'];
    private const array SQL_FUNCTIONS = ['mysqli_query' => 1, 'mysqli_real_query' => 1, 'mysqli_multi_query' => 1, 'mysqli_prepare' => 1, 'mysql_query' => 0, 'pg_query' => 1, 'sqlite_query' => 1];
    private const array SQL_METHODS = ['query', 'exec', 'prepare', 'real_query', 'executequery', 'executestatement'];
    private const array FETCH_FUNCTIONS = ['mysqli_fetch_assoc', 'mysqli_fetch_array', 'mysqli_fetch_object', 'mysql_fetch_assoc', 'mysql_fetch_array', 'mysql_fetch_object', 'pg_fetch_assoc', 'pg_fetch_array'];
    private const array FETCH_METHODS = ['fetch', 'fetch_assoc', 'fetch_array', 'fetch_object', 'fetchobject', 'fetchall', 'fetchassociative', 'fetchallassociative'];
    private const array HASH_FUNCTIONS = ['md5' => 0, 'sha1' => 0, 'crypt' => 0, 'hash' => 1, 'password_hash' => 0];
    private const array LOG_METHODS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'];
    private const array HTTP_METHODS = ['request', 'get', 'post', 'put', 'patch', 'delete', 'send'];
    /** Functions whose result carries no text from their arguments. */
    private const array OPAQUE_FUNCTIONS = ['intval', 'floatval', 'boolval', 'count', 'strlen', 'mb_strlen', 'is_numeric', 'isset', 'empty', 'mysqli_insert_id', 'mysqli_affected_rows', 'mysqli_error', 'date', 'time'];

    /** @var array<string, list<FieldReference>> fields carried by each variable */
    private array $carried = [];
    /** @var array<string, array<string, FieldReference>> for variables holding a fetched row, the field behind each key */
    private array $rows = [];
    /** @var array<string, SqlFragment> */
    private array $fragments = [];
    /** @var array<string, true> variables that cannot carry text (cast to int) */
    private array $safe = [];
    /** @var array<string, array{host: ?string, line: int, fields: list<FieldReference>}> */
    private array $curlHandles = [];
    /** @var array<string, string> request key => table it most likely belongs to */
    private array $requestTables = [];
    /** @var list<FieldReference> */
    private array $collected = [];
    private ?int $firstCollectLine = null;
    /** @var list<FlowPoint> */
    private array $flows = [];

    public function __construct(
        private readonly Schema $schema,
        private readonly string $file,
        private readonly SqlStatementParser $sqlParser,
    ) {
    }

    /**
     * @return list<FlowPoint>
     */
    public function flows(): array
    {
        return $this->flows;
    }

    /**
     * Request keys are matched to the table sharing the most of them: in a form posting
     * prenom, nom and nam, "nom" belongs to patients rather than ville_reference.
     */
    public function beforeTraverse(array $nodes): null
    {
        $keys = [];
        foreach (new NodeFinder()->findInstanceOf($nodes, Expr\ArrayDimFetch::class) as $fetch) {
            $key = $this->requestKey($fetch);
            if (null !== $key) {
                $keys[$key] = true;
            }
        }

        $scores = [];
        foreach ($this->schema->tables() as $table) {
            $scores[$table->name] = \count(array_filter($table->columns, static fn ($column): bool => isset($keys[$column->name()])));
        }
        arsort($scores);

        foreach (array_keys($keys) as $key) {
            foreach (array_keys($scores) as $table) {
                foreach ($this->schema->table($table)->columns ?? [] as $column) {
                    if ($column->name() === $key) {
                        $this->requestTables[$key] ??= $table;
                    }
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): null
    {
        match (true) {
            $node instanceof Expr\Assign && $node->var instanceof Expr\Variable && \is_string($node->var->name) => $this->assign($node->var->name, $node->expr),
            $node instanceof Expr\AssignOp\Concat && $node->var instanceof Expr\Variable && \is_string($node->var->name) => $this->appendTo($node->var->name, $node->expr),
            $node instanceof Expr\ArrayDimFetch => $this->collect($node),
            $node instanceof Expr\FuncCall => $this->functionCall($node),
            $node instanceof Expr\MethodCall => $this->methodCall($node),
            default => null,
        };

        return null;
    }

    public function afterTraverse(array $nodes): null
    {
        foreach ($this->curlHandles as $handle) {
            $this->flows[] = new FlowPoint(FlowOperation::Send, $handle['fields'], new SourceLocation($this->file, $handle['line']), $handle['host']);
        }
        if (null !== $this->firstCollectLine) {
            $this->flows[] = new FlowPoint(FlowOperation::Collect, $this->collected, new SourceLocation($this->file, $this->firstCollectLine));
        }

        return null;
    }

    private function assign(string $variable, Expr $value): void
    {
        $this->carried[$variable] = $this->fieldsOf($value);
        unset($this->safe[$variable], $this->fragments[$variable], $this->rows[$variable]);

        if ($this->isSafe($value)) {
            $this->safe[$variable] = true;
        }
        $fragment = $this->fragmentOf($value);
        if (null !== $fragment) {
            $this->fragments[$variable] = $fragment;
        }
        $row = $this->rowOf($value);
        if ([] !== $row) {
            $this->rows[$variable] = $row;
        }
        if ($value instanceof Expr\FuncCall && 'curl_init' === $this->functionName($value)) {
            $url = $this->arguments($value)[0] ?? null;
            $this->curlHandles[$variable] = [
                'host' => null === $url ? null : $this->host($url),
                'line' => $value->getStartLine(),
                'fields' => null === $url ? [] : $this->fieldsOf($url),
            ];
        }
    }

    private function appendTo(string $variable, Expr $value): void
    {
        $this->carried[$variable] = [...$this->carried[$variable] ?? [], ...$this->fieldsOf($value)];
        unset($this->safe[$variable]);
        if (isset($this->fragments[$variable])) {
            $this->fragments[$variable] = $this->fragments[$variable]->append($this->fragmentOrPlaceholder($value));
        }
    }

    private function collect(Expr\ArrayDimFetch $fetch): void
    {
        $key = $this->requestKey($fetch);
        $field = null === $key ? null : $this->requestField($key);
        if (null === $field) {
            return;
        }
        $this->collected[] = $field;
        $this->firstCollectLine ??= $fetch->getStartLine();
    }

    private function functionCall(Expr\FuncCall $call): void
    {
        $name = $this->functionName($call);
        $arguments = $this->arguments($call);
        if (null === $name) {
            return;
        }

        if (isset(self::SQL_FUNCTIONS[$name])) {
            $query = $arguments[self::SQL_FUNCTIONS[$name]] ?? $arguments[0] ?? null;
            if (null !== $query) {
                $this->sql($query);
            }

            return;
        }

        if (isset(self::HASH_FUNCTIONS[$name])) {
            $input = $arguments[self::HASH_FUNCTIONS[$name]] ?? null;
            $fields = null === $input ? [] : $this->fieldsOf($input);
            if ([] !== $fields) {
                $algorithm = 'hash' === $name && isset($arguments[0]) ? $this->fragmentOf($arguments[0])?->text() ?? 'hash' : $name;
                $this->flows[] = new FlowPoint(FlowOperation::Hash, $fields, $this->location($call), $algorithm);
            }

            return;
        }

        match ($name) {
            'error_log' => $this->log($arguments[0] ?? null, 'error_log', $call),
            'syslog' => $this->log($arguments[1] ?? null, 'syslog', $call),
            'file_put_contents' => $this->fileWrite($arguments, $call),
            'mail' => $this->mail($arguments, $call),
            'curl_setopt' => $this->curlOption($arguments[0] ?? null, $arguments[1] ?? null, $arguments[2] ?? null),
            'curl_setopt_array' => $this->curlOptions($arguments[0] ?? null, $arguments[1] ?? null),
            'file_get_contents' => $this->httpCall($arguments, $call),
            default => null,
        };
    }

    private function methodCall(Expr\MethodCall $call): void
    {
        if (!$call->name instanceof Node\Identifier) {
            return;
        }
        $name = $call->name->toLowerString();
        $arguments = $this->arguments($call);

        if (\in_array($name, self::LOG_METHODS, true) && $this->isLogger($call->var)) {
            $this->log(new Expr\Array_(array_map(static fn (Expr $argument): ArrayItem => new ArrayItem($argument), $arguments)), 'logger', $call);
        } elseif (\in_array($name, self::SQL_METHODS, true) && isset($arguments[0])) {
            $this->sql($arguments[0]);
        } elseif (\in_array($name, self::HTTP_METHODS, true)) {
            $this->httpCall($arguments, $call);
        }
    }

    private function sql(Expr $query): void
    {
        $fragment = $this->fragmentOf($query);
        $statement = null === $fragment ? null : $this->sqlParser->parse($fragment, $this->schema);
        if (null === $fragment || null === $statement) {
            return;
        }

        $this->flows[] = new FlowPoint(
            $statement->operation,
            $statement->fields,
            new SourceLocation($this->file, $fragment->line),
            $statement->table,
            $statement->concatenatedFields,
        );
    }

    private function log(?Expr $message, string $target, Node $call): void
    {
        if (null !== $message) {
            $this->flows[] = new FlowPoint(FlowOperation::Log, $this->fieldsOf($message), $this->location($call), $target);
        }
    }

    /**
     * @param list<Expr> $arguments
     */
    private function fileWrite(array $arguments, Expr\FuncCall $call): void
    {
        $path = isset($arguments[0]) ? $this->fragmentOf($arguments[0])?->text() : null;
        if (null !== $path && str_contains(strtolower($path), 'log')) {
            $this->log($arguments[1] ?? null, ltrim((string) preg_replace('#^.*\?#', '', $path), './'), $call);
        }
    }

    /**
     * @param list<Expr> $arguments
     */
    private function mail(array $arguments, Expr\FuncCall $call): void
    {
        if (!isset($arguments[0])) {
            return;
        }
        $recipients = $this->fieldsOf($arguments[0]);
        $target = 'mail:'.([] !== $recipients ? implode(',', array_map('strval', $recipients)) : ($this->fragmentOf($arguments[0])?->text() ?? '?'));
        $fields = array_merge(...array_map($this->fieldsOf(...), \array_slice($arguments, 0, 3)));

        $this->flows[] = new FlowPoint(FlowOperation::Send, $fields, $this->location($call), $target);
    }

    private function curlOption(?Expr $handle, ?Expr $option, ?Expr $value): void
    {
        if (!$handle instanceof Expr\Variable || !\is_string($handle->name) || !isset($this->curlHandles[$handle->name])
            || !$option instanceof Expr\ConstFetch || null === $value) {
            return;
        }

        match ($option->name->toString()) {
            'CURLOPT_URL' => $this->curlHandles[$handle->name]['host'] = $this->host($value),
            'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER', 'CURLOPT_COOKIE' => $this->curlHandles[$handle->name]['fields'] = [...$this->curlHandles[$handle->name]['fields'], ...$this->fieldsOf($value)],
            default => null,
        };
    }

    private function curlOptions(?Expr $handle, ?Expr $options): void
    {
        if (!$options instanceof Expr\Array_) {
            return;
        }
        foreach ($options->items as $item) {
            $this->curlOption($handle, $item->key, $item->value);
        }
    }

    /**
     * An HTTP call whose URL is written in the code (Guzzle, HttpClient, file_get_contents).
     *
     * @param list<Expr> $arguments
     */
    private function httpCall(array $arguments, Expr $call): void
    {
        foreach ($arguments as $index => $argument) {
            $host = $this->host($argument);
            if (null === $host) {
                continue;
            }
            $fields = array_merge(...array_map($this->fieldsOf(...), $arguments));
            $this->flows[] = new FlowPoint(FlowOperation::Send, $fields, $this->location($call), $host);

            return;
        }
    }

    /**
     * @return list<FieldReference>
     */
    private function fieldsOf(Expr $expression): array
    {
        if ($expression instanceof Expr\ArrayDimFetch) {
            $key = $expression->dim instanceof Scalar\String_ ? $expression->dim->value : null;
            $requestKey = $this->requestKey($expression);
            if (null !== $requestKey) {
                $field = $this->requestField($requestKey);

                return null === $field ? [] : [$field];
            }
            if ($expression->var instanceof Expr\Variable && \is_string($expression->var->name) && isset($this->rows[$expression->var->name]) && null !== $key) {
                $field = $this->rows[$expression->var->name][$key] ?? null;

                return null === $field ? [] : [$field];
            }

            return $this->fieldsOf($expression->var);
        }
        if ($expression instanceof Expr\Variable) {
            return \is_string($expression->name)
                ? [...$this->carried[$expression->name] ?? [], ...array_values($this->rows[$expression->name] ?? [])]
                : [];
        }
        if ($this->isSafe($expression) || $expression instanceof Scalar) {
            return [];
        }
        if ($expression instanceof Expr\FuncCall) {
            $name = $this->functionName($expression);
            if (null !== $name && (isset(self::HASH_FUNCTIONS[$name]) || \in_array($name, self::OPAQUE_FUNCTIONS, true))) {
                return [];
            }
        }

        $fields = [];
        foreach ($this->children($expression) as $child) {
            array_push($fields, ...$this->fieldsOf($child));
        }

        return $fields;
    }

    private function isSafe(Expr $expression): bool
    {
        return match (true) {
            $expression instanceof Expr\Cast\Int_, $expression instanceof Expr\Cast\Double, $expression instanceof Expr\Cast\Bool_,
            $expression instanceof Scalar\Int_, $expression instanceof Scalar\Float_,
            $expression instanceof Expr\ConstFetch, $expression instanceof Expr\ClassConstFetch => true,
            $expression instanceof Expr\Variable => \is_string($expression->name) && isset($this->safe[$expression->name]),
            // A hash is hexadecimal (or a crypt string): it cannot carry quotes nor the original value.
            $expression instanceof Expr\FuncCall => \in_array($this->functionName($expression), ['intval', 'floatval', 'count', 'strlen'], true)
                || isset(self::HASH_FUNCTIONS[$this->functionName($expression) ?? '']),
            default => false,
        };
    }

    private function fragmentOf(Expr $expression): ?SqlFragment
    {
        return match (true) {
            $expression instanceof Scalar\String_ => new SqlFragment([$expression->value], $expression->getStartLine()),
            $expression instanceof Scalar\InterpolatedString => new SqlFragment(array_values(array_map(
                fn (Node $part): string|SqlPlaceholder => $part instanceof InterpolatedStringPart ? $part->value : $this->placeholder($part),
                $expression->parts,
            )), $expression->getStartLine()),
            $expression instanceof Expr\BinaryOp\Concat => $this->fragmentOrPlaceholder($expression->left)->append($this->fragmentOrPlaceholder($expression->right)),
            $expression instanceof Expr\Variable => \is_string($expression->name) ? $this->fragments[$expression->name] ?? null : null,
            default => null,
        };
    }

    private function fragmentOrPlaceholder(Expr $expression): SqlFragment
    {
        return $this->fragmentOf($expression) ?? new SqlFragment([$this->placeholder($expression)], $expression->getStartLine());
    }

    private function placeholder(Node $expression): SqlPlaceholder
    {
        return $expression instanceof Expr
            ? new SqlPlaceholder($this->isSafe($expression), $this->fieldsOf($expression))
            : new SqlPlaceholder(false);
    }

    /**
     * @return array<string, FieldReference>
     */
    private function rowOf(Expr $expression): array
    {
        if ($expression instanceof Expr\Variable) {
            return \is_string($expression->name) ? $this->rows[$expression->name] ?? [] : [];
        }
        if ($expression instanceof Expr\FuncCall) {
            $name = $this->functionName($expression);
            $arguments = $this->arguments($expression);
            if (null !== $name && isset(self::SQL_FUNCTIONS[$name])) {
                $query = $arguments[self::SQL_FUNCTIONS[$name]] ?? $arguments[0] ?? null;

                return null === $query ? [] : $this->selectedRow($query);
            }
            if (\in_array($name, self::FETCH_FUNCTIONS, true) && isset($arguments[0])) {
                return $this->rowOf($arguments[0]);
            }
        }
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Node\Identifier) {
            $name = $expression->name->toLowerString();
            $arguments = $this->arguments($expression);
            if (\in_array($name, self::SQL_METHODS, true) && isset($arguments[0])) {
                return $this->selectedRow($arguments[0]);
            }
            if (\in_array($name, self::FETCH_METHODS, true)) {
                return $this->rowOf($expression->var);
            }
        }

        return [];
    }

    /**
     * @return array<string, FieldReference>
     */
    private function selectedRow(Expr $query): array
    {
        $fragment = $this->fragmentOf($query);

        return null === $fragment ? [] : $this->sqlParser->parse($fragment, $this->schema)->row ?? [];
    }

    private function requestKey(Expr\ArrayDimFetch $fetch): ?string
    {
        return $fetch->var instanceof Expr\Variable && \in_array($fetch->var->name, self::REQUEST, true) && $fetch->dim instanceof Scalar\String_
            ? $fetch->dim->value
            : null;
    }

    private function requestField(string $key): ?FieldReference
    {
        return isset($this->requestTables[$key]) ? new FieldReference($this->requestTables[$key], $key) : null;
    }

    private function host(Expr $url): ?string
    {
        $text = $this->fragmentOf($url)?->text();
        if (null === $text || 1 !== preg_match('#^https?://#i', $text)) {
            return null;
        }
        $host = parse_url($text, \PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : null;
    }

    private function isLogger(Expr $receiver): bool
    {
        $name = match (true) {
            $receiver instanceof Expr\Variable => $receiver->name,
            $receiver instanceof Expr\PropertyFetch => $receiver->name instanceof Node\Identifier ? $receiver->name->toString() : null,
            default => null,
        };

        return \is_string($name) && str_contains(strtolower($name), 'log');
    }

    private function functionName(Expr\FuncCall $call): ?string
    {
        return $call->name instanceof Name ? $call->name->toLowerString() : null;
    }

    /**
     * @return list<Expr>
     */
    private function arguments(Expr\FuncCall|Expr\MethodCall $call): array
    {
        return array_values(array_map(
            static fn (Arg $argument): Expr => $argument->value,
            array_filter($call->args, static fn (Node $argument): bool => $argument instanceof Arg),
        ));
    }

    /**
     * The sub-expressions of an expression, looking through arguments, array items and string parts.
     *
     * @return list<Expr>
     */
    private function children(Node $node): array
    {
        $children = [];
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            foreach (\is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Expr) {
                    $children[] = $child;
                } elseif ($child instanceof Arg || $child instanceof ArrayItem) {
                    $children[] = $child->value;
                }
            }
        }

        return $children;
    }

    private function location(Node $node): SourceLocation
    {
        return new SourceLocation($this->file, $node->getStartLine());
    }
}
