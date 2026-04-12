<?php

namespace App\Services;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TruexamineReportXlsxExporter
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function toBinary(array $report): string
    {
        $report = $this->sanitizeReportForExport($this->normalizeReport($report));

        $spreadsheet = new Spreadsheet;

        $matrixSheet = $spreadsheet->getActiveSheet();
        $matrixSheet->setTitle('Sheet1');
        $this->writeTrueExamineMatrixSheet($matrixSheet, $report);

        $supportingSheet = $spreadsheet->createSheet();
        $supportingSheet->setTitle('Supporting data');
        $this->writeSupportingDataSheet($supportingSheet, $report);

        $spreadsheet->setActiveSheetIndex(0);

        return $this->spreadsheetToXlsxBinary($spreadsheet);
    }

    /**
     * Original single-sheet workbook: one row per employment spell and per education qualification
     * (same column layout as before the TrueExamine matrix export was added).
     *
     * @param  array<string, mixed>  $report
     */
    public function toBinaryLegacySupportingTables(array $report): string
    {
        $report = $this->sanitizeReportForExport($this->normalizeReport($report));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Truexamine');
        $this->writeSupportingDataSheet($sheet, $report, 'Truexamine Check Report');

        return $this->spreadsheetToXlsxBinary($spreadsheet);
    }

    private function spreadsheetToXlsxBinary(Spreadsheet $spreadsheet): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'txm');
        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temporary file for XLSX export.');
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);
            $binary = (string) file_get_contents($tempPath);
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }

        return $binary;
    }

    /**
     * Same column layout as the reference truexamine.xlsx rubric: Component | Final Remarks | Severity | Description.
     *
     * @param  array<string, mixed>  $report
     */
    private function writeTrueExamineMatrixSheet(Worksheet $sheet, array $report): void
    {
        $row = 1;
        $headers = ['Component', 'Final Remarks', 'Severity', 'Description'];
        foreach (range(0, 3) as $i) {
            $sheet->setCellValue([$i + 1, $row], $headers[$i]);
        }
        $this->applyHeaderStyle($sheet, $row, 1, 4);
        $row++;

        /** @var array<int, array<string, mixed>> $vchecks */
        $vchecks = is_array($report['verification_checks'] ?? null) ? $report['verification_checks'] : [];
        $reportColor = (string) ($report['report_color'] ?? '');

        if ($vchecks === []) {
            $sheet->setCellValue([1, $row], 'TrueExamine Check');
            $sheet->setCellValue([2, $row], '—');
            $sheet->setCellValue([3, $row], $this->severityFromReportColor($reportColor));
            $sheet->setCellValue([4, $row], 'No verification_checks rows were present in the report.');
            $row++;
        } else {
            foreach ($vchecks as $checkRow) {
                $checkRow = is_array($checkRow) ? $checkRow : [];
                $component = trim((string) ($checkRow['type_of_search'] ?? ''));
                if ($component === '') {
                    $component = 'TrueExamine Check';
                }
                $parameter = (string) ($checkRow['check_name'] ?? '');
                $severity = $this->severityFromCheckResult(
                    (string) ($checkRow['check_result'] ?? ''),
                    $reportColor,
                );
                $description = $this->buildVerificationCheckDescription($checkRow);

                $sheet->setCellValue([1, $row], $component);
                $sheet->setCellValue([2, $row], $parameter);
                $sheet->setCellValue([3, $row], $severity);
                $sheet->setCellValue([4, $row], $description);
                $row++;
            }
        }

        $researchResult = trim((string) ($report['research_verification_result'] ?? ''));
        $researchRemarks = trim((string) ($report['research_remarks'] ?? ''));
        if ($researchResult !== '' || $researchRemarks !== '') {
            $sheet->setCellValue([1, $row], 'Research');
            $sheet->setCellValue([2, $row], $researchResult !== '' ? $researchResult : 'Remarks');
            $sheet->setCellValue([3, $row], $this->severityFromReportColor($reportColor));
            $sheet->setCellValue([4, $row], $researchRemarks !== '' ? $researchRemarks : '—');
            $row++;
        }

        /** @var list<string> $findings */
        $findings = [];
        $rawFindings = $report['key_findings'] ?? null;
        if (is_array($rawFindings)) {
            foreach ($rawFindings as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $findings[] = trim($f);
                }
            }
        }

        $findingIndex = 1;
        foreach ($findings as $finding) {
            $sheet->setCellValue([1, $row], 'Key finding');
            $sheet->setCellValue([2, $row], 'Finding '.$findingIndex);
            $sheet->setCellValue([3, $row], $this->severityFromReportColor($reportColor));
            $sheet->setCellValue([4, $row], $finding);
            $findingIndex++;
            $row++;
        }

        $lastDataRow = $row - 1;
        $this->applyMatrixSheetColumnLayout($sheet, $lastDataRow);
    }

    /**
     * Autosize compact columns; fix Description width with wrap so long text stays readable.
     */
    private function applyMatrixSheetColumnLayout(Worksheet $sheet, int $lastDataRow): void
    {
        foreach (['A', 'B', 'C'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->getColumnDimension('D')->setAutoSize(false);
        $sheet->getColumnDimension('D')->setWidth(52);

        if ($lastDataRow < 2) {
            return;
        }

        $sheet->getStyle("A2:C{$lastDataRow}")->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP,
            ],
        ]);

        $sheet->getStyle("D2:D{$lastDataRow}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ]);
    }

    /**
     * Employment annexure and education tables (original single-sheet export content).
     *
     * @param  array<string, mixed>  $report
     */
    private function writeSupportingDataSheet(
        Worksheet $sheet,
        array $report,
        string $bannerTitle = 'Truexamine Check Report -Executive Summary',
    ): void {
        $row = 1;
        $sheet->setCellValue("A{$row}", $bannerTitle);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        /** @var array<int, array<string, mixed>> $annexureRows */
        $annexureRows = is_array($report['annexure_rows'] ?? null) ? $report['annexure_rows'] : [];
        /** @var array<int, array<string, mixed>> $vchecks */
        $vchecks = is_array($report['verification_checks'] ?? null) ? $report['verification_checks'] : [];

        $annexureHeaders = ['Employer Name', 'Employment period', 'UAN-PF', 'BGV Profile', 'CV/Resume', 'Match Status', 'Remarks'];
        $headerRow = $row;
        foreach (range(0, 6) as $i) {
            $sheet->setCellValue([$i + 1, $row], $annexureHeaders[$i]);
        }
        $this->applyHeaderStyle($sheet, $headerRow, 1, 7);
        $row++;

        if ($annexureRows !== []) {
            foreach ($annexureRows as $ar) {
                $ar = is_array($ar) ? $ar : [];
                $sheet->setCellValue([1, $row], (string) ($ar['employer_name'] ?? ''));
                $sheet->setCellValue([2, $row], $this->formatOptionalDateRange(
                    (string) ($ar['employment_start_date'] ?? ''),
                    (string) ($ar['employment_end_date'] ?? ''),
                ));
                $sheet->setCellValue([3, $row], (string) ($ar['pf_match'] ?? ''));
                $sheet->setCellValue([4, $row], (string) ($ar['bgv_match'] ?? ''));
                $sheet->setCellValue([5, $row], (string) ($ar['cv_match'] ?? ''));
                $sheet->setCellValue([6, $row], (string) ($ar['match_status'] ?? ''));
                $sheet->setCellValue([7, $row], (string) ($ar['remarks'] ?? ''));
                $row++;
            }
        } else {
            foreach ($vchecks as $checkRow) {
                $checkRow = is_array($checkRow) ? $checkRow : [];
                $pass = (($checkRow['check_result'] ?? '') === 'Pass') ? 'Match' : 'Mismatch';
                $sheet->setCellValue([1, $row], (string) ($checkRow['check_name'] ?? ''));
                $sheet->setCellValue([2, $row], '-');
                $sheet->setCellValue([3, $row], '-');
                $sheet->setCellValue([4, $row], '-');
                $sheet->setCellValue([5, $row], '-');
                $sheet->setCellValue([6, $row], $pass);
                $sheet->setCellValue([7, $row], (string) ($report['research_remarks'] ?? '-'));
                $row++;
            }
        }

        $row++;
        $sheet->setCellValue("A{$row}", 'Educational Qualifications');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        /** @var array<int, array<string, mixed>> $edu */
        $edu = is_array($report['education_qualifications'] ?? null) ? $report['education_qualifications'] : [];

        $eduHeaders = ['Qualification', 'Institution', 'Year', 'CV/Resume', 'BGV Profile', 'Remarks'];
        $eduHeaderRow = $row;
        foreach (range(0, 5) as $i) {
            $sheet->setCellValue([$i + 1, $row], $eduHeaders[$i]);
        }
        $this->applyHeaderStyle($sheet, $eduHeaderRow, 1, 6);
        $row++;

        if ($edu !== []) {
            foreach ($edu as $er) {
                $er = is_array($er) ? $er : [];
                $sheet->setCellValue([1, $row], (string) ($er['qualification'] ?? ''));
                $sheet->setCellValue([2, $row], (string) ($er['institution'] ?? ''));
                $sheet->setCellValue([3, $row], (string) ($er['year'] ?? ''));
                $sheet->setCellValue([4, $row], (string) ($er['cv_match'] ?? ''));
                $sheet->setCellValue([5, $row], (string) ($er['bgv_match'] ?? ''));
                $sheet->setCellValue([6, $row], (string) ($er['remarks'] ?? ''));
                $row++;
            }
        } else {
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", 'No education qualifications were extracted from the provided documents.');
            $row++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function formatOptionalDateRange(string $start, string $end): string
    {
        $startFormatted = $this->formatSingleDateOrKeywordForExport(trim($start));
        $endFormatted = $this->formatSingleDateOrKeywordForExport(trim($end));

        if ($startFormatted === '' && $endFormatted === '') {
            return '';
        }
        if ($startFormatted === '') {
            return $endFormatted;
        }
        if ($endFormatted === '') {
            return $startFormatted;
        }

        return $startFormatted.' - '.$endFormatted;
    }

    /**
     * Normalizes ISO-style dates to d/m/Y. Preserves "Present" and non-date text when parsing fails.
     */
    private function formatSingleDateOrKeywordForExport(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (strcasecmp($value, 'Present') === 0) {
            return 'Present';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<string, mixed>  $checkRow
     */
    private function buildVerificationCheckDescription(array $checkRow): string
    {
        $parts = [];
        $given = trim((string) ($checkRow['given'] ?? ''));
        $verified = trim((string) ($checkRow['verified'] ?? ''));
        $result = trim((string) ($checkRow['check_result'] ?? ''));

        if ($given !== '') {
            $parts[] = 'Given: '.$given;
        }
        if ($verified !== '') {
            $parts[] = 'Verified: '.$verified;
        }
        if ($result !== '') {
            $parts[] = 'Result: '.$result;
        }

        return $parts !== [] ? implode("\n", $parts) : '—';
    }

    private function severityFromCheckResult(string $checkResult, string $reportColor): string
    {
        $r = strtolower(trim($checkResult));
        if ($r === '') {
            return $this->severityFromReportColor($reportColor);
        }
        if (str_contains($r, 'amber') || str_contains($r, 'yellow')) {
            return 'Amber';
        }
        if (str_contains($r, 'mismatch') || $r === 'fail' || str_contains($r, 'discrepancy')) {
            return 'Red';
        }
        if ($r === 'pass' || str_contains($r, 'match found') || str_contains($r, 'clear')) {
            return 'Green';
        }

        return $this->severityFromReportColor($reportColor);
    }

    private function severityFromReportColor(string $reportColor): string
    {
        $c = strtoupper(trim($reportColor));
        if (str_contains($c, 'GREEN')) {
            return 'Green';
        }
        if (str_contains($c, 'AMBER') || str_contains($c, 'YELLOW')) {
            return 'Amber';
        }

        return 'Red';
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeReportForExport(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $this->sanitizeStringForExport($value);
            } elseif (is_array($value)) {
                $out[$key] = $this->sanitizeReportForExport($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function sanitizeStringForExport(string $value): string
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

    private function applyHeaderStyle(Worksheet $sheet, int $row, int $startCol, int $endCol): void
    {
        $startLetter = Coordinate::stringFromColumnIndex($startCol);
        $endLetter = Coordinate::stringFromColumnIndex($endCol);
        $sheet->getStyle("{$startLetter}{$row}:{$endLetter}{$row}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'ECECEC'],
            ],
            'font' => ['bold' => true],
        ]);
    }
}
