<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use RuntimeException;

final class CsvReportWriter
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<int|string|null>>  $rows
     */
    public function write(string $outputPath, array $header, iterable $rows): void
    {
        $directory = dirname($outputPath);

        $this->ensureDirectoryExists($directory);

        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Could not open CSV file [{$outputPath}] for writing.");
        }

        try {
            $this->writeRow($handle, $header);

            foreach ($rows as $row) {
                $this->writeRow($handle, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (file_exists($directory) && ! is_dir($directory)) {
            throw new RuntimeException("Path [{$directory}] exists and is not a directory.");
        }

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create directory [{$directory}].");
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<int|string|null>  $row
     */
    private function writeRow(mixed $handle, array $row): void
    {
        $written = fputcsv($handle, $row);

        if ($written === false) {
            throw new RuntimeException('Could not write row to CSV file.');
        }
    }
}
