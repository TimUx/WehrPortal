<?php

namespace Extensions\Mitteilungen\Services;

use Extensions\Mitteilungen\Models\ChannelDelivery;
use Extensions\Mitteilungen\Models\ChannelDestination;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Mitteilungen\src\Models\Announcement;
use Throwable;

class AnnouncementDelivery
{
    public function __construct(private ChannelSettings $settings) {}

    /** @param array{ntfy: bool, matrix: bool} $requested @return list<string> */
    public function send(Announcement $announcement, array $requested): array
    {
        $result = [];

        foreach (ChannelSettings::TRANSPORTS as $transport) {
            if (! ($requested[$transport] ?? false)) {
                continue;
            }

            $result[$transport] = $this->sendOne($announcement, $transport);
        }

        return array_values($result);
    }

    private function sendOne(Announcement $announcement, string $transport): string
    {
        if (($announcement->visible_from && $announcement->visible_from->isFuture())
            || ($announcement->visible_until && $announcement->visible_until->isPast())) {
            return $this->label($transport).': nicht gesendet – die Mitteilung ist noch nicht oder nicht mehr veröffentlicht.';
        }

        if ($announcement->groups()->exists() || $announcement->functions()->exists()) {
            return $this->label($transport).': nicht gesendet – Abteilungs- oder Funktionszielgruppen sind für gemeinsame Kanäle zu eng.';
        }

        $locationId = $announcement->ext_location_id ? (int) $announcement->ext_location_id : null;
        $scopeKey = $locationId === null ? 'general' : 'location:'.$locationId;
        $destination = $this->settings->destinationFor($locationId, $transport);
        if (! $destination) {
            return $this->label($transport).': nicht gesendet – für diesen Geltungsbereich ist kein aktives Ziel vollständig eingerichtet.';
        }

        if ($this->destinationIsShared($destination, $scopeKey)) {
            return $this->label($transport).': nicht gesendet – dieses Ziel ist bereits einem anderen Geltungsbereich zugeordnet.';
        }

        $token = $this->settings->token($destination);
        if (! $token) {
            return $this->label($transport).': nicht gesendet – das Zugriffstoken fehlt oder kann nicht entschlüsselt werden.';
        }

        $identity = mb_strtolower(rtrim((string) $destination->server_url, '/')).'|'.$destination->target;
        $content = trim($announcement->title)."\n\n".trim((string) $announcement->body)."\n\n".route('mitteilungen.index');
        $fingerprint = hash('sha256', $identity."\0".$content);
        $transactionId = 'ow'.substr(hash('sha256', $announcement->id."\0".$scopeKey."\0".$transport."\0".$fingerprint), 0, 62);
        $claim = $this->claim($announcement, $scopeKey, $transport, $fingerprint, $transactionId);

        if (! $claim['claimed']) {
            return $this->label($transport).': '.$claim['message'];
        }

        try {
            $response = $transport === 'ntfy'
                ? $this->publishNtfy($destination, $token, $announcement)
                : $this->publishMatrix($destination, $token, $announcement, $transactionId);

            if ($response === 'encrypted') {
                $this->finish($claim['delivery'], false, 'Der Matrix-Raum ist verschlüsselt; Klartext wurde nicht gesendet.');

                return 'Matrix: nicht gesendet – der Raum ist verschlüsselt. Für E2EE-Versand ist diese Integration nicht geeignet.';
            }

            if ($response->successful()) {
                $this->finish($claim['delivery'], true, 'Erfolgreich gesendet.');

                return $this->label($transport).': erfolgreich gesendet.';
            }

            $message = $this->failureMessage($transport, $response->status());
            $this->finish($claim['delivery'], false, $message);

            return $this->label($transport).': '.$message;
        } catch (Throwable) {
            $this->finish($claim['delivery'], false, 'Verbindung fehlgeschlagen oder Server nicht zulässig.');

            return $this->label($transport).': Verbindung fehlgeschlagen oder Server nicht zulässig. Einstellungen prüfen.';
        }
    }

