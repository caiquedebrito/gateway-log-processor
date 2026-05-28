<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GatewayLog\Enums;

use App\Domain\GatewayLog\Enums\ReportExportStatus;
use PHPUnit\Framework\TestCase;

class ReportExportStatusTest extends TestCase
{
    public function test_it_has_expected_values(): void
    {
        $this->assertSame('queued', ReportExportStatus::Queued->value);
        $this->assertSame('processing', ReportExportStatus::Processing->value);
        $this->assertSame('finished', ReportExportStatus::Finished->value);
        $this->assertSame('failed', ReportExportStatus::Failed->value);
    }

    public function test_it_knows_final_statuses(): void
    {
        $this->assertFalse(ReportExportStatus::Queued->isFinal());
        $this->assertFalse(ReportExportStatus::Processing->isFinal());

        $this->assertTrue(ReportExportStatus::Finished->isFinal());
        $this->assertTrue(ReportExportStatus::Failed->isFinal());
    }
}
