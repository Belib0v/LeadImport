<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Parses Excel cell values of one sheet row into one `leads` row:
 * serial dates, phones stored as numbers, budgets like "1 500,50".
 *
 * A row is never rejected. A value that fails validation is stored as NULL and its
 * original text is kept in the `invalid` column (JSON), e.g. {"phone": "12345"}.
 */
final class ExcelParser
{
    /** Columns of the `leads` table that come from the sheet; the header names them. */
    public const FIELDS = [
        'external_id',
        'created_at',
        'first_name',
        'last_name',
        'phone',
        'email',
        'city',
        'source',
        'utm_campaign',
        'product',
        'budget_uah',
        'status',
        'manager',
        'comment',
        'next_contact_at'];

    /** Column where the original texts of invalid values go. */
    public const INVALID_FIELD = 'invalid';

    private const REQUIRED_FIELDS = ['external_id'];

    /** Text column sizes from schema.sql, in characters. */
    private const MAX_LENGTH = [
        'external_id' => 32,
        'first_name' => 100,
        'last_name' => 100,
        'email' => 255,
        'city' => 100,
        'source' => 100,
        'utm_campaign' => 100,
        'product' => 100,
        'status' => 32,
        'manager' => 100,
        'comment' => 5000,
    ];

    /**
     * Finds which sheet column holds which field, by the header text.
     *
     * @param array<string, ?string> $headerRow column letter => header text, e.g. ['A' => 'external_id']
     * @return array<string, string> column letter => field
     */
    public function mapHeaderToFields(array $headerRow): array
    {
        $columnToField = [];
        foreach ($headerRow as $column => $title) {
            $field = mb_strtolower(trim((string)$title));
            if (in_array($field, self::FIELDS, true)) {
                $columnToField[$column] = $field;
            }
        }

        $missing = array_diff(self::REQUIRED_FIELDS, $columnToField);
        if ($missing) {
            throw new InvalidArgumentException('Missing columns: ' . implode(', ', $missing));
        }

        return $columnToField;
    }

    /**
     * @param array<string, ?string> $cells column letter => cell value
     * @param array<string, string> $columnToField result of mapHeaderToFields()
     * @return array<string, ?string> field => clean value, plus INVALID_FIELD
     */
    public function parseRow(array $cells, array $columnToField): array
    {
        $lead = array_fill_keys(self::FIELDS, null);
        $invalid = [];

        foreach ($columnToField as $column => $field) {
            $value = $cells[$column] ?? null;
            if ($value === null) {
                continue;
            }

            $clean = match ($field) {
                'created_at', 'next_contact_at' => $this->parseDate($value),
                'phone' => $this->parsePhone($value),
                'email' => filter_var(mb_strtolower($value), FILTER_VALIDATE_EMAIL) ?: null,
                'budget_uah' => $this->parseBudget($value),
                default => $value,
            };

            $maxLength = self::MAX_LENGTH[$field] ?? null;
            if ($clean === null) {
                $invalid[$field] = $value;
            } elseif ($maxLength !== null && mb_strlen($clean) > $maxLength) {
                $invalid[$field] = $value; // cut so the INSERT cannot fail, the full text is kept here
                $clean = mb_substr($clean, 0, $maxLength);
            }
            $lead[$field] = $clean;
        }

        $lead[self::INVALID_FIELD] = $invalid ? json_encode($invalid, JSON_UNESCAPED_UNICODE) : null;

        return $lead;
    }

    /**
     * Excel serial date (45848.213125) or a date string like "2025-07-10 05:06:54" or "10.07.2025 05:06"
     * -> "2025-07-10 05:06:54", no time zone shift.
     * Invalid: any other text ("tomorrow"), a date that does not exist (31.02.2025), a year outside 2000–2100.
     */
    private function parseDate(string $value): ?string
    {
        if (is_numeric($value)) {
            $date = new DateTimeImmutable('@' . (int)round(($value - 25569) * 86400)); // 25569 = 1970-01-01 in Excel
        } else {
            $date = null;
            foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y-m-d\TH:i:s', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $format) {
                $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
                // createFromFormat() silently turns 31.02 into 03.03; formatting it back catches that
                if ($parsed && $parsed->format($format) === $value) {
                    $date = $parsed;
                    break;
                }
            }
        }

        $year = (int)$date?->format('Y');

        return $year >= 2000 && $year <= 2100 ? $date->format('Y-m-d H:i:s') : null;
    }

    /** "+38 (067) 123-45-67", "0671234567", Excel number 380671234567.0 or 3.80671234567E+11 -> "+380671234567". */
    private function parsePhone(string $value): ?string
    {
        // Scientific notation holds a whole phone only if every digit is written out:
        // 3.80671234567E+11 is 380671234567, but 3.8E+11 has lost 10 digits and is invalid.
        if (preg_match('~^(\d+)\.?(\d*)E\+(\d+)$~i', $value, $scientific)) {
            [, $integerPart, $fraction, $exponent] = $scientific;
            if (strlen($fraction) !== (int)$exponent) {
                return null;
            }
            $value = $integerPart . $fraction;
        }
        $value = preg_replace('~^(\d+)\.0+$~', '$1', $value);

        if (!preg_match('~^[\d\s()+\-]+$~', $value)) {
            return null; // letters or other garbage
        }
        $digits = preg_replace('~\D~', '', $value);

        // 0 + operator or area code (never 00) + 8 digits, with or without the country code 38
        return preg_match('~^(?:38)?(0[1-9]\d{8})$~', $digits, $m) ? '+38' . $m[1] : null;
    }

    /** "1 500,50" or 1500.5 -> "1500.50"; must fit DECIMAL(12,2). */
    private function parseBudget(string $value): ?string
    {
        $number = str_replace([' ', "\u{A0}", ','], ['', '', '.'], $value);
        if (!is_numeric($number)) {
            return null;
        }
        $number = round((float)$number, 2);

        return abs($number) < 1e10 ? number_format($number, 2, '.', '') : null;
    }
}
