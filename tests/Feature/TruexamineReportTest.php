<?php

use App\Ai\Agents\TruexamineReportGenerator;
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
        ],
        'research_contact_method' => 'Research',
        'research_verification_result' => 'Major Discrepancy',
        'research_remarks' => 'Test remarks for automated check.',
        'key_findings' => ['Synthetic finding for tests.'],
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
            ->where('report.applicant_name', 'Jane Doe'),
        );
});
