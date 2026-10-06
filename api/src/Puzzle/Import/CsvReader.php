<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

/**
 * Streams the lines of export files, one at a time (constant memory over ~5M lines). Accepts both
 * the official export (comma, header line) and split copies of it (no header, tabs, CRLF): the
 * separator is guessed from each file's first line.
 */
final class CsvReader
{
    /**
     * The export files named by the arguments: a directory gives its `*.csv` files.
     *
     * @param list<string> $paths files or directories
     *
     * @return list<string> in natural order (part-2 before part-10)
     */
    public static function files(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $files = [...$files, ...(glob(rtrim($path, '/').'/*.csv') ?: [])];
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                throw new \InvalidArgumentException(\sprintf('No such file or directory: %s', $path));
            }
        }
        natsort($files);

        return array_values(array_unique($files));
    }

    /**
     * @param callable(int $line, string $reason): void $onInvalid called for each skipped line
     *
     * @return \Generator<int, CsvRow> by line number
     */
    public function rows(string $file, callable $onInvalid): \Generator
    {
        $handle = fopen($file, 'r');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot read %s', $file));
        }

        try {
            $first = fgets($handle);
            $separator = false !== $first && str_contains($first, "\t") ? "\t" : ',';
            rewind($handle);

            $line = 0;
            while (false !== ($fields = fgetcsv($handle, null, $separator, '"', ''))) {
                ++$line;
                if ([null] === $fields || (1 === $line && 'PuzzleId' === $fields[0])) {
                    continue;
                }
                try {
                    yield $line => LineParser::parse($fields);
                } catch (InvalidLineException $e) {
                    $onInvalid($line, $e->getMessage());
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
