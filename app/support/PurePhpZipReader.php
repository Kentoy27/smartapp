<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal pure-PHP zip reader used only when PHP's ZipArchive class is
 * unavailable (e.g. XAMPP's Apache PHP build ships with php_zip disabled).
 * Parses the end-of-central-directory record, walks the central directory,
 * and inflates each entry with gzinflate (deflate) or a raw copy
 * (stored). Enough for .xlsx workbooks, nothing more.
 */
final class PurePhpZipReader
{
    private string $data;

    private int $size;

    public function __construct(string $filePath)
    {
        // The existence check keeps unreadable paths a clean exception —
        // file_get_contents would raise a warning first, which PHPUnit
        // records as a test issue even when suppressed.
        if (! is_file($filePath) || ($data = file_get_contents($filePath)) === false) {
            throw new RuntimeException("The workbook could not be read: {$filePath}");
        }

        $this->data = $data;
        $this->size = strlen($this->data);
    }

    /**
     * Every entry in the archive, indexed by name.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when the data is not a zip archive or an
     *                          entry cannot be read.
     */
    public function readAll(): array
    {
        $eocd = $this->findEndOfCentralDirectory();

        if ($eocd === null) {
            throw new RuntimeException('The file is not a valid .xlsx workbook.');
        }

        $entryCount = unpack('v', substr($this->data, $eocd + 10, 2))[1];
        $offset = unpack('V', substr($this->data, $eocd + 16, 4))[1];

        $entries = [];

        for ($i = 0; $i < $entryCount; $i++) {
            if (substr($this->data, $offset, 4) !== "PK\x01\x02") {
                break; // corrupt trailing records — return what we have
            }

            $method = unpack('v', substr($this->data, $offset + 10, 2))[1];
            $compressedSize = unpack('V', substr($this->data, $offset + 20, 4))[1];
            $nameLength = unpack('v', substr($this->data, $offset + 28, 2))[1];
            $extraLength = unpack('v', substr($this->data, $offset + 30, 2))[1];
            $commentLength = unpack('v', substr($this->data, $offset + 32, 2))[1];
            $localOffset = unpack('V', substr($this->data, $offset + 42, 4))[1];
            $name = substr($this->data, $offset + 46, $nameLength);

            if ($compressedSize > 0) {
                $entries[$name] = $this->extractToMemory($localOffset, $method, $compressedSize);
            }

            $offset += 46 + $nameLength + $extraLength + $commentLength;
        }

        return $entries;
    }

    /**
     * The "end of central directory" signature sits at the very end of
     * the archive (plus a ≤64KB comment), so scan backwards for it.
     */
    private function findEndOfCentralDirectory(): ?int
    {
        $scanFrom = max(0, $this->size - 65558);
        $position = strrpos($this->data, "PK\x05\x06");

        while ($position !== false && $position >= $scanFrom) {
            return $position;
        }

        return null;
    }

    /**
     * Read one entry via its local file header (the central directory
     * points at it), then inflate or copy the payload.
     */
    private function extractToMemory(int $localOffset, int $method, int $compressedSize): string
    {
        if (substr($this->data, $localOffset, 4) !== "PK\x03\x04") {
            throw new RuntimeException('The file is not a valid .xlsx workbook.');
        }

        $flags = unpack('v', substr($this->data, $localOffset + 6, 2))[1];
        $nameLength = unpack('v', substr($this->data, $localOffset + 26, 2))[1];
        $extraLength = unpack('v', substr($this->data, $localOffset + 28, 2))[1];
        $dataOffset = $localOffset + 30 + $nameLength + $extraLength;
        $payload = substr($this->data, $dataOffset, $compressedSize);

        // Bit 0 = encrypted entries are not supported; bit 3 (data
        // descriptor) doesn't matter because sizes come from the central
        // directory.
        if (($flags & 1) === 1) {
            throw new RuntimeException('The workbook is encrypted and cannot be read.');
        }

        if ($method === 0) { // stored
            return $payload;
        }

        if ($method === 8) { // deflate
            $inflated = @gzinflate($payload);

            if ($inflated === false) {
                throw new RuntimeException('The file is not a valid .xlsx workbook.');
            }

            return $inflated;
        }

        throw new RuntimeException('The workbook uses an unsupported compression method.');
    }
}
