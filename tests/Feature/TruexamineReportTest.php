<?php

use App\Ai\Agents\TruexamineReportGenerator;
use App\Ai\Agents\TruexamineUploadedDocumentClassifier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * @throws RuntimeException
 */
function spreadsheetFromXlsxBinary(string $binary): Spreadsheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test-');

    if ($tmp === false) {
        throw new RuntimeException('Unable to create temporary file for XLSX test.');
    }

    file_put_contents($tmp, $binary);

    try {
        return IOFactory::load($tmp);
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
}

/**
 * @throws RuntimeException
 */
function sharedStringsXmlFromXlsxBinary(string $binary): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx-zip-test-');

    if ($tmp === false) {
        throw new RuntimeException('Unable to create temporary file for XLSX zip test.');
    }

    file_put_contents($tmp, $binary);

    $zip = new ZipArchive;

    if ($zip->open($tmp) !== true) {
        unlink($tmp);
        throw new RuntimeException('Invalid XLSX archive in test.');
    }

    $xml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    if (is_file($tmp)) {
        unlink($tmp);
    }

    if ($xml === false) {
        return '';
    }

    return $xml;
}

function concatenateAllStringCellValues(Spreadsheet $spreadsheet): string
{
    $out = '';
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $v = $cell->getValue();
                if (is_string($v) && $v !== '') {
                    $out .= $v."\n";
                }
            }
        }
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function sampleStructuredTruexamineReport(): array
{
    return [
        'vendor_name' => 'A.M.S. INFORM PRIVATE LIMITED',
        'order_date' => '2026-03-16',
        'verified_date' => '2026-03-16',
        'client_name' => 'DXC TECHNOLOGY INDIA PRIVATE LIMITED',
        'client_ref' => 'DXC-4001878-Truexamine',
        'ams_ref' => '4001884',
        'report_color' => 'RED',
        'applicant_name' => 'Jane Doe',
        'applicant_dob' => '1987-07-02',
        'applicant_country_given' => 'India',
        'applicant_country_verified' => 'India',
        'verification_checks' => [
            [
                'check_name' => 'CV MATCH',
                'type_of_search' => 'TRUEXAMINE CHECK',
                'given' => 'CV',
                'verified' => 'CV',
                'check_result' => 'Match Found',
            ],
            [
                'check_name' => 'EDUCATION MATCH (CV vs BGV)',
                'type_of_search' => 'TRUEXAMINE CHECK',
                'given' => 'CV',
                'verified' => 'BGV PROFILE',
                'check_result' => 'Match Found',
            ],
        ],
        'research_contact_method' => 'Research',
        'research_verification_result' => 'Major Discrepancy',
        'research_remarks' => 'Test remarks for automated check.',
        'key_findings' => [
            '1. Synthetic undeclared-employment finding for tests.',
            '2. Synthetic date-alignment finding for tests.',
        ],
        'annexure_rows' => [
            [
                'employer_name' => 'Example Employer Pvt Ltd',
                'employment_start_date' => '2020-01-01',
                'employment_end_date' => '2022-01-31',
                'pf_match' => 'Yes',
                'bgv_match' => 'Yes',
                'cv_match' => 'Yes',
                'match_status' => 'Match',
                'remarks' => 'Synthetic row for tests.',
            ],
        ],
        'education_qualifications' => [
            [
                'qualification' => 'B.Tech',
                'institution' => 'Example University',
                'education_period_start' => '2008-07-01',
                'education_period_end' => '2012-05-30',
                'year' => '2012',
                'cv_match' => 'Yes',
                'bgv_match' => 'Yes',
                'remarks' => 'Synthetic education row for tests.',
            ],
        ],
        'verifier_name' => 'Research',
        'verifier_designation' => 'Research',
        'verifier_email' => 'Not Available',
        'verifier_phone' => 'Not Available',
    ];
}

test('guests cannot view the truexamine report form', function () {
    $this->get(route('truexamine-report.create'))
        ->assertRedirect(route('login'));
});

test('guests cannot submit the truexamine report form', function () {
    $this->post(route('truexamine-report.store'), [])
        ->assertRedirect(route('login'));
});

test('authenticated users can view the truexamine report form', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('truexamine-report.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('truexamine-report/index')
            ->where('report', null)
            ->has('defaults.client_name'),
        );
});

test('store validates required pdf uploads and metadata', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('truexamine-report.store'), [])
        ->assertSessionHasErrors([
            'uan_pf_download',
            'cv',
            'bgv_profile',
            'client_name',
            'client_ref',
            'ams_ref',
        ]);
});

