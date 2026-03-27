<?php

namespace App\Services;

use Carbon\Carbon;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

class TruexamineReportDocxExporter
{
    /**
     * @var array<string, int|string>
     */
    private const TABLE_OUTER = ['borderSize' => 6, 'borderColor' => '333333', 'cellMargin' => 80];

    /**
     * @param  array<string, mixed>  $report
     */
    public function toBinary(array $report): string
    {
        $report = $this->sanitizeReportForOoxml($this->normalizeReport($report));

        $previousEscaping = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);

        try {
            $phpWord = new PhpWord;
            $phpWord->setDefaultFontName('Arial');
            $phpWord->setDefaultFontSize(9);

            $section = $phpWord->addSection([
                'marginTop' => 800,
                'marginBottom' => 800,
                'marginLeft' => 900,
                'marginRight' => 900,
            ]);

            $this->addAnnexureAndEducationSection($section, $report);

            $tempPath = tempnam(sys_get_temp_dir(), 'txm');
            if ($tempPath === false) {
                throw new \RuntimeException('Unable to create temporary file for DOCX export.');
            }

            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempPath);

            $binary = (string) file_get_contents($tempPath);
            unlink($tempPath);

            return $binary;
        } finally {
            Settings::setOutputEscapingEnabled($previousEscaping);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function normalizeReport(array $report): array
    {
        $report['annexure_rows'] = $this->sortedAnnexureRows($report['annexure_rows'] ?? null);

        return $report;
    }

    /**
     * Strip characters that are invalid in OOXML text (Word may report the file as "corrupted").
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeReportForOoxml(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $this->sanitizeStringForOoxml($value);
            } elseif (is_array($value)) {
                $out[$key] = $this->sanitizeReportForOoxml($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function sanitizeStringForOoxml(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = $converted !== false ? $converted : '';
        }

        return $value;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sortedAnnexureRows(mixed $annexureRows): array
    {
        $rows = is_array($annexureRows) ? $annexureRows : [];

        $normalizeDateToTimestamp = static function (string $value, bool $isEndDate): int {
            $normalized = strtolower(trim($value));

            if ($normalized === '' || $normalized === 'present') {
                return $isEndDate ? PHP_INT_MAX : 0;
            }

            try {
                return Carbon::parse($value)->timestamp;
            } catch (\Throwable) {
                return 0;
            }
        };

        return collect($rows)
            ->sort(function ($a, $b) use ($normalizeDateToTimestamp): int {
                $a = is_array($a) ? $a : [];
                $b = is_array($b) ? $b : [];
                $aEnd = $normalizeDateToTimestamp((string) ($a['employment_end_date'] ?? ''), true);
                $bEnd = $normalizeDateToTimestamp((string) ($b['employment_end_date'] ?? ''), true);

                if ($aEnd !== $bEnd) {
                    return $bEnd <=> $aEnd;
                }

                $aStart = $normalizeDateToTimestamp((string) ($a['employment_start_date'] ?? ''), false);
                $bStart = $normalizeDateToTimestamp((string) ($b['employment_start_date'] ?? ''), false);

                return $bStart <=> $aStart;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function addAnnexureAndEducationSection(Section $section, array $report): void
    {
        $section->addText(
            'Truexamine Check Report',
            ['bold' => true],
            ['spaceBefore' => 0, 'spaceAfter' => 100],
        );

        /** @var array<int, array<string, mixed>> $annexureRows */
        $annexureRows = is_array($report['annexure_rows'] ?? null) ? $report['annexure_rows'] : [];
        /** @var array<int, array<string, mixed>> $vchecks */
        $vchecks = is_array($report['verification_checks'] ?? null) ? $report['verification_checks'] : [];

        $aTable = $section->addTable(self::TABLE_OUTER);
        $aTable->addRow(360, ['tblHeader' => true]);
        $headers = ['Employer Name', 'PF', 'BGV', 'CV', 'Match Status', 'Remarks'];
        $widths = [2600, 900, 900, 900, 1800, 1900];
        foreach ($headers as $i => $label) {
            $aTable->addCell($widths[$i], ['bgColor' => 'ECECEC'])->addText($label, ['bold' => true]);
        }

        if ($annexureRows !== []) {
            foreach ($annexureRows as $ar) {
                $ar = is_array($ar) ? $ar : [];
                $aTable->addRow();
                $aTable->addCell(2600)->addText((string) ($ar['employer_name'] ?? ''));
                $aTable->addCell(900)->addText((string) ($ar['pf_match'] ?? ''));
                $aTable->addCell(900)->addText((string) ($ar['bgv_match'] ?? ''));
                $aTable->addCell(900)->addText((string) ($ar['cv_match'] ?? ''));
                $aTable->addCell(1800)->addText((string) ($ar['match_status'] ?? ''));
                $aTable->addCell(1900)->addText((string) ($ar['remarks'] ?? ''));
            }
        } else {
            foreach ($vchecks as $row) {
                $row = is_array($row) ? $row : [];
                $pass = (($row['check_result'] ?? '') === 'Pass') ? 'Match' : 'Mismatch';
                $aTable->addRow();
                $aTable->addCell(2600)->addText((string) ($row['check_name'] ?? ''));
                $aTable->addCell(900)->addText('-');
                $aTable->addCell(900)->addText('-');
                $aTable->addCell(900)->addText('-');
                $aTable->addCell(1800)->addText($pass);
                $aTable->addCell(1900)->addText((string) ($report['research_remarks'] ?? '-'));
            }
        }

        $section->addText(
            'Educational Qualifications',
            ['bold' => true],
            ['spaceBefore' => 200, 'spaceAfter' => 100],
        );

        /** @var array<int, array<string, mixed>> $edu */
        $edu = is_array($report['education_qualifications'] ?? null) ? $report['education_qualifications'] : [];

        $eTable = $section->addTable(self::TABLE_OUTER);
        $eTable->addRow(360, ['tblHeader' => true]);
        $eh = ['Qualification', 'Institution', 'Year', 'CV', 'BGV', 'Remarks'];
        $ew = [2500, 2300, 900, 900, 900, 1500];
        foreach ($eh as $i => $label) {
            $eTable->addCell($ew[$i], ['bgColor' => 'ECECEC'])->addText($label, ['bold' => true]);
        }

        if ($edu !== []) {
            foreach ($edu as $er) {
                $er = is_array($er) ? $er : [];
                $eTable->addRow();
                $eTable->addCell(2500)->addText((string) ($er['qualification'] ?? ''));
                $eTable->addCell(2300)->addText((string) ($er['institution'] ?? ''));
                $eTable->addCell(900)->addText((string) ($er['year'] ?? ''));
                $eTable->addCell(900)->addText((string) ($er['cv_match'] ?? ''));
                $eTable->addCell(900)->addText((string) ($er['bgv_match'] ?? ''));
                $eTable->addCell(1500)->addText((string) ($er['remarks'] ?? ''));
            }
        } else {
            $eTable->addRow();
            $eTable->addCell(9000, ['gridSpan' => 6])->addText(
                'No education qualifications were extracted from the provided documents.',
            );
        }
    }
}
