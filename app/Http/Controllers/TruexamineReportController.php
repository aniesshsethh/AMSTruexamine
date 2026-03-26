<?php

namespace App\Http\Controllers;

use App\Ai\Agents\TruexamineReportGenerator;
use App\Ai\Agents\TruexamineUploadedDocumentClassifier;
use App\Http\Requests\GenerateTruexamineReportRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class TruexamineReportController extends Controller
{
    /**
     * Show the Truexamine report generator form.
     */
    public function create(): InertiaResponse
    {
        return Inertia::render('truexamine-report/index', [
            'report' => null,
            'defaults' => [
                'client_name' => 'DXC TECHNOLOGY INDIA PRIVATE LIMITED',
                'client_ref' => 'DXC-4001878-Truexamine',
                'ams_ref' => '4001884',
            ],
        ]);
    }

    /**
     * Generate a structured Truexamine-style report from the three PDFs.
     */
    public function store(GenerateTruexamineReportRequest $request): InertiaResponse|RedirectResponse
    {
        $data = $request->validated();

        $documentTypeErrors = [];

        $expected = [
            'uan_pf_download' => 'UAN_PF_DOWNLOAD',
            'cv' => 'CV',
            'bgv_profile' => 'BGV_PROFILE',
        ];

        foreach ($expected as $field => $expectedType) {
            /** @var StructuredAgentResponse $classification */
            $classification = TruexamineUploadedDocumentClassifier::make()->prompt(
                "Classify this PDF's document type for the Truexamine workflow. Expected: {$expectedType}. Return UNKNOWN if unsure.",
                [$request->file($field)],
                Lab::OpenAI,
                config('truexamine.openai_model'),
                60,
            );

            $actualType = (string) ($classification->structured['document_type'] ?? 'UNKNOWN');

            if ($actualType !== $expectedType) {
                $documentTypeErrors[$field] = match ($field) {
                    'uan_pf_download' => 'The uploaded PDF does not look like a UAN / PF download (EPFO) document.',
                    'cv' => 'The uploaded PDF does not look like a CV / resume document.',
                    'bgv_profile' => 'The uploaded PDF does not look like a BGV / onboarding profile document.',
                    default => 'The uploaded PDF does not match the expected document type.',
                };
            }
        }

        if ($documentTypeErrors !== []) {
            return back()
                ->withErrors($documentTypeErrors)
                ->withInput();
        }

        $orderDate = $data['order_date'] ?? now()->toDateString();
        $verifiedDate = $data['verified_date'] ?? $orderDate;

        $prompt = <<<PROMPT
Generate the full structured TRUEXAMINE report JSON per your schema.

Context for header fields:
- client_name: {$data['client_name']}
- client_ref: {$data['client_ref']}
- ams_ref: {$data['ams_ref']}
- order_date: {$orderDate}
- verified_date: {$verifiedDate}

Attachment order:
1) UAN / PF download PDF (filename: {$request->file('uan_pf_download')->getClientOriginalName()})
2) CV PDF (filename: {$request->file('cv')->getClientOriginalName()})
3) BGV profile PDF (filename: {$request->file('bgv_profile')->getClientOriginalName()})

Include rows in verification_checks for at least: TRUEXAMINE CHECK (summary), CV MATCH, EDUCATION MATCH (CV vs BGV), BGV PROFILE - ONBOARDING FORM, UAN - EPFO, FORM 26AS, and any other checks implied by the documents.
For every verification_checks row, set check_result to exactly one of: "Pass" or "Fail" (no other wording).

When comparing employment end dates: if UAN, CV, and BGV all show the same calendar day (e.g. 10-Oct-2016 in each), do not report a one-day or minor date mismatch for that employment.

Critical undeclared-employment rule:
- Compare each UAN employment spell (employer + join date + exit date) against CV and BGV declared history.
- If UAN shows an employer that is absent from CV and BGV, you MUST treat it as undeclared employment and report it clearly in verification_checks / remarks / key_findings.
- Example pattern to catch when present in UAN: TATA CONSULTANCY SERVICES LIMITED with tenure 11-Dec-2009 to 05-Nov-2014. If this spell is missing from CV/BGV, explicitly call it out as undeclared employment.

Bidirectional reconciliation rule (strict):
- Reconcile employment records across ALL three sources (UAN, CV, BGV) in both directions.
- If an employer/date spell appears in CV or BGV but is missing in UAN, report it as a discrepancy unless records clearly indicate a valid reason (e.g., employment fully before UAN go-live period).
- If an employer/date spell appears in CV but not BGV, or in BGV but not CV, report that mismatch as well.
- In short: anything present in one source but absent in another source must be detected and explicitly reported.

