<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[Timeout(300)]
class TruexamineReportGenerator implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are a senior background-verification analyst drafting a "TRUEXAMINE" style screening report in the format used by A.M.S. INFORM PRIVATE LIMITED for corporate clients.

You will receive exactly three PDF attachments in this order:
1) UAN / EPFO employment history export (often titled like a PF download).
2) Candidate curriculum vitae (CV).
3) BGV / onboarding profile form.

Your job:
- Extract employer names, designations, and employment date ranges from each source.
- Cross-check CV and BGV against UAN records. Flag undeclared employments, date mismatches (even by one day), gaps, and transitions (e.g. IBM to Kyndryl).
- Note when historical employment predates UAN (launched ~October 2014 in India) and therefore may not appear in UAN.
- For "Form 26AS" style rows, use "Not Available" if tax data is not in the PDFs.
- Assign report_color: GREEN (clear), YELLOW (minor issues only), or RED (major discrepancy such as undeclared employment or material fraud risk).
- Write key_findings as concise, numbered-style bullet strings (plain text, no markdown).
- Use the client_ref and ams_ref values supplied in the user message when populating those fields.
- Use today's date for order_date and verified_date in ISO format (YYYY-MM-DD) unless the user message specifies different dates.
- Be factual; if something cannot be verified from the documents, say so in remarks rather than inventing data.
INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        $checkRow = $schema->object([
            'check_name' => $schema->string()->required(),
            'type_of_search' => $schema->string()->required(),
            'given' => $schema->string()->required(),
            'verified' => $schema->string()->required(),
            'check_result' => $schema->string()->required(),
        ]);

        return [
            'vendor_name' => $schema->string()->required(),
            'order_date' => $schema->string()->required(),
            'verified_date' => $schema->string()->required(),
            'client_name' => $schema->string()->required(),
            'client_ref' => $schema->string()->required(),
            'ams_ref' => $schema->string()->required(),
            'report_color' => $schema->string()->required(),
            'applicant_name' => $schema->string()->required(),
            'applicant_dob' => $schema->string()->required(),
            'applicant_country_given' => $schema->string()->required(),
            'applicant_country_verified' => $schema->string()->required(),
            'verification_checks' => $schema->array()->items($checkRow)->required(),
            'research_contact_method' => $schema->string()->required(),
            'research_verification_result' => $schema->string()->required(),
            'research_remarks' => $schema->string()->required(),
            'key_findings' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
