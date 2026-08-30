<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SettingsService
{
    private const CACHE_KEY = 'khaloo.settings';

    public function all(): array
    {
        if (! Schema::hasTable('settings')) return [];
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->get()->mapWithKeys(
            fn (Setting $setting) => [$setting->key => $this->cast($setting->value, $setting->type)]
        )->all());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value, string $group = 'general', ?string $type = null): void
    {
        $type ??= is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');
        Setting::query()->updateOrCreate(['key' => $key], [
            'value' => is_bool($value) ? ($value ? '1' : '0') : $value,
            'type' => $type,
            'group' => $group,
        ]);
        Cache::forget(self::CACHE_KEY);
    }

    public function setMany(array $settings, string $group): void
    {
        foreach ($settings as $key => $value) $this->set($key, $value, $group);
    }

    private function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'integer' => (int) $value,
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }
}
