<?php

namespace App\Domain\EInvoicing\Transmitters;

use App\Domain\EInvoicing\EInvoice;

/**
 * Sends an e-invoice to the BIR, directly or through an accredited
 * provider. Each provider gets its own implementation that maps the
 * neutral document (EInvoiceDocument) to the format it expects.
 *
 * Return a Result; throw only for failures worth retrying (the provider
 * is down or unreachable).
 */
interface Transmitter
{
    /** Short name stored on each e-invoice ("record", "http", ...). */
    public function name(): string;

    public function transmit(EInvoice $eInvoice): Result;
}
