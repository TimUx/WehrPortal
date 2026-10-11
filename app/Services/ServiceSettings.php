<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Third-party services the suite can use: Google Maps and AI models through OpenRouter.
 * API keys are stored encrypted (Laravel APP_KEY) and never shown again after saving.
 */
class ServiceSettings
{
    private const MODELS_URL = 'https://openrouter.ai/api/v1/models';

    /** Shown when the model list cannot be loaded; the saved model is always added */
    private const FALLBACK_MODELS = [
        'anthropic/claude-sonnet-4.5' => 'Anthropic Claude Sonnet 4.5',
        'anthropic/claude-haiku-4.5' => 'Anthropic Claude Haiku 4.5',
        'openai/gpt-4o' => 'OpenAI GPT-4o',
        'openai/gpt-4o-mini' => 'OpenAI GPT-4o mini',
        'google/gemini-2.5-flash' => 'Google Gemini 2.5 Flash',
        'meta-llama/llama-3.3-70b-instruct' => 'Meta Llama 3.3 70B Instruct',
        'mistralai/mistral-large' => 'Mistral Large',
    ];

    public function googleMapsKey(): ?string
    {
        return $this->secret('services_google_maps_key');
    }

    public function openRouterKey(): ?string
    {
        return $this->secret('services_openrouter_key');
    }

    public function aiModel(): ?string
    {
        return Setting::get('services_openrouter_model') ?: null;
    }

    public function saveGoogleMapsKey(?string $key): void
    {
        $this->saveSecret('services_google_maps_key', $key);
    }

    public function saveOpenRouterKey(?string $key): void
    {
        $this->saveSecret('services_openrouter_key', $key);
    }

    public function saveAiModel(?string $model): void
    {
        $model ? Setting::set('services_openrouter_model', $model) : Setting::forget('services_openrouter_model');
    }

    /**
     * Model ID → label for the selection, loaded from OpenRouter (cached for an hour).
     *
     * @return array<string, string>
     */
    public function aiModels(): array
    {
        $models = Cache::remember('openrouter_models', 3600, function () {
            try {
                $response = Http::timeout(8)->acceptJson()->get(self::MODELS_URL);

                return $response->successful()
                    ? collect($response->json('data', []))
                        ->filter(fn ($model) => isset($model['id']))
                        ->mapWithKeys(fn ($model) => [$model['id'] => ($model['name'] ?? $model['id']).' · '.$model['id']])
                        ->sort()
                        ->all()
                    : [];
            } catch (Throwable) {
                return [];
            }
        });

        $models = $models ?: self::FALLBACK_MODELS;

        // A model chosen earlier stays selectable even if OpenRouter no longer lists it
        $saved = $this->aiModel();

        return $saved && ! isset($models[$saved]) ? [$saved => $saved] + $models : $models;
    }

    /** @return array{ok: bool, message: string} */
    public function testOpenRouter(string $key): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->withToken($key)->get('https://openrouter.ai/api/v1/auth/key');
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'OpenRouter ist nicht erreichbar.'];
        }

        return match (true) {
            $response->successful() => ['ok' => true, 'message' => 'Der Schlüssel ist gültig.'],
            in_array($response->status(), [401, 403], true) => ['ok' => false, 'message' => 'OpenRouter lehnt den Schlüssel ab.'],
            default => ['ok' => false, 'message' => 'OpenRouter antwortet mit Fehler '.$response->status().'.'],
        };
    }

    /** @return array{ok: bool, message: string} */
    public function testGoogleMaps(string $key): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => 'Hamburg',
                'key' => $key,
            ]);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Google Maps ist nicht erreichbar.'];
        }

        $status = $response->json('status');

        return in_array($status, ['OK', 'ZERO_RESULTS'], true)
            ? ['ok' => true, 'message' => 'Der Schlüssel funktioniert für die Geocoding-API.']
            : ['ok' => false, 'message' => 'Google lehnt den Schlüssel ab ('.($status ?? 'keine Antwort').'): '.($response->json('error_message') ?? 'Ist die Geocoding-API für den Schlüssel aktiviert?')];
    }

    private function secret(string $setting): ?string
    {
        $encrypted = Setting::get($setting);

        if (blank($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // Saved with another APP_KEY: treat as not set rather than failing every page
            return null;
        }
    }

    private function saveSecret(string $setting, ?string $key): void
    {
        filled($key) ? Setting::set($setting, Crypt::encryptString(trim($key))) : Setting::forget($setting);
    }
}
