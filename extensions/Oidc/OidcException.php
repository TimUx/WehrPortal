<?php

namespace Extensions\Oidc;

use RuntimeException;

/** A failed OIDC step. The message is safe to show; the reason is only for the log (never a secret or token). */
class OidcException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'failed')
    {
        parent::__construct($message);
    }

    public static function of(string $key, string $reason): self
    {
        return new self(__('oidc::oidc.error.'.$key), $reason);
    }
}
