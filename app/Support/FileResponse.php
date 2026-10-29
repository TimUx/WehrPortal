<?php

namespace App\Support;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Serves a stored upload safely: the Content-Type comes from a fixed list by the file's extension
 * (never from what the browser or a sniffer said at upload), only PDFs and photos open in the
 * browser, everything else is downloaded, and a sandbox CSP plus nosniff keep any HTML or script
 * inside a file from running on the app's origin (PDFs are left out of the sandbox, the browsers' viewers need it). The file name is encoded properly (RFC 6266).
 */
class FileResponse
{
    /** Opened in the browser */
    public const INLINE = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    /** Downloaded */
    public const DOWNLOAD = [
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'md' => 'text/markdown',
    ];

    /** Extensions an upload may have (for validation rules: 'mimes:'.implode(',', ...)) */
    public static function allowedExtensions(bool $office = true): array
    {
        return array_keys($office ? self::INLINE + self::DOWNLOAD : self::INLINE);
    }

    public static function make(string $bytes, string $filename, string $cache = 'private, max-age=3600'): Response
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $inline = isset(self::INLINE[$extension]);
        $type = self::INLINE[$extension] ?? self::DOWNLOAD[$extension] ?? 'application/octet-stream';
        $name = str_replace(["\r", "\n", '/', '\\'], '', $filename) ?: 'datei';

        $headers = [
            'Content-Type' => $type.(str_starts_with($type, 'text/') ? '; charset=utf-8' : ''),
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $name, Str::ascii($name) ?: 'datei'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $cache,
        ];
        // The browsers' PDF viewers refuse to work in a sandbox; PDFs can't run script on our origin anyway
        if ($type !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'";
        }

        return response($bytes, 200, $headers);
    }
}
