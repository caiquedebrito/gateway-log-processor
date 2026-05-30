<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GatewayLog\DTO;

use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportDateField;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

final class ReportFiltersDataTest extends TestCase
{
    public function test_it_creates_empty_filters(): void
    {
        $filters = ReportFiltersData::empty();

        $this->assertNull($filters->dateField);
        $this->assertNull($filters->dateFrom);
        $this->assertNull($filters->dateTo);
        $this->assertFalse($filters->hasDateFilters());

        $this->assertSame([], $filters->toDatabaseArray());
    }

    public function test_it_creates_filters_by_started_at(): void
    {
        $filters = new ReportFiltersData(
            dateField: ReportDateField::StartedAt,
            dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
            dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
        );

        $this->assertSame(ReportDateField::StartedAt, $filters->dateField);
        $this->assertTrue($filters->hasDateFilters());
        $this->assertSame(ReportDateField::StartedAt, $filters->resolvedDateField());

        $this->assertSame([
            'date_field' => 'started_at',
            'date_from' => '2026-05-01T00:00:00.000000Z',
            'date_to' => '2026-05-31T23:59:59.000000Z',
        ], $filters->toDatabaseArray());
    }

    public function test_it_creates_filters_by_processed_at(): void
    {
        $filters = new ReportFiltersData(
            dateField: ReportDateField::ProcessedAt,
            dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
            dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
        );

        $this->assertSame(ReportDateField::ProcessedAt, $filters->dateField);
        $this->assertTrue($filters->hasDateFilters());
        $this->assertSame(ReportDateField::ProcessedAt, $filters->resolvedDateField());

        $this->assertSame('processed_at', $filters->toDatabaseArray()['date_field']);
    }

    public function test_it_defaults_to_started_at_when_date_range_exists_without_date_field(): void
    {
        $filters = ReportFiltersData::fromArray([
            'date_from' => '2026-05-01T00:00:00Z',
            'date_to' => '2026-05-31T23:59:59Z',
        ]);

        $this->assertSame(ReportDateField::StartedAt, $filters->dateField);
        $this->assertSame(ReportDateField::StartedAt, $filters->resolvedDateField());
        $this->assertTrue($filters->hasDateFilters());
    }

    public function test_it_creates_filters_from_array(): void
    {
        $filters = ReportFiltersData::fromArray([
            'date_field' => 'processed_at',
            'date_from' => '2026-05-01T00:00:00Z',
            'date_to' => '2026-05-31T23:59:59Z',
        ]);

        $this->assertSame(ReportDateField::ProcessedAt, $filters->dateField);
        $this->assertSame('2026-05-01 00:00:00', $filters->dateFrom?->toDateTimeString());
        $this->assertSame('2026-05-31 23:59:59', $filters->dateTo?->toDateTimeString());
    }

    public function test_it_supports_only_date_from(): void
    {
        $filters = ReportFiltersData::fromArray([
            'date_field' => 'started_at',
            'date_from' => '2026-05-01T00:00:00Z',
        ]);

        $this->assertTrue($filters->hasDateFilters());
        $this->assertSame(ReportDateField::StartedAt, $filters->dateField);
        $this->assertNotNull($filters->dateFrom);
        $this->assertNull($filters->dateTo);
    }

    public function test_it_supports_only_date_to(): void
    {
        $filters = ReportFiltersData::fromArray([
            'date_field' => 'processed_at',
            'date_to' => '2026-05-31T23:59:59Z',
        ]);

        $this->assertTrue($filters->hasDateFilters());
        $this->assertSame(ReportDateField::ProcessedAt, $filters->dateField);
        $this->assertNull($filters->dateFrom);
        $this->assertNotNull($filters->dateTo);
    }

    public function test_it_fails_when_date_to_is_before_date_from(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('date_to filter must be greater than or equal to date_from');

        new ReportFiltersData(
            dateField: ReportDateField::StartedAt,
            dateFrom: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
            dateTo: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
        );
    }

    public function test_it_fails_when_date_field_is_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('date_field filter must be started_at or processed_at');

        ReportFiltersData::fromArray([
            'date_field' => 'created_at',
            'date_from' => '2026-05-01T00:00:00Z',
        ]);
    }
}
