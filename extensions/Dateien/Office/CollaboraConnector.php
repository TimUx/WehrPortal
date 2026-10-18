<?php

namespace Extensions\Dateien\Office;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Models\Version;
use RuntimeException;
use Throwable;

/** Collabora Online over WOPI: the editor page posts the token to the action URL from discovery. */
class CollaboraConnector implements OfficeConnector
{
    public function __construct(private readonly OfficeSettings $settings, private readonly WopiDiscovery $discovery) {}

    public function key(): string
    {
        return 'collabora';
    }

    public function editor(OfficeSession $session, string $token, User $user, Node $node, Version $version): array
    {
        $action = $this->discovery->actionUrl($version->extension);
        if ($action === null) {
            throw new RuntimeException('Der Office-Server kann diese Datei nicht öffnen.');
        }
        $source = $this->settings->callbackUrl().route('dateien.office.wopi.file', ['node' => $node->id], false);

        return [
            'mode' => 'wopi',
            'action' => $action.'WOPISrc='.rawurlencode($source).'&lang=de',
            'token' => $token,
            'ttl' => $session->expires_at->getTimestamp() * 1000,
        ];
    }

    public function test(): array
    {
        try {
            $discovery = $this->discovery->get(true);
            if ($discovery['actions'] === []) {
                return ['ok' => false, 'message' => 'Discovery enthält keine Aktionen.'];
            }
            if ($discovery['proof'] === null) {
                return ['ok' => false, 'message' => 'Discovery enthält keinen Proof-Key; Anfragen können nicht geprüft werden.'];
            }

            return ['ok' => true, 'message' => 'Collabora erreichbar ('.count($discovery['actions']).' Dateitypen, Proof-Key vorhanden).'];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Collabora nicht erreichbar oder Discovery ungültig.'];
        }
    }
}
