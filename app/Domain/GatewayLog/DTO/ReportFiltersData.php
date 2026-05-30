<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\DTO;

use App\Domain\GatewayLog\Enums\ReportDateField;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportFiltersData
{
    public function __construct(
        public ?ReportDateField $dateField = null,
        public ?CarbonImmutable $dateFrom = null,
        public ?CarbonImmutable $dateTo = null,
    ) {
        $this->validateDateRange();
    }

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function fromArray(?array $filters): self
    {
        if ($filters === null || $filters === []) {
            return self::empty();
        }

        $dateFrom = self::parseNullableDate($filters['date_from'] ?? null, 'date_from');
        $dateTo = self::parseNullableDate($filters['date_to'] ?? null, 'date_to');

        $dateField = self::parseNullableDateField(
            value: $filters['date_field'] ?? null,
            shouldDefault: $dateFrom !== null || $dateTo !== null,
        );

        return new self(
            dateField: $dateField,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
    }

    public function hasDateFilters(): bool
    {
        return $this->dateFrom !== null || $this->dateTo !== null;
    }

    public function resolvedDateField(): ReportDateField
    {
        return $this->dateField ?? ReportDateField::StartedAt;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'date_field' => $this->dateField?->value,
            'date_from' => $this->dateFrom?->toISOString(),
            'date_to' => $this->dateTo?->toISOString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabaseArray(): array
    {
        return array_filter(
            $this->toArray(),
            static fn (?string $value): bool => $value !== null,
        );
    }

    private function validateDateRange(): void
    {
        if (
            $this->dateFrom instanceof CarbonImmutable
            && $this->dateTo instanceof CarbonImmutable
            && $this->dateTo->lessThan($this->dateFrom)
        ) {
            throw new InvalidArgumentException('The date_to filter must be greater than or equal to date_from.');
        }
    }

    private static function parseNullableDate(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if (is_string($value)) {
            return CarbonImmutable::parse($value)->utc();
        }

        throw new InvalidArgumentException("The {$field} filter must be a valid date string.");
    }

    private static function parseNullableDateField(
        mixed $value,
        bool $shouldDefault,
    ): ?ReportDateField {
        if ($value === null || $value === '') {
            return $shouldDefault ? ReportDateField::StartedAt : null;
        }

        if ($value instanceof ReportDateField) {
            return $value;
        }

        if (is_string($value)) {
            $dateField = ReportDateField::tryFrom($value);

            if ($dateField instanceof ReportDateField) {
                return $dateField;
            }
        }

        throw new InvalidArgumentException('The date_field filter must be started_at or processed_at.');
    }
}
