<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * Whether the outside services work: sending mail (SMTP or Amazon SES), the Google API and
 * OpenRouter. Checked at most every 15 minutes (or "Jetzt prüfen"), so the admin dashboard stays fast.
 */
class ServiceHealth
{
    public const CACHE_KEY = 'service_health';

    public const CACHE_MINUTES = 15;

    public function __construct(private readonly ServiceSettings $settings) {}

    /** @return array{checked_at: string, checks: list<array{label: string, status: string, message: string}>} */
    public function status(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), fn () => [
            'checked_at' => now()->toIso8601String(),
            'checks' => [$this->mail(), $this->google(), $this->openRouter()],
        ]);
    }

    /** @return array{label: string, status: string, message: string} status: ok, warning, error or off */
    public function mail(): array
    {
        $mailer = (string) config('mail.default');
        $label = 'E-Mail-Versand';

        if (in_array($mailer, ['log', 'array'], true)) {
            return ['label' => $label, 'status' => 'warning', 'message' => 'MAIL_MAILER='.$mailer.': Es werden keine E-Mails verschickt, sie landen nur im Log.'];
        }

        try {
            $transport = Mail::mailer()->getSymfonyTransport();

            if ($transport instanceof SmtpTransport) {
                // Connect and log in, then hang up – no mail is sent
                $transport->start();
                $transport->stop();

                return ['label' => $label, 'status' => 'ok', 'message' => 'SMTP-Server '.config('mail.mailers.smtp.host').' erreichbar, Anmeldung klappt.'];
            }

            if (method_exists($transport, 'ses')) {
                $client = $transport->ses();
                $quota = method_exists($client, 'getAccount') && $mailer === 'ses-v2' ? $client->getAccount()->toArray() : $client->getSendQuota()->toArray();
                $max = $quota['SendQuota']['Max24HourSend'] ?? $quota['Max24HourSend'] ?? null;
                $sent = $quota['SendQuota']['SentLast24Hours'] ?? $quota['SentLast24Hours'] ?? null;

                return ['label' => $label, 'status' => 'ok', 'message' => 'Amazon SES antwortet'.($max !== null ? sprintf(' – %d von %d Mails in 24 Stunden.', $sent, $max) : '.')];
            }

            return ['label' => $label, 'status' => 'ok', 'message' => 'Versand über „'.$mailer.'“ eingerichtet (nicht prüfbar).'];
        } catch (Throwable $e) {
            return ['label' => $label, 'status' => 'error', 'message' => 'Versand über „'.$mailer.'“ geht nicht: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    public function google(): array
    {
        $key = $this->settings->googleMapsKey();

        return $key ? $this->result('Google API (Maps/Geocoding)', $this->settings->testGoogleMaps($key)) : ['label' => 'Google API (Maps/Geocoding)', 'status' => 'off', 'message' => 'Kein Schlüssel hinterlegt (Admin → Einstellungen → Dienste).'];
    }

    public function openRouter(): array
    {
        $key = $this->settings->openRouterKey();

        return $key ? $this->result('OpenRouter (KI)', $this->settings->testOpenRouter($key)) : ['label' => 'OpenRouter (KI)', 'status' => 'off', 'message' => 'Kein Schlüssel hinterlegt (Admin → Einstellungen → Dienste).'];
    }

    private function result(string $label, array $test): array
    {
        return ['label' => $label, 'status' => $test['ok'] ? 'ok' : 'error', 'message' => $test['message']];
    }
}
