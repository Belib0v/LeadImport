<?php

declare(strict_types=1);

namespace App;

/**
 * Reads an .xlsx file and writes its rows into `leads` in one transaction.
 *
 * A lead is identified by external_id + created_at (UNIQUE in schema.sql); a lead that is
 * already in the database is skipped. So uploading the same file again, even re-saved,
 * adds nothing, while rows that only share an external_id (the file has 205) are all kept.
 */
final class Importer
{
    private const COLUMNS = [
        ...ExcelParser::FIELDS,
        ExcelParser::INVALID_FIELD,
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ExcelParser $parser = new ExcelParser(),
        private readonly int $batchSize = 1000,
    ) {
    }

    public function import(string $xlsxPath): array
    {
        $rows = SheetReader::open($xlsxPath)->rows();
        $columns = $this->parser->mapHeaderToFields($rows->current() ?? []); // first row is the header
        $rows->next();

        return $this->db->transaction(function () use ($rows, $columns): array {
            $total = 0;
            $imported = 0;
            $batch = [];
            for (; $rows->valid(); $rows->next()) {
                $batch[] = $this->parser->parseRow($rows->current(), $columns);
                $total++;

                if (count($batch) === $this->batchSize) {
                    $imported += $this->db->insertSkippingDuplicates('leads', self::COLUMNS, $batch);
                    $batch = [];
                }
            }
            $imported += $this->db->insertSkippingDuplicates('leads', self::COLUMNS, $batch);

            return ['imported' => $imported, 'duplicates' => $total - $imported];
        });
    }
}
