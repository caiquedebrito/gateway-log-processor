<?php

declare(strict_types=1);

namespace Tests\Unit\Application\GatewayLog\Services;

use App\Application\GatewayLog\Services\NdjsonLogFileReader;
use InvalidArgumentException;
use Tests\TestCase;

final class NdjsonLogFileReaderTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-reader-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_reads_a_file_from_the_beginning(): void
    {
        $line1 = '{"started_at":1433209822425,"service":{"name":"service-a"}}'.PHP_EOL;
        $line2 = '{"started_at":1433209822426,"service":{"name":"service-b"}}'.PHP_EOL;
        $line3 = '{"started_at":1433209822427,"service":{"name":"service-c"}}'.PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line1.$line2.$line3);

        $reader = new NdjsonLogFileReader;

        $chunk = $reader->read(
            filePath: $filePath,
            fromOffset: 0,
            fromLineNumber: 0,
            limit: 100,
        );

        $this->assertFalse($chunk->isEmpty());
        $this->assertCount(3, $chunk->lines);

        $this->assertSame(1, $chunk->lines[0]->lineNumber);
        $this->assertSame(2, $chunk->lines[1]->lineNumber);
        $this->assertSame(3, $chunk->lines[2]->lineNumber);

        $this->assertSame(0, $chunk->lines[0]->byteOffset);
        $this->assertSame(strlen($line1), $chunk->lines[1]->byteOffset);
        $this->assertSame(strlen($line1.$line2), $chunk->lines[2]->byteOffset);

        $this->assertSame($line1, $chunk->lines[0]->content);
        $this->assertSame($line2, $chunk->lines[1]->content);
        $this->assertSame($line3, $chunk->lines[2]->content);

        $this->assertSame(strlen($line1.$line2.$line3), $chunk->currentOffset);
        $this->assertSame(3, $chunk->lastLineNumber);
        $this->assertSame(3, $chunk->totalLinesRead);
        $this->assertTrue($chunk->reachedEndOfFile);
    }

    public function test_it_respects_the_chunk_limit(): void
    {
        $line1 = '{"started_at":1433209822425}'.PHP_EOL;
        $line2 = '{"started_at":1433209822426}'.PHP_EOL;
        $line3 = '{"started_at":1433209822427}'.PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line1.$line2.$line3);

        $reader = new NdjsonLogFileReader;

        $chunk = $reader->read(
            filePath: $filePath,
            fromOffset: 0,
            fromLineNumber: 0,
            limit: 2,
        );

        $this->assertCount(2, $chunk->lines);

        $this->assertSame(1, $chunk->lines[0]->lineNumber);
        $this->assertSame(2, $chunk->lines[1]->lineNumber);

        $this->assertSame(strlen($line1.$line2), $chunk->currentOffset);
        $this->assertSame(2, $chunk->lastLineNumber);
        $this->assertSame(2, $chunk->totalLinesRead);
        $this->assertFalse($chunk->reachedEndOfFile);
    }

    public function test_it_continues_reading_from_a_previous_offset(): void
    {
        $line1 = '{"started_at":1433209822425,"message":"first"}'.PHP_EOL;
        $line2 = '{"started_at":1433209822426,"message":"second"}'.PHP_EOL;
        $line3 = '{"started_at":1433209822427,"message":"third"}'.PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line1.$line2.$line3);

        $reader = new NdjsonLogFileReader;

        $firstChunk = $reader->read(
            filePath: $filePath,
            fromOffset: 0,
            fromLineNumber: 0,
            limit: 2,
        );

        $secondChunk = $reader->read(
            filePath: $filePath,
            fromOffset: $firstChunk->currentOffset,
            fromLineNumber: $firstChunk->lastLineNumber,
            limit: 2,
        );

        $this->assertCount(1, $secondChunk->lines);

        $this->assertSame(3, $secondChunk->lines[0]->lineNumber);
        $this->assertSame(strlen($line1.$line2), $secondChunk->lines[0]->byteOffset);
        $this->assertSame($line3, $secondChunk->lines[0]->content);

        $this->assertSame(strlen($line1.$line2.$line3), $secondChunk->currentOffset);
        $this->assertSame(3, $secondChunk->lastLineNumber);
        $this->assertSame(1, $secondChunk->totalLinesRead);
        $this->assertTrue($secondChunk->reachedEndOfFile);
    }

    public function test_it_returns_empty_chunk_when_file_is_empty(): void
    {
        $filePath = $this->createTemporaryLogFile('');

        $reader = new NdjsonLogFileReader;

        $chunk = $reader->read(
            filePath: $filePath,
            fromOffset: 0,
            fromLineNumber: 0,
            limit: 100,
        );

        $this->assertTrue($chunk->isEmpty());
        $this->assertSame([], $chunk->lines);
        $this->assertSame(0, $chunk->currentOffset);
        $this->assertSame(0, $chunk->lastLineNumber);
        $this->assertSame(0, $chunk->totalLinesRead);
        $this->assertTrue($chunk->reachedEndOfFile);
    }

    public function test_it_returns_empty_chunk_when_offset_is_at_the_end_of_file(): void
    {
        $line1 = '{"started_at":1433209822425}'.PHP_EOL;
        $line2 = '{"started_at":1433209822426}'.PHP_EOL;

        $content = $line1.$line2;

        $filePath = $this->createTemporaryLogFile($content);

        $reader = new NdjsonLogFileReader;

        $chunk = $reader->read(
            filePath: $filePath,
            fromOffset: strlen($content),
            fromLineNumber: 2,
            limit: 100,
        );

        $this->assertTrue($chunk->isEmpty());
        $this->assertSame(strlen($content), $chunk->currentOffset);
        $this->assertSame(2, $chunk->lastLineNumber);
        $this->assertSame(0, $chunk->totalLinesRead);
        $this->assertTrue($chunk->reachedEndOfFile);
    }

    public function test_it_throws_exception_when_file_does_not_exist(): void
    {
        $reader = new NdjsonLogFileReader;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $reader->read(
            filePath: $this->temporaryDirectory.DIRECTORY_SEPARATOR.'missing-logs.txt',
            fromOffset: 0,
            fromLineNumber: 0,
            limit: 100,
        );
    }

    public function test_it_throws_exception_when_offset_is_negative(): void
    {
        $filePath = $this->createTemporaryLogFile('{"started_at":1433209822425}'.PHP_EOL);

        $reader = new NdjsonLogFileReader;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('offset cannot be negative');

        $reader->read(
            filePath: $filePath,
            fromOffset: -1,
            fromLineNumber: 0,
            limit: 100,
        );
    }

    public function test_it_throws_exception_when_line_number_is_negative(): void
    {
        $filePath = $this->createTemporaryLogFile('{"started_at":1433209822425}'.PHP_EOL);

        $reader = new NdjsonLogFileReader;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line number cannot be negative');

        $reader->read(
            filePath: $filePath,
            fromOffset: 0,
            fromLineNumber: -1,
            limit: 100,
        );
    }

    public function test_it_throws_exception_when_limit_is_zero(): void
    {
        $filePath = $this->createTemporaryLogFile('{"started_at":1433209822425}'.PHP_EOL);

        $reader = new NdjsonLogFileReader;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be greater than zero');

        $reader->read(
            filePath: $filePath,
            fromOffset: 0,
            fromLineNumber: 0,
            limit: 0,
        );
    }

    public function test_it_throws_exception_when_offset_is_greater_than_file_size(): void
    {
        $filePath = $this->createTemporaryLogFile('{"started_at":1433209822425}'.PHP_EOL);

        $reader = new NdjsonLogFileReader;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than the file size');

        $reader->read(
            filePath: $filePath,
            fromOffset: 999999,
            fromLineNumber: 0,
            limit: 100,
        );
    }

    private function createTemporaryLogFile(string $content): string
    {
        $filePath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'logs.txt';

        file_put_contents($filePath, $content);

        return $filePath;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
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
