<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GatewayLog\Enums;

use App\Domain\GatewayLog\Enums\LogImportStatus;
use PHPUnit\Framework\TestCase;

class LogImportStatusTest extends TestCase
{
    public function test_it_has_expected_values(): void
    {
        $this->assertSame('queued', LogImportStatus::Queued->value);
        $this->assertSame('processing', LogImportStatus::Processing->value);
        $this->assertSame('finished', LogImportStatus::Finished->value);
        $this->assertSame('failed', LogImportStatus::Failed->value);
        $this->assertSame('canceled', LogImportStatus::Canceled->value);
    }

    public function test_it_knows_final_statuses(): void
    {
        $this->assertFalse(LogImportStatus::Queued->isFinal());
        $this->assertFalse(LogImportStatus::Processing->isFinal());

        $this->assertTrue(LogImportStatus::Finished->isFinal());
        $this->assertTrue(LogImportStatus::Failed->isFinal());
        $this->assertTrue(LogImportStatus::Canceled->isFinal());
    }

    public function test_it_knows_when_import_can_be_retried(): void
    {
        $this->assertTrue(LogImportStatus::Failed->canBeRetried());

        $this->assertFalse(LogImportStatus::Queued->canBeRetried());
        $this->assertFalse(LogImportStatus::Processing->canBeRetried());
        $this->assertFalse(LogImportStatus::Finished->canBeRetried());
        $this->assertFalse(LogImportStatus::Canceled->canBeRetried());
    }
}
