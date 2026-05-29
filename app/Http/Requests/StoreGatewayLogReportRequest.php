<?php

declare(strict_types=1);

namespace App\Http\Requests;

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
        ];
    }
}
