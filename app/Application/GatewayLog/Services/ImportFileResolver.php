<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Domain\GatewayLog\DTO\ResolvedImportFileData;
use App\Domain\GatewayLog\Enums\ImportFileType;
use App\Domain\GatewayLog\Exceptions\ImportFileResolutionException;
use ZipArchive;

final readonly class ImportFileResolver
{
    public function __construct(
        private ?string $extractionBaseDirectory = null,
    ) {}

    public function resolve(string $inputPath): ResolvedImportFileData
    {
        $realInputPath = $this->resolveExistingReadableFile($inputPath);

        $extension = strtolower(pathinfo($realInputPath, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt' => $this->resolveTxtFile($inputPath, $realInputPath),
            'zip' => $this->resolveZipFile($inputPath, $realInputPath),
            default => throw new ImportFileResolutionException(
                'Unsupported import file extension. Only .txt and .zip files are allowed.'
            ),
        };
    }

    private function resolveTxtFile(
        string $inputPath,
        string $realInputPath,
    ): ResolvedImportFileData {
        return new ResolvedImportFileData(
            inputPath: $inputPath,
            resolvedPath: $realInputPath,
            fileType: ImportFileType::Txt,
            fileHash: $this->hashFile($realInputPath),
            wasExtracted: false,
            extractedFrom: null,
        );
    }

    private function resolveZipFile(
        string $inputPath,
        string $realInputPath,
    ): ResolvedImportFileData {
        if (! class_exists(ZipArchive::class)) {
            throw new ImportFileResolutionException(
                'PHP Zip extension is required to import .zip files.'
            );
        }

        $zip = new ZipArchive;

        $opened = $zip->open($realInputPath);

        if ($opened !== true) {
            throw new ImportFileResolutionException(
                "Could not open ZIP file [{$realInputPath}]."
            );
        }

        try {
            $txtEntryName = $this->selectTxtEntryFromZip($zip);
            $extractedPath = $this->extractZipEntry(
                zip: $zip,
                zipPath: $realInputPath,
                entryName: $txtEntryName,
            );

            return new ResolvedImportFileData(
                inputPath: $inputPath,
                resolvedPath: $extractedPath,
                fileType: ImportFileType::Zip,
                fileHash: $this->hashFile($extractedPath),
                wasExtracted: true,
                extractedFrom: $realInputPath,
            );
        } finally {
            $zip->close();
        }
    }

    private function resolveExistingReadableFile(string $inputPath): string
    {
        $inputPath = trim($inputPath);

        if ($inputPath === '') {
            throw new ImportFileResolutionException('Import file path cannot be empty.');
        }

        if (is_file($inputPath)) {
            return $this->realPath($inputPath);
        }

        $basePathFile = base_path($inputPath);

        if (is_file($basePathFile)) {
            return $this->realPath($basePathFile);
        }

        throw new ImportFileResolutionException(
            "Import file [{$inputPath}] does not exist."
        );
    }

    private function realPath(string $path): string
    {
        if (! is_readable($path)) {
            throw new ImportFileResolutionException(
                "Import file [{$path}] is not readable."
            );
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            throw new ImportFileResolutionException(
                "Could not resolve real path for import file [{$path}]."
            );
        }

        return $realPath;
    }

    private function hashFile(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new ImportFileResolutionException(
                "Could not calculate hash for import file [{$path}]."
            );
        }

        return $hash;
    }

    private function extractionBaseDirectory(): string
    {
        return $this->extractionBaseDirectory
            ?? storage_path('app/imports/extracted');
    }

    private function selectTxtEntryFromZip(ZipArchive $zip): string
    {
        $txtEntries = [];
        $logsTxtEntries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);

            if ($entryName === false) {
                continue;
            }

            if ($this->isDirectoryEntry($entryName)) {
                continue;
            }

            if (! $this->isSafeZipEntryName($entryName)) {
                throw new ImportFileResolutionException(
                    "ZIP file contains an unsafe path [{$entryName}]."
                );
            }

            if (! $this->isTxtEntry($entryName)) {
                continue;
            }

            $txtEntries[] = $entryName;

            if (strtolower(basename(str_replace('\\', '/', $entryName))) === 'logs.txt') {
                $logsTxtEntries[] = $entryName;
            }
        }

        if (count($logsTxtEntries) === 1) {
            return $logsTxtEntries[0];
        }

        if (count($logsTxtEntries) > 1) {
            throw new ImportFileResolutionException(
                'ZIP file contains multiple logs.txt files. Please provide an unambiguous ZIP file.'
            );
        }

        if (count($txtEntries) === 1) {
            return $txtEntries[0];
        }

        if ($txtEntries === []) {
            throw new ImportFileResolutionException(
                'ZIP file does not contain a .txt log file.'
            );
        }

        throw new ImportFileResolutionException(
            'ZIP file contains multiple .txt files and no unique logs.txt file.'
        );
    }

    private function extractZipEntry(
        ZipArchive $zip,
        string $zipPath,
        string $entryName,
    ): string {
        $zipHash = $this->hashFile($zipPath);

        $targetDirectory = $this->extractionBaseDirectory()
            .DIRECTORY_SEPARATOR
            .$zipHash;

        $this->ensureDirectoryExists($targetDirectory);

        $targetPath = $targetDirectory
            .DIRECTORY_SEPARATOR
            .$this->safeBasename($entryName);

        $sourceStream = $zip->getStream($entryName);

        if ($sourceStream === false) {
            throw new ImportFileResolutionException(
                "Could not read ZIP entry [{$entryName}]."
            );
        }

        $targetStream = fopen($targetPath, 'wb');

        if ($targetStream === false) {
            fclose($sourceStream);

            throw new ImportFileResolutionException(
                "Could not create extracted log file [{$targetPath}]."
            );
        }

        try {
            $copiedBytes = stream_copy_to_stream($sourceStream, $targetStream);

            if ($copiedBytes === false) {
                throw new ImportFileResolutionException(
                    "Could not extract ZIP entry [{$entryName}]."
                );
            }
        } finally {
            fclose($sourceStream);
            fclose($targetStream);
        }

        return $this->realPath($targetPath);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (file_exists($directory) && ! is_dir($directory)) {
            throw new ImportFileResolutionException(
                "Path [{$directory}] exists and is not a directory."
            );
        }

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new ImportFileResolutionException(
                "Could not create directory [{$directory}]."
            );
        }
    }

    private function safeBasename(string $entryName): string
    {
        $basename = basename(str_replace('\\', '/', $entryName));

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);

        if (! is_string($safeName) || $safeName === '' || $safeName === '.' || $safeName === '..') {
            return 'logs.txt';
        }

        return $safeName;
    }

    private function isDirectoryEntry(string $entryName): bool
    {
        return str_ends_with($entryName, '/')
            || str_ends_with($entryName, '\\');
    }

    private function isTxtEntry(string $entryName): bool
    {
        return strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) === 'txt';
    }

    private function isSafeZipEntryName(string $entryName): bool
    {
        if (str_contains($entryName, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $entryName);

        if (str_starts_with($normalized, '/')) {
            return false;
        }

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
