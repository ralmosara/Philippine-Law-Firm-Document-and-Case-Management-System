<?php

namespace App\Domain\EInvoicing\Transmitters;

use App\Domain\EInvoicing\EInvoice;

/** What a transmitter got back: the new status, the provider's reference, and any reason given. */
final class Result
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly ?string $error = null,
    ) {}

    public static function recorded(): self
    {
        return new self(EInvoice::RECORDED);
    }

    public static function submitted(?string $reference): self
    {
        return new self(EInvoice::SUBMITTED, $reference);
    }

    public static function accepted(?string $reference): self
    {
        return new self(EInvoice::ACCEPTED, $reference);
    }

    public static function rejected(string $reason, ?string $reference = null): self
    {
        return new self(EInvoice::REJECTED, $reference, $reason);
    }
}
