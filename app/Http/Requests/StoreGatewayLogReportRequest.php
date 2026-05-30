<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\GatewayLog\Enums\ReportDateField;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGatewayLogReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'string',
                Rule::in(array_map(
                    static fn (ReportType $type): string => $type->value,
                    ReportType::cases(),
                )),
            ],
            'date_field' => [
                'nullable',
                'string',
                Rule::in(array_map(
                    static fn (ReportDateField $field): string => $field->value,
                    ReportDateField::cases(),
                )),
            ],

            'date_from' => [
                'nullable',
                'date',
            ],

            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],

        ];
    }
}