Include verifier_name, verifier_designation, verifier_email, and verifier_phone (use "Research" / "Not Available" when appropriate per your instructions). Number key_findings as "1. ...", "2. ...", covering undeclared UAN employment vs CV/BGV, real date mismatches, UAN employer transitions, and UAN gaps explained (e.g. pre-Oct 2014 tenure).
Include annexure_rows with one row per company/employment spell and fields: employer_name, employment_start_date, employment_end_date, pf_match, bgv_match, cv_match, match_status, remarks.
Include education_qualifications with one row per qualification and fields: qualification, institution, year, cv_match, bgv_match, remarks.
Keep each annexure_rows.remarks value very short (maximum 12 words).
Keep each education_qualifications.remarks value very short (maximum 10 words).
PROMPT;

        try {
            /** @var StructuredAgentResponse $response */
            $response = TruexamineReportGenerator::make()->prompt(
                $prompt,
                [
                    $request->file('uan_pf_download'),
                    $request->file('cv'),
                    $request->file('bgv_profile'),
                ],
                Lab::OpenAI,
                config('truexamine.openai_model'),
                300,
            );
        } catch (Throwable $e) {
            Log::error('Truexamine report generation failed', [
                'exception' => $e,
            ]);

            $message = $e->getMessage();

            return back()
                ->withErrors([
                    'report' => $message !== '' ? $message : 'Report generation failed: '.class_basename($e),
                ])
                ->withInput();
        }

        $structured = $this->decodeHtmlEntities($response->structured);

        if (isset($structured['verification_checks']) && is_array($structured['verification_checks'])) {
            $structured['verification_checks'] = array_map(
                function ($row): array {
                    $normalizedRow = is_array($row) ? $row : [];
                    $normalizedRow['type_of_search'] = 'TRUEXAMINE CHECK';

                    return $normalizedRow;
                },
                $structured['verification_checks'],
            );
        }

        $verificationChecks = is_array($structured['verification_checks'] ?? null)
            ? $structured['verification_checks']
            : [];

        $hasAnyFailure = collect($verificationChecks)->contains(function ($row): bool {
            $result = strtolower(trim((string) (is_array($row) ? ($row['check_result'] ?? '') : '')));

            return $result !== 'pass';
        });

        $structured['report_color'] = $hasAnyFailure ? 'RED' : 'GREEN';
        $structured['research_verification_result'] = $hasAnyFailure ? 'Major Discrepancy' : 'Clear Report';

        $request->session()->put('truexamine_report', $structured);

        return Inertia::render('truexamine-report/index', [
            'report' => $structured,
            'defaults' => [
                'client_name' => $data['client_name'],
                'client_ref' => $data['client_ref'],
                'ams_ref' => $data['ams_ref'],
            ],
        ]);
    }

    /**
     * Download the last generated report as a PDF (from session).
     */
    public function download(Request $request): Response
    {
        /** @var array<string, mixed>|null $report */
        $report = $request->session()->get('truexamine_report');

        if (! is_array($report)) {
            abort(404);
        }

        $report = $this->decodeHtmlEntities($report);

        $clientReference = trim((string) ($report['client_ref'] ?? ''));
        $filename = $clientReference !== ''
            ? $clientReference.'.pdf'
            : Str::slug((string) ($report['ams_ref'] ?? 'truexamine'), '-').'-Truexamine.pdf';

        return Pdf::loadView('pdf.truexamine-report', ['report' => $report])
            ->download($filename);
    }

    /**
     * Download a sample PDF to validate template fidelity quickly.
     */
    public function testPdf(): Response
    {
        $report = [
            'vendor_name' => 'A.M.S. INFORM PRIVATE LIMITED',
            'order_date' => '2026-03-16',
            'verified_date' => '2026-03-16',
            'client_name' => 'DXC TECHNOLOGY INDIA PRIVATE LIMITED',
            'client_ref' => 'DXC-4001878-Truexamine',
            'ams_ref' => '4001884',
            'report_color' => 'RED',
            'applicant_name' => 'Himanshu Tripathi',
            'applicant_dob' => '1987-07-02',
            'applicant_country_given' => 'India',
            'applicant_country_verified' => 'India',
            'verification_checks' => [
                [
                    'check_name' => 'CV MATCH',
                    'type_of_search' => 'TRUEXAMINE CHECK',
                    'given' => 'TRUEXAMINE CHECK',
                    'verified' => 'TRUEXAMINE CHECK',
                    'check_result' => 'Pass',
                ],
                [
                    'check_name' => 'BGV PROFILE - ONBOARDING FORM',
                    'type_of_search' => 'TRUEXAMINE CHECK',
                    'given' => 'TRUEXAMINE CHECK',
                    'verified' => 'TRUEXAMINE CHECK',
                    'check_result' => 'Pass',
                ],
                [
                    'check_name' => 'EDUCATION MATCH (CV vs BGV)',
                    'type_of_search' => 'TRUEXAMINE CHECK',
                    'given' => 'TRUEXAMINE CHECK',
                    'verified' => 'TRUEXAMINE CHECK',
                    'check_result' => 'Pass',
                ],
                [
                    'check_name' => 'UAN - EPFO',
                    'type_of_search' => 'TRUEXAMINE CHECK',
                    'given' => 'TRUEXAMINE CHECK',
                    'verified' => 'TRUEXAMINE CHECK',
                    'check_result' => 'Fail',
                ],
                [
                    'check_name' => 'FORM 26AS',
                    'type_of_search' => 'TRUEXAMINE CHECK',
                    'given' => 'TRUEXAMINE CHECK',
                    'verified' => 'TRUEXAMINE CHECK',
                    'check_result' => 'Fail',
                ],
            ],
            'research_contact_method' => 'Research',
            'research_verification_result' => 'Major Discrepancy',
            'research_remarks' => '',
            'key_findings' => [
                'Undeclared employment identified: Tata Consultancy Services (11 December 2009 - 04 November 2014) appears in UAN but not declared in CV/BGV.',
                'Minor date mismatch: Dell International Services joining date differs by 1 day in CV vs BGV/UAN.',
                'Employer transition verified: IBM India Pvt Ltd transitioned to Kyndryl Solutions Pvt Ltd in UAN records.',
                'HCL employment not appearing in UAN: UAN System was launched in October 2014 and employment tenure pertains to before 2014.',
            ],
            'annexure_rows' => [
                [
                    'employer_name' => 'Kyndryl Solutions Pvt Ltd / IBM India Pvt Ltd',
                    'employment_start_date' => '2016-12-06',
                    'employment_end_date' => 'Present',
                    'pf_match' => 'Yes',
                    'bgv_match' => 'Yes',
                    'cv_match' => 'Yes',
                    'match_status' => 'Match',
                    'remarks' => 'Employer transitioned from IBM to Kyndryl in 2021 and is consistent across records.',
                ],
                [
                    'employer_name' => 'Tata Consultancy Services Ltd',
                    'employment_start_date' => '2014-11-05',
                    'employment_end_date' => '2016-10-10',
                    'pf_match' => 'Yes',
                    'bgv_match' => 'Yes',
                    'cv_match' => 'Yes',
                    'match_status' => 'Match',
                    'remarks' => 'Declared employment matches in PF, CV, and BGV.',
                ],
                [
                    'employer_name' => 'Tata Consultancy Services Ltd',
                    'employment_start_date' => '2009-12-11',
                    'employment_end_date' => '2014-11-04',
                    'pf_match' => 'Yes',
                    'bgv_match' => 'No',
                    'cv_match' => 'No',
                    'match_status' => 'Undeclared Employment',
                    'remarks' => 'Employment spell appears in PF but is missing in CV and BGV.',
                ],
                [
                    'employer_name' => 'Dell International Services India Pvt Ltd',
                    'employment_start_date' => '2013-02-25',
                    'employment_end_date' => '2014-10-20',
                    'pf_match' => 'Yes',
                    'bgv_match' => 'Yes',
                    'cv_match' => 'Partial',
                    'match_status' => 'Partial Match',
                    'remarks' => 'Company is present in all sources; CV joining date differs by 1 day.',
                ],
                [
                    'employer_name' => 'HCL Comnet Systems & Services Ltd',
                    'employment_start_date' => '2010-04-26',
                    'employment_end_date' => '2013-02-25',
                    'pf_match' => 'No',
                    'bgv_match' => 'Yes',
                    'cv_match' => 'Yes',
                    'match_status' => 'Mismatch',
                    'remarks' => 'Not available in PF/UAN; likely pre-UAN period or PF transfer/non-linking.',
                ],
            ],
            'education_qualifications' => [
                [
                    'qualification' => 'Bachelor of Technology (Computer Science)',
                    'institution' => 'Example Institute of Technology',
                    'year' => '2009',
                    'cv_match' => 'Yes',
                    'bgv_match' => 'Yes',
                    'remarks' => 'Qualification details align between CV and BGV.',
                ],
                [
                    'qualification' => 'Higher Secondary (XII)',
                    'institution' => 'Example Senior Secondary School',
                    'year' => '2005',
                    'cv_match' => 'Yes',
                    'bgv_match' => 'Yes',
                    'remarks' => 'Reported consistently in available documents.',
                ],
            ],
            'verifier_name' => 'Research',
            'verifier_designation' => 'Research',
            'verifier_email' => 'Not Available',
            'verifier_phone' => 'Not Available',
        ];

        $pdf = Pdf::loadView('pdf.truexamine-report', ['report' => $report]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="DXC-4001878-Truexamine.pdf"',
        ]);
    }

    /**
     * Decode accidental HTML entities returned by LLM while preserving escaping at render time.
     */
    private function decodeHtmlEntities(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->decodeHtmlEntities($item), $value);
        }

        if (is_string($value)) {
            $decoded = $value;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($next === $decoded) {
                    break;
                }

                $decoded = $next;
            }

            return $decoded;
        }

        return $value;
    }
}
