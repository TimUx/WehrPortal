<?php

namespace Extensions\Dateien\Office;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Models\Version;

/** One office server type. The editor page only knows this contract. */
interface OfficeConnector
{
    public function key(): string;

    /**
     * Data for the editor view: 'mode' => 'onlyoffice' with 'script' and 'config',
     * or 'mode' => 'wopi' with 'action', 'token' and 'ttl'.
     *
     * @return array<string, mixed>
     */
    public function editor(OfficeSession $session, string $token, User $user, Node $node, Version $version): array;

    /** @return array{ok: bool, message: string} */
    public function test(): array;
}
