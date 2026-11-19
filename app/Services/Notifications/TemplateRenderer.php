<?php

namespace App\Services\Notifications;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Stringable;

class TemplateRenderer
{
    public function render(?string $templateBody, array $data = []): string
    {
        if ($templateBody === null || trim($templateBody) === '') {
            return '';
        }

        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/',
            function (array $matches) use ($data): string {
                $variableName = trim($matches[1]);

                if (\array_key_exists($variableName, $data)) {
                    $value = $data[$variableName];
                } elseif (Arr::has($data, $variableName)) {
                    $value = data_get($data, $variableName);
                } else {
                    if (app()->environment(['local', 'testing'])) {
                        throw new InvalidArgumentException(
                            "Missing notification template variable: {$variableName}"
                        );
                    }

                    Log::warning('Missing notification template variable', [
                        'variable' => $variableName,
                    ]);
                    $value = '';
                }

                return e($this->valueToString($value));
            },
            $templateBody
        ) ?? $templateBody;
    }

    private function valueToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (\is_string($value) || \is_numeric($value)) {
            return (string) $value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '';
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (\is_array($value) || \is_object($value)) {
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            return \is_string($encoded) ? $encoded : '';
        }

        return (string) $value;
    }
}
