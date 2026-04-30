<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notifications' => ['required', 'array', 'min:1', 'max:1000'],
            'notifications.*.recipient' => ['required', 'string', 'max:100'],
            'notifications.*.channel' => ['required', 'string', Rule::enum(NotificationChannel::class)],
            'notifications.*.content' => ['required', 'string', $this->contentLengthRule()],
            'notifications.*.priority' => ['sometimes', 'string', Rule::enum(NotificationPriority::class)],
            'notifications.*.idempotency_key' => ['sometimes', 'string', 'max:100'],
            'notifications.*.scheduled_at' => ['sometimes', 'date', 'after_or_equal:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $notifications = collect($this->input('notifications', []))
            ->map(function (array $notification): array {
                if (! array_key_exists('priority', $notification)) {
                    $notification['priority'] = NotificationPriority::Normal->value;
                }

                return $notification;
            })
            ->values()
            ->all();

        $this->merge(['notifications' => $notifications]);
    }

    protected function contentLengthRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $segments = explode('.', $attribute);
            $index = $segments[1] ?? null;

            if ($index === null) {
                return;
            }

            $channel = $this->input("notifications.{$index}.channel");

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
