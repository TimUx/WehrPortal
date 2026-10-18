<?php

namespace Extensions\Dateien\Office;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Models\Version;
use Illuminate\Support\Facades\Http;
use Throwable;

/** ONLYOFFICE Docs: DocsAPI in the browser, file download and callback signed with the shared JWT secret. */
class OnlyOfficeConnector implements OfficeConnector
{
    private const TYPES = [
        'word' => ['docx', 'doc', 'odt', 'rtf', 'txt'],
        'cell' => ['xlsx', 'xls', 'ods', 'csv'],
        'slide' => ['pptx', 'ppt', 'odp'],
        'pdf' => ['pdf'],
    ];

    public function __construct(private readonly OfficeSettings $settings) {}

    public function key(): string
    {
        return 'onlyoffice';
    }

    public function editor(OfficeSession $session, string $token, User $user, Node $node, Version $version): array
    {
        $base = $this->settings->callbackUrl();
        $config = [
            'document' => [
                'fileType' => $version->extension,
                // Unique per stored content; ONLYOFFICE caches by this key
                'key' => $node->id.'-'.$version->id.'-'.substr($version->checksum, 0, 16),
                'title' => $node->name,
                'url' => $base.route('dateien.office.onlyoffice.file', ['token' => $token], false),
                'permissions' => ['edit' => false, 'comment' => false, 'review' => false, 'fillForms' => false, 'download' => true, 'print' => true],
            ],
            'documentType' => self::documentType($version->extension),
            'editorConfig' => [
                'mode' => 'view',
                'lang' => 'de',
                'callbackUrl' => $base.route('dateien.office.onlyoffice.callback', ['token' => $token], false),
                'user' => ['id' => (string) $user->id, 'name' => $user->name],
                'customization' => ['forcesave' => false, 'chat' => false, 'comments' => false],
            ],
        ];
        $config['token'] = Jwt::encode($config + ['exp' => $session->expires_at->getTimestamp()], (string) $this->settings->secret());

        return ['mode' => 'onlyoffice', 'script' => $this->settings->serverUrl().'/web-apps/apps/api/documents/api.js', 'config' => $config];
    }

    public function test(): array
    {
        try {
            $response = Http::timeout((int) config('dateien.office.timeout', 5))->withOptions(['allow_redirects' => false])
                ->get($this->settings->internalUrl().'/healthcheck');
            if ($response->successful() && trim($response->body()) === 'true') {
                return ['ok' => true, 'message' => 'ONLYOFFICE erreichbar.'.($this->settings->secret() ? '' : ' Es fehlt aber das JWT-Secret.')];
            }

            return ['ok' => false, 'message' => 'ONLYOFFICE antwortet nicht wie erwartet (HTTP '.$response->status().').'];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'ONLYOFFICE nicht erreichbar.'];
        }
    }

    public static function documentType(string $extension): string
    {
        foreach (self::TYPES as $type => $extensions) {
            if (in_array(strtolower($extension), $extensions, true)) {
                return $type;
            }
        }

        return 'word';
    }
}
