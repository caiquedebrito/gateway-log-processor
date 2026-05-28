<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GatewayLog\DTO;

use App\Domain\GatewayLog\DTO\ImportProgressData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ImportProgressDataTest extends TestCase
{
    public function test_it_can_be_created(): void
    {
        $progress = new ImportProgressData(
            currentOffset: 1500,
            lastLineNumber: 20,
            totalLinesProcessed: 18,
            totalLinesFailed: 2,
            reachedEndOfFile: false,
        );

        $this->assertSame(1500, $progress->currentOffset);
        $this->assertSame(20, $progress->lastLineNumber);
        $this->assertSame(18, $progress->totalLinesProcessed);
        $this->assertSame(2, $progress->totalLinesFailed);
        $this->assertFalse($progress->reachedEndOfFile);
    }

    public function test_it_rejects_negative_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImportProgressData(
            currentOffset: -1,
            lastLineNumber: 20,
            totalLinesProcessed: 18,
            totalLinesFailed: 2,
            reachedEndOfFile: false,
        );
    }

    public function test_it_can_represent_finished_file(): void
    {
        $progress = new ImportProgressData(
            currentOffset: 9000,
            lastLineNumber: 100,
            totalLinesProcessed: 100,
            totalLinesFailed: 0,
            reachedEndOfFile: true,
        );

        $this->assertTrue($progress->reachedEndOfFile);
    }
}
