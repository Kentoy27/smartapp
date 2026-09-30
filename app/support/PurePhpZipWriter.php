<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal pure-PHP zip writer — the mirror of PurePhpZipReader. Used to
 * build in-memory .xlsx workbooks (a zip of XML parts) without depending
 * on PHP's ZipArchive class, which the XAMPP web-server PHP build ships
 * disabled. Entries are deflate-compressed when zlib's gzdeflate is
 * available and stored raw otherwise — the reader handles both.
 *
 * @see PurePhpZipReader
 */
final class PurePhpZipWriter
{
    /**
     * The assembled archive: local file headers + payloads, then the
     * central directory and its end-of-central-directory record.
     */
    public static function create(array $entries): string
    {
        if ($entries === []) {
            throw new RuntimeException('A zip archive needs at least one entry.');
        }

        $canDeflate = function_exists('gzdeflate');

        $local = '';
        $central = '';
        $offset = 0;

        foreach ($entries as $name => $contents) {
            $name = (string) $name;

            if ($name === '' || strlen($name) > 65535) {
                throw new RuntimeException('Invalid zip entry name.');
            }

            $crc = crc32($contents);
            $uncompressedSize = strlen($contents);

            if ($canDeflate) {
                $deflated = (string) gzdeflate($contents, 6);
                $method = 8; // deflate

                // Small payloads can deflate larger than themselves.
                if (strlen($deflated) < $uncompressedSize) {
                    $payload = $deflated;
                } else {
                    $method = 0; // stored
                    $payload = $contents;
                }
            } else {
                $method = 0;
                $payload = $contents;
            }

            $compressedSize = strlen($payload);
            $nameLength = strlen($name);

            $localHeader = "PK\x03\x04"
                .pack('v', 20) // version needed
                .pack('v', 0) // flags
                .pack('v', $method)
                .pack('v', 0) // dos time
                .pack('v', 0) // dos date
                .pack('V', $crc)
                .pack('V', $compressedSize)
                .pack('V', $uncompressedSize)
                .pack('v', $nameLength)
                .pack('v', 0) // extra length
                .$name;

            $centralEntry = "PK\x01\x02"
                .pack('v', 20) // version made by
                .pack('v', 20) // version needed
                .pack('v', 0) // flags
                .pack('v', $method)
                .pack('v', 0) // dos time
                .pack('v', 0) // dos date
                .pack('V', $crc)
                .pack('V', $compressedSize)
                .pack('V', $uncompressedSize)
                .pack('v', $nameLength)
                .pack('v', 0) // extra length
                .pack('v', 0) // comment length
                .pack('v', 0) // disk number
                .pack('v', 0) // internal attrs
                .pack('V', 0) // external attrs
                .pack('V', $offset)
                .$name;

            $local .= $localHeader.$payload;
            $central .= $centralEntry;

            $offset += strlen($localHeader) + $compressedSize;
        }

        $centralOffset = $offset;
        $centralSize = strlen($central);
        $entryCount = count($entries);

        $eocd = "PK\x05\x06"
            .pack('v', 0) // disk number
            .pack('v', 0) // central directory disk
            .pack('v', $entryCount)
            .pack('v', $entryCount)
            .pack('V', $centralSize)
            .pack('V', $centralOffset)
            .pack('v', 0); // comment length

        return $local.$central.$eocd;
    }
}
