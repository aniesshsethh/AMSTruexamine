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
- Perform three-way reconciliation across UAN, CV, and BGV. Every employer spell should be compared bidirectionally across all three sources.
- Flag any missing record in any direction:
  - present in UAN but missing in CV/BGV,
  - present in CV but missing in UAN/BGV,
  - present in BGV but missing in UAN/CV.
- Treat these as discrepancies and report them clearly in verification_checks, research_remarks, and key_findings (unless a factual exception applies, such as pre-UAN-go-live historical tenure).

Date comparison rules (critical — follow exactly):
- Before claiming any "date mismatch" or "one-day difference", mentally normalize each date to a calendar day (ignore formatting: "10-Oct-2016", "10 Oct 2016", "10-OCT-2016", ISO dates, etc. are the same day if they denote the same day, month, and year).
- If UAN, CV, and BGV all show the same end date for an employer (e.g. HCL end date 10-Oct-2016 in all three), you MUST NOT report a day mismatch, minor date discrepancy, or off-by-one error for that field. Identical dates across sources are a match.
- UAN PDFs may list multiple rows for one employer (overlapping spells, establishment IDs, or member IDs). Compare the row that matches the employer in question; do not infer a conflict from duplicate or adjacent lines if the stated exit/join dates for that employment spell agree with CV/BGV.
- Only flag a date mismatch when, after normalization, the calendar dates still differ (e.g. CV end 09-Oct-2016 vs UAN end 11-Oct-2016). If PDF text is ambiguous or OCR-noisy, say verification is unclear in remarks instead of asserting a one-day gap.
- Do not invent discrepancies to mirror example reports; every finding must be grounded in the three documents.

- Note when historical employment predates UAN (launched ~October 2014 in India) and therefore may not appear in UAN.
- For "Form 26AS" style rows, use "Not Available" if tax data is not in the PDFs.
- Assign report_color: GREEN (clear), YELLOW (minor issues only), or RED (major discrepancy such as undeclared employment or material fraud risk).
- Use the client_ref and ams_ref values supplied in the user message when populating those fields.
- Use today's date for order_date and verified_date in ISO format (YYYY-MM-DD) unless the user message specifies different dates.
- Be factual; if something cannot be verified from the documents, say so in remarks rather than inventing data.
- For every row in verification_checks, check_result MUST be exactly "Pass" or "Fail" (never "Match Found", "No Match Found", "Not Available", or any other wording).

Key findings (must mirror standard TRUEXAMINE research output):
- Populate key_findings as an ordered list of plain-text strings. Each string MUST start with its index and a period and space, e.g. "1. ...", "2. ...", "3. ...".
- Cover the following themes when they apply to THIS candidate's documents (omit a numbered item only if genuinely not applicable; do not pad with generic filler):
  1) Missing employment in any source: employers or date ranges that appear in one source (UAN/CV/BGV) but are missing or materially inconsistent in one or both of the other sources.
  2) Date alignment: after normalization, call out any remaining real calendar mismatches between CV vs BGV vs UAN (e.g. join/end dates off by a real day). If none, state clearly that dates align (or that no material mismatch was found).
  3) Employer / legal transitions visible in UAN (e.g. IBM India Pvt Ltd to Kyndryl Solutions Pvt Ltd) and whether records support the transition.
  4) Employment not shown in UAN: explain using factual reasons from records (e.g. spell entirely before UAN go-live ~October 2014 in India, such as older HCL tenure), without claiming a mismatch when UAN simply did not yet exist for that period.
- research_remarks may summarize context, but the numbered key_findings must carry the main narrative like a client-facing AMS report.

Annexure table data (for the annexure page):
- Provide annexure_rows as an employment reconciliation table with one row per company / employment spell.
- Include ALL companies found across UAN (PF), CV, and BGV (not just discrepancies).
- Each annexure row must include:
  - employer_name
  - employment_start_date (YYYY-MM-DD when known, else empty string)
  - employment_end_date (YYYY-MM-DD when known; use "Present" for ongoing)
  - pf_match (Yes/No/Partial)
  - bgv_match (Yes/No/Partial)
  - cv_match (Yes/No/Partial)
  - match_status (examples: "Match", "Partial Match", "Mismatch", "Undeclared Employment")
  - remarks
- Use factual values from the PDFs only.
- Keep annexure row remarks short: maximum 12 words, one concise sentence, no filler.

Education table data (for the annexure page):
- Provide education_qualifications with one row per qualification found in CV / BGV.
- Each education row must include:
  - qualification
  - institution
  - year
  - cv_match (Yes/No/Partial)
  - bgv_match (Yes/No/Partial)
  - remarks
- Include ALL identified qualifications (not just mismatches). If a field is unavailable, use "Not Available".
- Keep education remarks short: maximum 10 words, concise and specific.

Verifier block (footer of research section):
- Set verifier_name and verifier_designation to "Research" when verification is document/desk-based (typical for this check type).
- Set verifier_email and verifier_phone to "Not Available" unless a real verifier identity appears in the PDFs.
INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * Verification check rows use withoutAdditionalProperties() because OpenAI requires
     * additionalProperties: false on object types used as array items.
     */
    public function schema(JsonSchema $schema): array
    {
        $checkRow = $schema->object([
            'check_name' => $schema->string()->required(),
            'type_of_search' => $schema->string()->required(),
            'given' => $schema->string()->required(),
            'verified' => $schema->string()->required(),
            'check_result' => $schema->string()->enum(['Pass', 'Fail'])->required(),
        ])->withoutAdditionalProperties();

        $annexureRow = $schema->object([
            'employer_name' => $schema->string()->required(),
            'employment_start_date' => $schema->string()->required(),
            'employment_end_date' => $schema->string()->required(),
            'pf_match' => $schema->string()->required(),
            'bgv_match' => $schema->string()->required(),
            'cv_match' => $schema->string()->required(),
            'match_status' => $schema->string()->required(),
            'remarks' => $schema->string()->required(),
        ])->withoutAdditionalProperties();

        $educationRow = $schema->object([
            'qualification' => $schema->string()->required(),
            'institution' => $schema->string()->required(),
            'year' => $schema->string()->required(),
            'cv_match' => $schema->string()->required(),
            'bgv_match' => $schema->string()->required(),
            'remarks' => $schema->string()->required(),
        ])->withoutAdditionalProperties();

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
            'annexure_rows' => $schema->array()->items($annexureRow)->required(),
            'education_qualifications' => $schema->array()->items($educationRow)->required(),
            'verifier_name' => $schema->string()->required(),
            'verifier_designation' => $schema->string()->required(),
            'verifier_email' => $schema->string()->required(),
            'verifier_phone' => $schema->string()->required(),
        ];
    }
}
