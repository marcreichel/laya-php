<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

/**
 * Reads labelled examples for an {@see Evaluation} from a CSV file with a header row (.csv) or JSON Lines (.jsonl, .ndjson).
 * Each row has a "state" column and one column per labelled question: the enum value, level index, or true/false.
 *
 *     state,department,urgency,churn
 *     "Billed twice, refund it or we cancel.",billing,2,true
 *
 *     {"state": "Billed twice, refund it or we cancel.", "department": "billing", "urgency": 2, "churn": true}
 */
final class LabelledRows
{
    /**
     * @return array<int, array<mixed>> rows by their row number in the file, the CSV header being row 1; blank lines are skipped
     */
    public static function read(string $path): array
    {
        $handle = is_file($path) ? @fopen($path, 'r') : false;
        if ($handle === false) {
            throw new \UnexpectedValueException(sprintf('Cannot read "%s".', $path));
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv' => self::csv($handle),
            'jsonl', 'ndjson' => self::jsonLines($handle),
            default => throw new \UnexpectedValueException(sprintf('"%s" is neither CSV (.csv) nor JSON Lines (.jsonl, .ndjson).', $path)),
        };
    }

    /**
     * @param  resource  $handle
     * @return array<int, array<string, string|null>>
     */
    private static function csv($handle): array
    {
        $header = fgetcsv($handle, escape: '');
        if ($header === false) {
            throw new \UnexpectedValueException('The CSV file is empty; it needs a header row with a "state" column.');
        }
        // Spreadsheets save UTF-8 with a byte order mark in front of the first column's name.
        $header = array_map(fn (?string $name) => trim((string) $name, "\u{FEFF} "), $header);
        $duplicates = array_keys(array_filter(array_count_values($header), fn (int $n) => $n > 1));
        if ($duplicates !== []) {
            throw new \UnexpectedValueException(sprintf('The CSV header repeats %s.', implode(', ', $duplicates)));
        }

        $rows = [];
        $row = 1;
        while (($fields = fgetcsv($handle, escape: '')) !== false) {
            $row++;
            if ($fields === [null]) {
                continue;
            }
            if (count($fields) !== count($header)) {
                throw new \UnexpectedValueException(sprintf('Row %d has %d fields, but the header has %d.', $row, count($fields), count($header)));
            }
            $rows[$row] = array_combine($header, $fields);
        }

        return $rows;
    }

    /**
     * @param  resource  $handle
     * @return array<int, array<mixed>>
     */
    private static function jsonLines($handle): array
    {
        $rows = [];
        $row = 0;
        while (($line = fgets($handle)) !== false) {
            $row++;
            if (trim($line) === '') {
                continue;
            }
            $values = json_decode($line, true);
            if (! is_array($values) || array_is_list($values)) {
                throw new \UnexpectedValueException(sprintf('Row %d is not a JSON object.', $row));
            }
            $rows[$row] = $values;
        }

        return $rows;
    }
}
