<?php

declare(strict_types=1);

namespace Tests\Unit\Application\GatewayLog\Services;

use App\Application\GatewayLog\Services\ImportFileResolver;
use App\Domain\GatewayLog\Enums\ImportFileType;
use App\Domain\GatewayLog\Exceptions\ImportFileResolutionException;
use Tests\TestCase;
use ZipArchive;

final class ImportFileResolverTest extends TestCase
{
    private string $temporaryDirectory;

    private string $extractionDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = storage_path(
            'app/testing-import-file-resolver-'.bin2hex(random_bytes(8))
        );

        $this->extractionDirectory = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'extracted';

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_resolves_txt_file_by_absolute_path(): void
    {
        $content = '{"started_at":1433209822425}'.PHP_EOL;

        $txtPath = $this->createTextFile('logs.txt', $content);

        $resolved = $this->makeResolver()->resolve($txtPath);

        $this->assertSame($txtPath, $resolved->inputPath);
        $this->assertSame(realpath($txtPath), $resolved->resolvedPath);
        $this->assertSame(ImportFileType::Txt, $resolved->fileType);
        $this->assertSame(hash('sha256', $content), $resolved->fileHash);
        $this->assertFalse($resolved->wasExtracted);
        $this->assertNull($resolved->extractedFrom);
    }

    public function test_it_resolves_txt_file_by_relative_path(): void
    {
        $content = '{"started_at":1433209822425}'.PHP_EOL;

        $txtPath = $this->createTextFile('relative-logs.txt', $content);

        $relativePath = str_replace(
            base_path().DIRECTORY_SEPARATOR,
            '',
            $txtPath,
        );

        $resolved = $this->makeResolver()->resolve($relativePath);

        $this->assertSame($relativePath, $resolved->inputPath);
        $this->assertSame(realpath($txtPath), $resolved->resolvedPath);
        $this->assertSame(ImportFileType::Txt, $resolved->fileType);
        $this->assertSame(hash('sha256', $content), $resolved->fileHash);
        $this->assertFalse($resolved->wasExtracted);
    }

    public function test_it_resolves_zip_containing_logs_txt(): void
    {
        $this->requireZipExtension();

        $content = '{"started_at":1433209822425}'.PHP_EOL;

        $zipPath = $this->createZipFile('logs.zip', [
            'logs.txt' => $content,
        ]);

        $resolved = $this->makeResolver()->resolve($zipPath);

        $this->assertSame($zipPath, $resolved->inputPath);
        $this->assertSame(ImportFileType::Zip, $resolved->fileType);
        $this->assertSame(hash('sha256', $content), $resolved->fileHash);
        $this->assertTrue($resolved->wasExtracted);
        $this->assertSame(realpath($zipPath), $resolved->extractedFrom);
        $this->assertFileExists($resolved->resolvedPath);
        $this->assertSame($content, file_get_contents($resolved->resolvedPath));
    }

    public function test_it_resolves_zip_containing_a_single_txt_with_different_name(): void
    {
        $this->requireZipExtension();

        $content = '{"started_at":1433209822425}'.PHP_EOL;

        $zipPath = $this->createZipFile('gateway-export.zip', [
            'gateway-export.txt' => $content,
        ]);

        $resolved = $this->makeResolver()->resolve($zipPath);

        $this->assertSame(ImportFileType::Zip, $resolved->fileType);
        $this->assertSame(hash('sha256', $content), $resolved->fileHash);
        $this->assertTrue($resolved->wasExtracted);
        $this->assertFileExists($resolved->resolvedPath);
        $this->assertSame($content, file_get_contents($resolved->resolvedPath));
    }

    public function test_it_prefers_logs_txt_when_zip_has_other_txt_files(): void
    {
        $this->requireZipExtension();

        $logsContent = '{"started_at":1433209822425}'.PHP_EOL;

        $zipPath = $this->createZipFile('mixed.zip', [
            'readme.txt' => 'documentation',
            'logs.txt' => $logsContent,
        ]);

        $resolved = $this->makeResolver()->resolve($zipPath);

        $this->assertSame(hash('sha256', $logsContent), $resolved->fileHash);
        $this->assertSame($logsContent, file_get_contents($resolved->resolvedPath));
    }

    public function test_it_fails_when_zip_has_no_txt_file(): void
    {
        $this->requireZipExtension();

        $zipPath = $this->createZipFile('without-txt.zip', [
            'data.json' => '{"ok":true}',
        ]);

        $this->expectException(ImportFileResolutionException::class);
        $this->expectExceptionMessage('does not contain a .txt log file');

        $this->makeResolver()->resolve($zipPath);
    }

    public function test_it_fails_when_zip_has_multiple_txt_files_without_logs_txt(): void
    {
        $this->requireZipExtension();

        $zipPath = $this->createZipFile('multiple-txt.zip', [
            'first.txt' => 'first',
            'second.txt' => 'second',
        ]);

        $this->expectException(ImportFileResolutionException::class);
        $this->expectExceptionMessage('multiple .txt files');

        $this->makeResolver()->resolve($zipPath);
    }

    public function test_it_fails_when_zip_entry_contains_unsafe_path(): void
    {
        $this->requireZipExtension();

        $zipPath = $this->createZipFile('unsafe.zip', [
            '../logs.txt' => '{"started_at":1433209822425}'.PHP_EOL,
        ]);

        $this->expectException(ImportFileResolutionException::class);
        $this->expectExceptionMessage('unsafe path');

        $this->makeResolver()->resolve($zipPath);
    }

    public function test_it_fails_when_file_does_not_exist(): void
    {
        $this->expectException(ImportFileResolutionException::class);
        $this->expectExceptionMessage('does not exist');

        $this->makeResolver()->resolve(
            $this->temporaryDirectory.DIRECTORY_SEPARATOR.'missing.txt'
        );
    }

    public function test_it_fails_when_extension_is_not_supported(): void
    {
        $jsonPath = $this->createTextFile('logs.json', '{"started_at":1433209822425}');

        $this->expectException(ImportFileResolutionException::class);
        $this->expectExceptionMessage('Only .txt and .zip files are allowed');

        $this->makeResolver()->resolve($jsonPath);
    }

    public function test_txt_and_zip_with_same_txt_content_have_same_file_hash(): void
    {
        $this->requireZipExtension();

        $content = '{"started_at":1433209822425}'.PHP_EOL;

        $txtPath = $this->createTextFile('same-content.txt', $content);

        $zipPath = $this->createZipFile('same-content.zip', [
            'logs.txt' => $content,
        ]);

        $txtResolved = $this->makeResolver()->resolve($txtPath);
        $zipResolved = $this->makeResolver()->resolve($zipPath);

        $this->assertSame($txtResolved->fileHash, $zipResolved->fileHash);
    }

    private function makeResolver(): ImportFileResolver
    {
        return new ImportFileResolver(
            extractionBaseDirectory: $this->extractionDirectory,
        );
    }

    private function createTextFile(string $filename, string $content): string
    {
        $path = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .$filename;

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function createZipFile(string $filename, array $entries): string
    {
        $this->requireZipExtension();

        $path = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .$filename;

        $zip = new ZipArchive;

        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $this->assertTrue($opened);

        foreach ($entries as $entryName => $content) {
            $this->assertTrue($zip->addFromString($entryName, $content));
        }

        $this->assertTrue($zip->close());

        return $path;
    }

    private function requireZipExtension(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('PHP Zip extension is not installed.');
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            if (is_file($directory)) {
                unlink($directory);
            }

            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
