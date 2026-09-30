<?php

declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMElement;
use DOMNode;
use Generator;
use InvalidArgumentException;
use XMLReader;
use ZipArchive;

/**
 * Reads rows of the first sheet of an .xlsx file one by one.
 *
 * An .xlsx is a zip of XML files:
 *   xl/workbook.xml            list of sheets
 *   xl/_rels/workbook.xml.rels where each sheet's XML file is
 *   xl/worksheets/sheet1.xml   the rows: <row><c r="A2" t="s"><v>15</v></c>...</row>
 *   xl/sharedStrings.xml       texts of the cells, cells refer to them by index: <si><t>text</t></si>
 *
 * The sheet is streamed with XMLReader, so memory does not grow with the number of rows.
 */
final class SheetReader
{
    private function __construct(
        private readonly string $xlsxPath,
        private readonly string $sheetPart,
        private readonly ?string $sharedStringsPart,
    ) {
    }

    public static function open(string $xlsxPath): self
    {
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            throw new InvalidArgumentException('Not an .xlsx file');
        }

        try {
            [$sheetPart, $sharedStringsPart] = self::findPartPaths($zip);
            if ($sheetPart === null || $zip->locateName($sheetPart) === false) {
                throw new InvalidArgumentException('The file has no worksheet');
            }
            if ($sharedStringsPart !== null && $zip->locateName($sharedStringsPart) === false) {
                $sharedStringsPart = null;
            }
        } finally {
            $zip->close();
        }

        return new self($xlsxPath, $sheetPart, $sharedStringsPart);
    }

    /**
     * Each non-empty row as column letter => value, e.g. ['A' => 'LD-000001', 'E' => '380672341057'].
     *
     * @return Generator<array<string, string>>
     */
    public function rows(): Generator
    {
        $sharedStrings = $this->readSharedStrings();

        foreach ($this->eachElement($this->sheetPart, 'row') as $row) {
            $cells = [];
            $previousColumn = null;
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                    continue;
                }
                // "E12" -> "E"; a cell without a reference is the one right after the previous (A, B, ... Z, AA)
                $column = preg_replace('~\d+~', '', $cell->getAttribute('r'))
                    ?: ($previousColumn === null ? 'A' : ++$previousColumn);
                $previousColumn = $column;

                $value = trim((string)$this->cellValue($cell, $sharedStrings));
                if ($value !== '') {
                    $cells[$column] = $value;
                }
            }

            if ($cells) {
                yield $cells;
            }
        }
    }

    /** @param list<string> $sharedStrings */
    private function cellValue(DOMElement $cell, array $sharedStrings): ?string
    {
        $rawValue = self::firstChild($cell, 'v')?->textContent;

        $value = match ($cell->getAttribute('t')) {
            's' => $rawValue === null ? null : $sharedStrings[(int)$rawValue] ?? null, // index in sharedStrings.xml
            'inlineStr' => self::textOf(self::firstChild($cell, 'is')),                 // text stored in the cell itself
            default => $rawValue,                                                       // number, boolean, formula result
        };

        // A formula without a usable cached result (an empty <v/>, or "#ERROR!" for "=+38050abc1234"):
        // keep what the user typed instead.
        $formula = self::firstChild($cell, 'f')?->textContent;
        if ($formula && ($value === null || $value === '' || str_starts_with($value, '#'))) {
            return $formula;
        }

        return $value;
    }

    /** @return list<string> texts in file order; a cell of type "s" holds an index in this list */
    private function readSharedStrings(): array
    {
        if ($this->sharedStringsPart === null) {
            return [];
        }

        $strings = [];
        foreach ($this->eachElement($this->sharedStringsPart, 'si') as $item) {
            $strings[] = self::textOf($item); // an empty <si/> still takes its index
        }

        return $strings;
    }

    /**
     * Streams every <$name> element of a file inside the archive, one at a time.
     *
     * @return Generator<DOMElement>
     */
    private function eachElement(string $part, string $name): Generator
    {
        $reader = new XMLReader();
        if (!@$reader->open("zip://{$this->xlsxPath}#{$part}", null, LIBXML_NONET)) {
            throw new InvalidArgumentException('Could not read the file');
        }

        try {
            $hasNode = $reader->read();
            while ($hasNode) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $name) {
                    yield $reader->expand();
                    $hasNode = $reader->next(); // jump over the element's children
                } else {
                    $hasNode = $reader->read();
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Where the first sheet and the shared strings are, per workbook.xml and its relationships file.
     *
     * @return array{?string, ?string} [sheet path, shared strings path]
     */
    private static function findPartPaths(ZipArchive $zip): array
    {
        $workbook = self::loadXml($zip->getFromName('xl/workbook.xml'));
        $relationships = self::loadXml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        if ($workbook === null || $relationships === null) {
            return ['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml'];
        }

        $pathById = [];
        $sharedStringsPath = null;
        foreach ($relationships->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
            $target = $relationship->getAttribute('Target');
            $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            $pathById[$relationship->getAttribute('Id')] = $path;

            if (str_ends_with($relationship->getAttribute('Type'), '/sharedStrings')) {
                $sharedStringsPath = $path;
            }
        }

        // <sheet name="Leads" sheetId="1" r:id="rId1"/>: take "id" whatever its prefix is ("r:", "x:", ...)
        $firstSheet = $workbook->getElementsByTagNameNS('*', 'sheet')->item(0);
        $firstSheetId = null;
        foreach ($firstSheet?->attributes ?? [] as $attribute) {
            if ($attribute->localName === 'id') {
                $firstSheetId = $attribute->value;
            }
        }

        return [$pathById[$firstSheetId] ?? null, $sharedStringsPath];
    }

    /** Text of <si> or <is>: plain <t>, or rich-text runs <r><t>; phonetic hints <rPh> are skipped. */
    private static function textOf(?DOMNode $item): ?string
    {
        if ($item === null) {
            return null;
        }

        $text = '';
        foreach ($item->childNodes as $node) {
            $text .= match ($node->localName) {
                't' => $node->textContent,
                'r' => self::firstChild($node, 't')?->textContent ?? '',
                default => '',
            };
        }

        return $text;
    }

    private static function firstChild(DOMNode $node, string $localName): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }

    private static function loadXml(string|false $xml): ?DOMDocument
    {
        $document = new DOMDocument();

        return $xml !== false && @$document->loadXML($xml, LIBXML_NONET) ? $document : null;
    }
}
