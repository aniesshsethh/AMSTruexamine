<?php

namespace App\Http\Controllers;

use App\Ai\Agents\TruexamineReportGenerator;
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

Include rows in verification_checks for at least: TRUEXAMINE CHECK (summary), CV MATCH, BGV PROFILE - ONBOARDING FORM, UAN - EPFO, FORM 26AS, and any other checks implied by the documents.

When comparing employment end dates: if UAN, CV, and BGV all show the same calendar day (e.g. 10-Oct-2016 in each), do not report a one-day or minor date mismatch for that employment.

Include verifier_name, verifier_designation, verifier_email, and verifier_phone (use "Research" / "Not Available" when appropriate per your instructions). Number key_findings as "1. ...", "2. ...", covering undeclared UAN employment vs CV/BGV, real date mismatches, UAN employer transitions, and UAN gaps explained (e.g. pre-Oct 2014 tenure).
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

        $structured = $response->structured;

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

        $slug = Str::slug((string) ($report['ams_ref'] ?? 'truexamine'), '-');
        $filename = $slug.'-Truexamine.pdf';

        return Pdf::loadView('pdf.truexamine-report', ['report' => $report])
            ->download($filename);
    }
}
