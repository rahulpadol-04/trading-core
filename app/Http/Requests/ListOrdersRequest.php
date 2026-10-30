<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\OrderFilters;
use App\Enums\OrderStatus;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListOrdersRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'asset' => ['nullable', 'string', 'exists:assets,symbol'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string'],
        ];
    }

    public function filters(): OrderFilters
    {
        return new OrderFilters(
            status: $this->filled('status') ? OrderStatus::from($this->string('status')->toString()) : null,
            assetId: $this->filled('asset')
                ? Asset::query()->where('symbol', $this->string('asset')->toString())->value('id')
                : null,
            perPage: $this->integer('per_page', 25),
        );
    }
}