test('store generates a structured report using the ai agent', function () {
    TruexamineReportGenerator::fake([sampleStructuredTruexamineReport()]);
    TruexamineUploadedDocumentClassifier::fake([
        ['document_type' => 'UAN_PF_DOWNLOAD', 'confidence' => 0.99, 'signals' => ['EPFO/UAN']],
        ['document_type' => 'CV', 'confidence' => 0.99, 'signals' => ['experience']],
        ['document_type' => 'BGV_PROFILE', 'confidence' => 0.99, 'signals' => ['onboarding form']],
    ]);

    $user = User::factory()->create();

    $pdf = UploadedFile::fake()->create('document.pdf', 200, 'application/pdf');

    $this->actingAs($user)
        ->post(route('truexamine-report.store'), [
            'uan_pf_download' => $pdf,
            'cv' => $pdf,
            'bgv_profile' => $pdf,
            'client_name' => 'DXC TECHNOLOGY INDIA PRIVATE LIMITED',
            'client_ref' => 'DXC-4001878-Truexamine',
            'ams_ref' => '4001884',
        ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('truexamine-report/index')
            ->where('report.vendor_name', 'A.M.S. INFORM PRIVATE LIMITED')
            ->where('report.applicant_name', 'Jane Doe')
            ->where('report.verification_checks.1.check_name', 'EDUCATION MATCH (CV vs BGV)'),
        );
});

test('store returns the real error message when the ai agent fails', function () {
    TruexamineReportGenerator::fake(function (): void {
        throw new RuntimeException('OpenAI Error [400]: invalid_request_error');
    });
    TruexamineUploadedDocumentClassifier::fake([
        ['document_type' => 'UAN_PF_DOWNLOAD', 'confidence' => 0.99, 'signals' => ['EPFO/UAN']],
        ['document_type' => 'CV', 'confidence' => 0.99, 'signals' => ['experience']],
        ['document_type' => 'BGV_PROFILE', 'confidence' => 0.99, 'signals' => ['onboarding form']],
    ]);

    $user = User::factory()->create();

    $pdf = UploadedFile::fake()->create('document.pdf', 200, 'application/pdf');

    $this->actingAs($user)
        ->post(route('truexamine-report.store'), [
            'uan_pf_download' => $pdf,
            'cv' => $pdf,
            'bgv_profile' => $pdf,
            'client_name' => 'DXC TECHNOLOGY INDIA PRIVATE LIMITED',
            'client_ref' => 'DXC-4001878-Truexamine',
            'ams_ref' => '4001884',
        ])
        ->assertSessionHasErrors([
            'report' => 'OpenAI Error [400]: invalid_request_error',
        ]);
});

test('store rejects swapped document types', function () {
    TruexamineUploadedDocumentClassifier::fake([
        ['document_type' => 'UAN_PF_DOWNLOAD', 'confidence' => 0.99, 'signals' => ['EPFO/UAN']],
        ['document_type' => 'BGV_PROFILE', 'confidence' => 0.95, 'signals' => ['onboarding form']],
        ['document_type' => 'CV', 'confidence' => 0.95, 'signals' => ['experience']],
    ]);

    $user = User::factory()->create();

    $pdf = UploadedFile::fake()->create('document.pdf', 200, 'application/pdf');

    $this->actingAs($user)
        ->post(route('truexamine-report.store'), [
            'uan_pf_download' => $pdf,
            'cv' => $pdf,
            'bgv_profile' => $pdf,
            'client_name' => 'DXC TECHNOLOGY INDIA PRIVATE LIMITED',
            'client_ref' => 'DXC-4001878-Truexamine',
            'ams_ref' => '4001884',
        ])
        ->assertSessionHasErrors([
            'cv',
            'bgv_profile',
        ]);
});

test('download returns 404 when no report is in session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('truexamine-report.download'))
        ->assertNotFound();
});

test('legacy employment-education download returns 404 when no report is in session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('truexamine-report.download-employment-education'))
        ->assertNotFound();
});

test('download returns an xlsx when report is in session', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['truexamine_report' => sampleStructuredTruexamineReport()])
        ->get(route('truexamine-report.download'));

    $response->assertDownload('DXC-4001878-Truexamine.xlsx');
    expect($response->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect(strtolower((string) $response->headers->get('content-disposition')))->toContain('attachment');

    $spreadsheet = spreadsheetFromXlsxBinary($response->streamedContent());
    $sheet1 = $spreadsheet->getSheetByName('Sheet1');
    expect($sheet1)->not->toBeNull();
    expect($sheet1->getCell([1, 1])->getValue())->toBe('Component');
    expect($sheet1->getCell([2, 1])->getValue())->toBe('Final Remarks');
    expect($sheet1->getCell([3, 1])->getValue())->toBe('Severity');
    expect($sheet1->getCell([4, 1])->getValue())->toBe('Description');
    $descriptionSample = (string) $sheet1->getCell([4, 2])->getValue();
    expect($descriptionSample)->toContain('Given:');
    expect($descriptionSample)->toContain("\n");
    $descriptionStyle = $sheet1->getStyle('D2');
    expect($descriptionStyle->getAlignment()->getWrapText())->toBeTrue();
    expect($descriptionStyle->getAlignment()->getVertical())->toBe(Alignment::VERTICAL_TOP);
    $supporting = $spreadsheet->getSheetByName('Supporting data');
    expect($supporting)->not->toBeNull();
    expect($supporting->getCell([1, 1])->getValue())->toBe('Truexamine Check Report -Executive Summary');
    expect($supporting->getCell([5, 2])->getValue())->toBe('CV/Resume');
    expect($supporting->getCell([4, 6])->getValue())->toBe('CV/Resume');
});

