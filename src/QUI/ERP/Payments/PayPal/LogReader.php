<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal;

use DirectoryIterator;
use InvalidArgumentException;
use RuntimeException;

/** Read a bounded tail of the PayPal API logs; never expose arbitrary server files. */
final class LogReader
{
    public const MAX_BYTES = 262144;
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? VAR_DIR . 'log';
    }

    /** @return array{files: list<string>, selected: string, data: string, truncated: bool} */
    public function read(string $file = ''): array
    {
        if ($file !== '' && !$this->validName($file)) {
            throw new InvalidArgumentException('Invalid PayPal log filename.');
        }

        $directory = realpath($this->directory);
        $files = [];

        if ($directory !== false && is_dir($directory)) {
            foreach (new DirectoryIterator($directory) as $File) {
                if (!$File->isLink() && $File->isFile() && $this->validName($File->getFilename())) {
                    $files[] = $File->getFilename();
                }
            }
        }

        rsort($files, SORT_STRING);
        $files = array_slice($files, 0, 100);
        $file = $file === '' ? ($files[0] ?? '') : $file;
        $result = ['files' => $files, 'selected' => $file, 'data' => '', 'truncated' => false];

        if ($file === '') {
            return $result;
        }

        if ($directory === false || !in_array($file, $files, true)) {
            throw new RuntimeException('PayPal log is not available.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . $file;
        $realPath = realpath($path);

        if ($realPath === false || is_link($path) || dirname($realPath) !== $directory) {
            throw new RuntimeException('PayPal log is not available.');
        }

        $Handle = @fopen($realPath, 'rb');

        if ($Handle === false) {
            throw new RuntimeException('PayPal log is not readable.');
        }

        try {
            $stat = fstat($Handle);
            $size = $stat === false ? 0 : $stat['size'];
            $result['truncated'] = $size > self::MAX_BYTES;

            if ($result['truncated']) {
                fseek($Handle, -self::MAX_BYTES, SEEK_END);
            }

            $data = stream_get_contents($Handle, self::MAX_BYTES);

            if ($data === false) {
                throw new RuntimeException('PayPal log could not be read.');
            }

            // Drop the partial first line when reading the tail of a large file.
            if ($result['truncated'] && ($newline = strpos($data, "\n")) !== false) {
                $data = substr($data, $newline + 1);
            }

            $result['data'] = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        } finally {
            fclose($Handle);
        }

        return $result;
    }

    private function validName(string $file): bool
    {
        return preg_match('/\Apaypal_api-\d{4}-\d{2}-\d{2}\.log\z/D', $file) === 1;
    }
}
