<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\PlaceOrderData;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

final class PlaceOrderRequest extends FormRequest
{
    private const string DECIMAL = '/^\d{1,16}(\.\d{1,8})?$/';

    private ?Asset $asset = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'between:8,128', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'asset' => ['required', 'string', 'max:16'],
            'side' => ['required', Rule::enum(OrderSide::class)],
            'type' => ['required', Rule::enum(OrderType::class)],
            'quantity' => ['required', 'string', 'regex:'.self::DECIMAL],
            'limit_price' => ['required_if:type,LIMIT', 'prohibited_if:type,MARKET', 'nullable', 'string', 'regex:'.self::DECIMAL],
            'expires_at' => ['prohibited_if:type,MARKET', 'nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'quantity.string' => 'quantity must be a decimal string such as "0.5", not a JSON number.',
            'quantity.regex' => 'quantity must be a positive decimal string with at most 8 decimals.',
            'limit_price.string' => 'limit_price must be a decimal string such as "101.25", not a JSON number.',
            'limit_price.regex' => 'limit_price must be a positive decimal string with at most 8 decimals.',
            'limit_price.required_if' => 'limit_price is required for LIMIT orders.',
            'limit_price.prohibited_if' => 'limit_price must not be sent for MARKET orders.',
            'expires_at.prohibited_if' => 'expires_at applies to LIMIT orders only.',
        ];
    }

    /**
     * Rules that need the asset row; they run only once the basic shape is valid.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->asset = Asset::query()->where('symbol', $this->string('asset')->toString())->first();

            if ($this->asset === null) {
                $validator->errors()->add('asset', 'Unknown asset.');

                return;
            }

            if (! $this->asset->is_tradable) {
                $validator->errors()->add('asset', 'This asset is not tradable.');

                return;
            }

            $quantity = Decimal::of($this->string('quantity')->toString());

            if (! $quantity->isPositive()) {
                $validator->errors()->add('quantity', 'quantity must be greater than zero.');
            } elseif (! $quantity->fitsScale($this->asset->quantity_precision)) {
                $validator->errors()->add('quantity', "quantity allows at most {$this->asset->quantity_precision} decimals.");
            }

            if ($this->filled('limit_price')) {
                $price = Decimal::of($this->string('limit_price')->toString());

                if (! $price->isPositive()) {
                    $validator->errors()->add('limit_price', 'limit_price must be greater than zero.');
                } elseif (! $price->fitsScale($this->asset->price_precision)) {
                    $validator->errors()->add('limit_price', "limit_price allows at most {$this->asset->price_precision} decimals.");
                }
            }
        }];
    }

    public function toData(): PlaceOrderData
    {
        if ($this->asset === null) {
            throw new LogicException('toData() requires a validated request.');
        }

        return new PlaceOrderData(
            userId: $this->user()->id,
            assetId: $this->asset->id,
            side: OrderSide::from($this->string('side')->toString()),
            type: OrderType::from($this->string('type')->toString()),
            quantity: Decimal::of($this->string('quantity')->toString()),
            limitPrice: $this->filled('limit_price') ? Decimal::of($this->string('limit_price')->toString()) : null,
            expiresAt: $this->filled('expires_at') ? CarbonImmutable::parse($this->string('expires_at')->toString()) : null,
            idempotencyKey: $this->string('idempotency_key')->toString(),
            requestIp: $this->ip(),
        );
    }
}
