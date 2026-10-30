<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner\Php;

use App\Application\Port\CodeScanner;
use App\Domain\Schema\Schema;
use App\Infrastructure\Scanner\Sql\SqlStatementParser;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Parses the audited PHP files (nikic/php-parser) and lists their personal data flows.
 */
final readonly class PhpCodeScanner implements CodeScanner
{
    public function __construct(
        private SqlStatementParser $sqlParser,
    ) {
    }

    public function scan(string $projectPath, Schema $schema): array
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $files = (new Finder())->files()->in($projectPath)->name('*.php')->exclude(['vendor', 'node_modules'])->sortByName();

        $flows = [];
        foreach ($files as $file) {
            try {
                $statements = $parser->parse($file->getContents()) ?? [];
            } catch (Error) {
                // A file that does not parse is skipped rather than failing the whole scan.
                continue;
            }

            $visitor = new FileFlowVisitor($schema, str_replace('\\', '/', $file->getRelativePathname()), $this->sqlParser);
            $traverser = new NodeTraverser($visitor);
            $traverser->traverse($statements);
            array_push($flows, ...$visitor->flows());
        }

        return $flows;
    }
}
