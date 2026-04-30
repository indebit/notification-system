<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:100'],
            'channel' => ['required', 'string', Rule::enum(NotificationChannel::class)],
            'content' => ['required', 'string', $this->contentLengthRule('channel')],
            'priority' => ['sometimes', 'string', Rule::enum(NotificationPriority::class)],
            'idempotency_key' => ['sometimes', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('priority')) {
            $this->merge(['priority' => NotificationPriority::Normal->value]);
        }
    }

    protected function contentLengthRule(string $channelAttribute): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($channelAttribute): void {
            $channel = $this->input($channelAttribute);

            $maxLength = match ($channel) {
                NotificationChannel::Sms->value => 160,
                NotificationChannel::Email->value => 10000,
                NotificationChannel::Push->value => 256,
                default => null,
            };

            if ($maxLength !== null && is_string($value) && mb_strlen($value) > $maxLength) {
                $fail("The {$attribute} field may not be greater than {$maxLength} characters for the selected channel.");
            }
        };
    }
}