test('legacy employment-education download returns a single-sheet xlsx with annexure and education rows', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['truexamine_report' => sampleStructuredTruexamineReport()])
        ->get(route('truexamine-report.download-employment-education'));

    $response->assertDownload('DXC-4001878-Truexamine-employment-education.xlsx');
    expect($response->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $spreadsheet = spreadsheetFromXlsxBinary($response->streamedContent());
    expect($spreadsheet->getSheetCount())->toBe(1);

    $sheet = $spreadsheet->getSheetByName('Truexamine');
    expect($sheet)->not->toBeNull();
    expect($sheet->getCell([1, 1])->getValue())->toBe('Truexamine Check Report');
    expect($sheet->getCell([1, 2])->getValue())->toBe('Employer Name');
    expect($sheet->getCell([2, 2])->getValue())->toBe('Employment period');
    expect($sheet->getCell([3, 2])->getValue())->toBe('UAN-PF');
    expect($sheet->getCell([5, 2])->getValue())->toBe('CV/Resume');
    expect($sheet->getCell([1, 3])->getValue())->toBe('Example Employer Pvt Ltd');
    expect($sheet->getCell([2, 3])->getValue())->toBe('01/01/2020 - 31/01/2022');
    expect(concatenateAllStringCellValues($spreadsheet))->toContain('Educational Qualifications');
    expect($sheet->getCell([1, 6])->getValue())->toBe('Qualification');
    expect($sheet->getCell([4, 6])->getValue())->toBe('CV/Resume');
    expect($sheet->getCell([3, 6])->getValue())->toBe('Year');
    expect((string) $sheet->getCell([3, 7])->getValue())->toBe('2012');
});

test('supporting data export leaves Present as the employment end label', function () {
    $user = User::factory()->create();
    $report = sampleStructuredTruexamineReport();
    $report['annexure_rows'][0]['employment_end_date'] = 'Present';

    $response = $this->actingAs($user)
        ->withSession(['truexamine_report' => $report])
        ->get(route('truexamine-report.download-employment-education'));

    $response->assertDownload('DXC-4001878-Truexamine-employment-education.xlsx');
    $sheet = spreadsheetFromXlsxBinary($response->streamedContent())->getSheetByName('Truexamine');
    expect($sheet)->not->toBeNull();
    expect($sheet->getCell([2, 3])->getValue())->toBe('01/01/2020 - Present');
});

test('download produces well formed shared strings xml when report contains ampersands and angle brackets', function () {
    $user = User::factory()->create();
    $report = sampleStructuredTruexamineReport();
    $report['annexure_rows'][0]['remarks'] = 'Foo & Bar <Holdings> "Test"';
    $report['education_qualifications'][0]['remarks'] = 'Remarks with <placeholder> & special chars';

    $response = $this->actingAs($user)
        ->withSession(['truexamine_report' => $report])
        ->get(route('truexamine-report.download'));

    $response->assertDownload();
    $xml = sharedStringsXmlFromXlsxBinary($response->streamedContent());

    $dom = new DOMDocument;
    $loaded = @$dom->loadXML($xml);

    expect($loaded)->toBeTrue();

    $spreadsheet = spreadsheetFromXlsxBinary($response->streamedContent());
    $cells = concatenateAllStringCellValues($spreadsheet);
    expect($cells)->toContain('Foo & Bar <Holdings> "Test"');
    expect($cells)->toContain('Remarks with <placeholder> & special chars');
});

test('download decodes html entities before rendering xlsx', function () {
    $user = User::factory()->create();
    $report = sampleStructuredTruexamineReport();
    $report['annexure_rows'][0]['remarks'] = 'R&D verification completed';
    $report['education_qualifications'][0]['remarks'] = '1. R&amp;D experience was verified from provided records.';

    $response = $this->actingAs($user)
        ->withSession(['truexamine_report' => $report])
        ->get(route('truexamine-report.download'));

    $response->assertDownload('DXC-4001878-Truexamine.xlsx');
    $spreadsheet = spreadsheetFromXlsxBinary($response->streamedContent());
    $cells = concatenateAllStringCellValues($spreadsheet);

    expect($cells)->not->toContain('R&amp;D');
    expect($cells)->toContain('R&D');
});

test('test-xlsx route returns reference-style xlsx download', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('truexamine-report.test-xlsx'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect(strtolower((string) $response->headers->get('content-disposition')))
        ->toContain('inline')
        ->toContain('dxc-4001878-truexamine.xlsx');
});
