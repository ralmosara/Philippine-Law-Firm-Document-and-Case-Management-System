<?php

namespace App\Domain\EInvoicing\Transmitters;

use App\Domain\EInvoicing\EInvoice;

/**
 * The default until the firm chooses a provider: nothing leaves the
 * system. Each e-invoice is built, fingerprinted and kept, so the history
 * is complete and ready to send once a provider is connected.
 */
class RecordOnlyTransmitter implements Transmitter
{
    public function name(): string
    {
        return 'record';
    }

    public function transmit(EInvoice $eInvoice): Result
    {
        return Result::recorded();
    }
}
