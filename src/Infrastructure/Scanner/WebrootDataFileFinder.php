<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner;

use App\Application\Port\DataFileFinder;
use App\Domain\DataFile;
use App\Domain\SourceLocation;
use Symfony\Component\Finder\Finder;

final class WebrootDataFileFinder implements DataFileFinder
{
    /** Directory names that web servers usually serve as-is. */
    private const array WEBROOTS = ['public', 'public_html', 'www', 'htdocs', 'html', 'web'];

    private const array DATA_FILES = ['*.csv', '*.tsv', '*.xls', '*.xlsx', '*.sql', '*.sql.gz', '*.dump', '*.bak', '*.mdb', '*.sqlite', '*.db'];

    public function findExposed(string $projectPath): array
    {
        $webroots = array_values(array_filter(
            array_map(static fn (string $name): string => $projectPath.'/'.$name, self::WEBROOTS),
            is_dir(...),
        ));
        if ([] === $webroots) {
            return [];
        }

        $files = (new Finder())->files()->in($webroots)->name(self::DATA_FILES)->exclude(['vendor', 'node_modules'])->sortByName();

        $dataFiles = [];
        foreach ($files as $file) {
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($projectPath))), '/');
            $dataFiles[] = new DataFile(new SourceLocation($relative), self::header($file->getPathname()));
        }

        return $dataFiles;
    }

    /**
     * @return list<string>
     */
    private static function header(string $path): array
    {
        if (1 !== preg_match('/\.(csv|tsv)$/i', $path)) {
            return [];
        }
        $handle = fopen($path, 'r');
        $line = false === $handle ? false : fgets($handle);
        if (false !== $handle) {
            fclose($handle);
        }
        if (false === $line) {
            return [];
        }

        $separator = array_reduce([';', ',', "\t"], static fn (string $best, string $candidate): string => substr_count($line, $candidate) > substr_count($line, $best) ? $candidate : $best, ',');

        return array_values(array_filter(array_map(
            static fn (?string $column): string => strtolower(trim((string) $column)),
            str_getcsv(trim($line), $separator, '"', ''),
        ), static fn (string $column): bool => '' !== $column));
    }
}
