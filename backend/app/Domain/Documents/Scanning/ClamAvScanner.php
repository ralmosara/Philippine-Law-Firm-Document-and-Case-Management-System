<?php

namespace App\Domain\Documents\Scanning;

use Closure;

/**
 * Streams a file to a ClamAV daemon (clamd) over TCP using the INSTREAM
 * command, so the scanner needs no access to the application's disk.
 *
 * Protocol: "zINSTREAM\0", then chunks each prefixed by their length as a
 * 4-byte big-endian integer, then a zero-length chunk. clamd replies with
 * "stream: OK", "stream: <signature> FOUND" or "<reason> ERROR".
 */
class ClamAvScanner implements VirusScanner
{
    private const CHUNK_BYTES = 8192;

    /** @var Closure(): resource */
    private readonly Closure $connect;

    /**
     * @param  (Closure(): resource)|null  $connect  opens the connection (injectable for tests)
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 3310,
        private readonly int $timeoutSeconds = 30,
        ?Closure $connect = null,
    ) {
        $this->connect = $connect ?? function () {
            $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, 5);

            if ($socket === false) {
                throw new ScannerUnavailable("Cannot reach the virus scanner at {$this->host}:{$this->port}: {$error}");
            }

            return $socket;
        };
    }

    public function scan(string $path): ScanResult
    {
        $file = @fopen($path, 'rb');

        if ($file === false) {
            throw new ScannerUnavailable('The uploaded file could not be read for scanning.');
        }

        $socket = ($this->connect)();
        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            $this->write($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new ScannerUnavailable('The uploaded file could not be read for scanning.');
                }

                if ($chunk !== '') {
                    $this->write($socket, pack('N', strlen($chunk)).$chunk);
                }
            }

            $this->write($socket, pack('N', 0));

            return $this->parse($this->readReply($socket));
        } finally {
            fclose($file);
            fclose($socket);
        }
    }

    private function parse(string $reply): ScanResult
    {
        $reply = trim($reply, "\0\r\n ");

        if (str_ends_with($reply, ' OK')) {
            return ScanResult::clean();
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reply, $match)) {
            return ScanResult::infected($match[1]);
        }

        // e.g. "INSTREAM size limit exceeded. ERROR": no verdict.
        throw new ScannerUnavailable("The virus scanner could not scan the file ({$reply}).");
    }

    /** @param  resource  $socket */
    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($socket, $data);

            if ($written === false || $written === 0) {
                throw new ScannerUnavailable('The connection to the virus scanner was lost.');
            }

            $data = substr($data, $written);
        }
    }

    /** @param  resource  $socket */
    private function readReply($socket): string
    {
        $reply = '';

        while (! str_contains($reply, "\0") && ! feof($socket)) {
            $part = fread($socket, 1024);

            if ($part === false || ($part === '' && stream_get_meta_data($socket)['timed_out'])) {
                throw new ScannerUnavailable('The virus scanner did not answer in time.');
            }

            $reply .= $part;
        }

        if ($reply === '') {
            throw new ScannerUnavailable('The virus scanner closed the connection without an answer.');
        }

        return $reply;
    }
}
