<?php

namespace App\Http\Controllers;

use App\Ai\Agents\TruexamineReportGenerator;
use App\Http\Requests\GenerateTruexamineReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class TruexamineReportController extends Controller
{
    /**
     * Show the Truexamine report generator form.
     */
    public function create(): Response
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
    public function store(GenerateTruexamineReportRequest $request): Response|RedirectResponse
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

            return back()
                ->withErrors(['report' => 'Report generation failed. Check your OpenAI API key, model access, and try again.'])
                ->withInput();
        }

        return Inertia::render('truexamine-report/index', [
            'report' => $response->structured,
            'defaults' => [
                'client_name' => $data['client_name'],
                'client_ref' => $data['client_ref'],
                'ams_ref' => $data['ams_ref'],
            ],
        ]);
    }
}
