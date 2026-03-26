<?php

use App\Ai\Agents\TruexamineReportGenerator;
use App\Ai\Agents\TruexamineUploadedDocumentClassifier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

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

test('download returns a pdf when report is in session', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['truexamine_report' => sampleStructuredTruexamineReport()])
        ->get(route('truexamine-report.download'));

    $response->assertDownload('DXC-4001878-Truexamine.pdf');
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect(strtolower((string) $response->headers->get('content-disposition')))->toContain('attachment');
});

test('download decodes html entities before rendering pdf', function () {
    $user = User::factory()->create();
    $report = sampleStructuredTruexamineReport();
    $report['research_remarks'] = 'R&D verification completed';
    $report['key_findings'] = ['1. R&amp;D experience was verified from provided records.'];

    $response = $this->actingAs($user)
        ->withSession(['truexamine_report' => $report])
        ->get(route('truexamine-report.download'));

    $response->assertDownload('DXC-4001878-Truexamine.pdf');
    $content = $response->streamedContent();

    expect($content)->toContain('R&D');
    expect($content)->not->toContain('R&amp;amp;D');
});

test('test-pdf route returns reference-style pdf download', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('truexamine-report.test-pdf'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect(strtolower((string) $response->headers->get('content-disposition')))
        ->toContain('inline')
        ->toContain('dxc-4001878-truexamine.pdf');
});