    /** @return array{claimed: bool, delivery: ?ChannelDelivery, message: string} */
    private function claim(Announcement $announcement, string $scopeKey, string $transport, string $fingerprint, string $transactionId): array
    {
        $delivery = DB::transaction(function () use ($announcement, $scopeKey, $transport, $fingerprint, $transactionId) {
            ChannelDelivery::query()->firstOrCreate([
                'announcement_id' => $announcement->id,
                'scope_key' => $scopeKey,
                'transport' => $transport,
                'fingerprint' => $fingerprint,
            ], [
                'status' => 'pending',
                'attempts' => 0,
                'transaction_id' => $transport === 'matrix' ? $transactionId : null,
            ]);

            $delivery = ChannelDelivery::query()->where('announcement_id', $announcement->id)
                ->where('scope_key', $scopeKey)->where('transport', $transport)->where('fingerprint', $fingerprint)
                ->lockForUpdate()->firstOrFail();

            if ($delivery->status === 'sent') {
                return ['claimed' => false, 'delivery' => $delivery, 'message' => 'dieser Nachrichtenstand wurde bereits gesendet.'];
            }
            if ($delivery->status === 'sending') {
                return ['claimed' => false, 'delivery' => $delivery, 'message' => 'der Versand dieses Nachrichtenstands läuft bereits.'];
            }
            $limit = $transport === 'matrix' ? 3 : 1;
            if ($delivery->attempts >= $limit) {
                return ['claimed' => false, 'delivery' => $delivery, 'message' => 'kein sicherer weiterer Versuch möglich; Inhalt und Ziel prüfen und eine neue Mitteilung anlegen.'];
            }

            $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'result' => null]);

            return ['claimed' => true, 'delivery' => $delivery->refresh(), 'message' => ''];
        });

        return $delivery;
    }

    private function publishNtfy(ChannelDestination $destination, string $token, Announcement $announcement): Response
    {
        $url = rtrim((string) $destination->server_url, '/').'/'.rawurlencode((string) $destination->target);
        $title = preg_replace('/[\r\n\p{C}]+/u', ' ', trim($announcement->title)) ?: 'Mitteilung';
        $title = mb_substr($title, 0, 200);

        return $this->request('POST', $url, $token, ['Title' => $title, 'Content-Type' => 'text/plain; charset=utf-8'],
            trim((string) $announcement->body));
    }

    private function publishMatrix(ChannelDestination $destination, string $token, Announcement $announcement, string $transactionId): Response|string
    {
        $base = rtrim((string) $destination->server_url, '/');
        $room = rawurlencode((string) $destination->target);
        $stateUrl = $base.'/_matrix/client/v3/rooms/'.$room.'/state/m.room.encryption';
        $encryption = $this->request('GET', $stateUrl, $token);
        if ($encryption->successful()) {
            return 'encrypted';
        }
        if ($encryption->status() !== 404) {
            return $encryption;
        }

        $url = $base.'/_matrix/client/v3/rooms/'.$room.'/send/m.room.message/'.rawurlencode($transactionId);
        $message = trim($announcement->title)."\n\n".trim((string) $announcement->body)."\n\n".route('mitteilungen.index');

        return $this->request('PUT', $url, $token, ['Content-Type' => 'application/json'], json_encode([
            'msgtype' => 'm.text',
            'body' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $url, string $token, array $headers = [], ?string $body = null): Response
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! in_array($parts['port'] ?? 443, [443], true)) {
            throw new \RuntimeException('Only public HTTPS targets are allowed.');
        }

        [$host, $port, $ip] = \App\Support\SafeUrl::check($url);
        $options = [
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip]],
        ];
        $request = Http::timeout(8)->withToken($token)->acceptJson()->withHeaders($headers)->withOptions($options);

        return $request->send($method, $url, $body === null ? [] : ['body' => $body]);
    }

    private function finish(ChannelDelivery $delivery, bool $sent, string $result): void
    {
        $delivery->update([
            'status' => $sent ? 'sent' : 'failed',
            'result' => $result,
            'sent_at' => $sent ? now() : null,
        ]);
    }

    private function destinationIsShared(ChannelDestination $destination, string $scopeKey): bool
    {
        return ChannelDestination::query()->where('transport', $destination->transport)
            ->where('scope_key', '!=', $scopeKey)
            ->whereRaw('LOWER(server_url) = ?', [mb_strtolower(rtrim((string) $destination->server_url, '/'))])
            ->where('target', $destination->target)->exists();
    }

    private function failureMessage(string $transport, int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'Zugriff abgelehnt; ntfy-Token bzw. Matrix-Bot-Mitgliedschaft und Senderecht prüfen.',
            $status === 429 => 'Der Zielserver begrenzt den Versand; später erneut versuchen.',
            default => 'Der Zielserver antwortet mit HTTP '.$status.'; Server, Ziel und Berechtigung prüfen.',
        };
    }

    private function label(string $transport): string
    {
        return $transport === 'ntfy' ? 'ntfy' : 'Matrix';
    }
}
