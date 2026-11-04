<?php

declare(strict_types=1);

namespace App\Infrastructure\Report;

use App\Application\Port\ReportStore;
use App\Application\ScanReport;
use App\Application\StoredReport;

/**
 * Keeps the last scan of each project as a JSON file (var/pii-radar by default).
 */
final readonly class JsonReportStore implements ReportStore
{
    public function __construct(
        private string $directory,
        private ScanReportJson $json,
    ) {
    }

    public function save(string $projectPath, ScanReport $report): StoredReport
    {
        $stored = new StoredReport($report, self::canonical($projectPath), new \DateTimeImmutable());

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the report directory "%s".', $this->directory));
        }
        $content = json_encode([
            'project' => $stored->projectPath,
            'scanned_at' => $stored->scannedAt->format(\DATE_ATOM),
            'report' => $this->json->normalize($report),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        file_put_contents($this->path($stored->projectPath), $content, \LOCK_EX);

        return $stored;
    }

    public function load(string $projectPath): ?StoredReport
    {
        $path = $this->path(self::canonical($projectPath));
        if (!is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($data) || !\is_array($data['report'] ?? null) || !\is_string($data['project'] ?? null) || !\is_string($data['scanned_at'] ?? null)) {
                throw new InvalidReport('Malformed report file.');
            }

            return new StoredReport($this->json->denormalize($data['report']), $data['project'], new \DateTimeImmutable($data['scanned_at']));
        } catch (\JsonException|\Exception $exception) {
            throw new InvalidReport(\sprintf('The last report of "%s" cannot be read (%s): scan the project again.', $projectPath, $exception->getMessage()), previous: $exception);
        }
    }

    private function path(string $canonicalProject): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($canonicalProject))), '-');

        return \sprintf('%s/%s-%s.json', rtrim($this->directory, '/'), '' === $slug ? 'project' : $slug, substr(sha1($canonicalProject), 0, 8));
    }

    private static function canonical(string $projectPath): string
    {
        return rtrim(realpath($projectPath) ?: $projectPath, '/');
    }
}
