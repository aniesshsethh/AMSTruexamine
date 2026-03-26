<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>TRUEXAMINE — {{ $report['client_ref'] ?? ($report['ams_ref'] ?? 'Report') }}</title>
    <style>
        @page { margin: 11mm 10mm 10mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.4pt; color: #222; line-height: 1.12; }
        .page-wrap { width: 98%; margin: 0 auto; }
        .top-logo { text-align: center; margin-bottom: 2pt; }
        .top-logo img { height: 34px; }
        .vendor-sub { text-align: center; font-size: 10px; font-weight: 700; letter-spacing: 0.2px; margin-top: -5px; }
        .meta-table { width: 96%; border-collapse: collapse; margin: 5pt auto 0 auto; font-size: 7.9pt; table-layout: fixed; }
        .meta-table td { border: 1px solid #333; padding: 4px 4px; vertical-align: middle; }
        .meta-table strong { font-weight: 700; }
        .status-chip { background: #f00; color: #000; font-weight: 700; padding: 0 2px; }
        .section-title { text-align: center; margin-top: 8pt; margin-bottom: 6pt; font-size: 13pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1px; }
        .row { width: 100%; margin: 0 0 6pt 0; }
        .col { display: inline-block; width: 49%; vertical-align: top; }
        .label { font-weight: 700; }
        .pair-head { width: 100%; border-collapse: collapse; margin-top: 6pt; table-layout: fixed; }
        .pair-head td { border-bottom: 1px solid #c8c8c8; padding: 0 0 3px 4px; color: #1f2d7a; font-weight: 700; }
        .pair-body { width: 100%; border-collapse: collapse; margin-top: 2pt; table-layout: fixed; }
        .pair-body td { padding: 1px 0 1px 4px; }
        .checks { width: 100%; border-collapse: collapse; margin-top: 5pt; font-size: 8.3pt; }
        .checks th { text-align: left; padding: 3px 5px; color: #1f2d7a; background: #cfcfd1; }
        .checks td { padding: 3px 5px; }
        .checks tbody tr:nth-child(odd) td { background: #cfcfd1; }
        .checks tbody tr:nth-child(even) td { background: #fff; }
        .findings { margin: 0; padding-left: 0; list-style: none; line-height: 1.02; }
        .findings li { margin: 0 0 0.5px 0; page-break-inside: avoid; }
        .legend { width: 100%; border-collapse: collapse; margin-top: 6pt; table-layout: fixed; }
        .legend td { border-top: 1px solid #777; border-bottom: 1px solid #777; padding: 5px 2px; font-size: 8.1pt; text-align: left; vertical-align: middle; }
        .legend-major { padding-top: 9px !important; padding-bottom: 9px !important; }
        .legend-label { display: inline-block; vertical-align: middle; line-height: 1.15; }
        .box { display: inline-block; width: 12px; height: 30px; margin-right: 5px; vertical-align: middle; }
        .legend-nowrap { white-space: nowrap; }
        .b-red { background: #ff0a0a; }
        .b-yellow { background: #f4f000; }
        .b-amber { background: #f5a000; }
        .b-green { background: #008a00; }
        .certs {
            text-align: center;
            margin-top: 3pt;
            padding: 4px 0 2px 0;
            white-space: nowrap;
        }
        .certs img {
            display: inline-block;
            vertical-align: middle;
            margin: 0 6px;
            object-fit: contain;
        }
        .cert-urs { height: 36px; }
        .cert-aicpa { height: 38px; }
        .cert-pbsa { height: 38px; }
        .disclaimer { margin-top: 1pt; margin-bottom: 0; font-size: 5.2pt; line-height: 0.9; text-align: justify; }
        .page-break { page-break-after: always; }
        .annexure-box { border: 1px solid #222; width: 86%; margin: 120px auto 0 auto; padding: 26px 8px; }
        .annexure { text-align: center; font-size: 16pt; font-weight: 700; line-height: 1.35; }
        .annexure-image { display: block; width: 100%; height: 215mm; margin-top: 6pt; }
    </style>
</head>
<body>
    @php
        $orderDate = filled($report['order_date'] ?? null) ? \Carbon\Carbon::parse($report['order_date'])->format('F j, Y') : '';
        $verifiedDate = filled($report['verified_date'] ?? null) ? \Carbon\Carbon::parse($report['verified_date'])->format('F j, Y') : '';
        $dob = filled($report['applicant_dob'] ?? null) ? \Carbon\Carbon::parse($report['applicant_dob'])->format('F j, Y') : '';
        $rows = $report['verification_checks'] ?? [];
        $resolvedReportColor = strtoupper((string) ($report['report_color'] ?? ''));
        $verificationResult = strtolower((string) ($report['research_verification_result'] ?? ''));

        if (str_contains($verificationResult, 'major discrepancy')) {
            $resolvedReportColor = 'RED';
        } elseif (str_contains($verificationResult, 'minor discrepancy')) {
            $resolvedReportColor = 'YELLOW';
        } elseif ($resolvedReportColor === '') {
            $resolvedReportColor = 'RED';
        }
    @endphp

    <div class="page-wrap">
        <div class="top-logo">
            <img src="{{ public_path('pdf-assets/ams-logo.png') }}" alt="AMS Inform">
        </div>
        <div class="vendor-sub">A.M.S. INFORM PRIVATE LIMITED</div>

        <table class="meta-table">
            <colgroup>
                <col style="width:37%">
                <col style="width:30%">
                <col style="width:33%">
            </colgroup>
            <tr>
                <td><strong>Order Date:</strong> {{ $orderDate }}</td>
                <td><strong>Verified Date:</strong> {{ $verifiedDate }}</td>
                <td><strong>Client:</strong> {{ $report['client_name'] ?? '' }}</td>
            </tr>
            <tr>
                <td><strong>Client Ref. No:</strong>&nbsp;<span style="white-space: nowrap;">{{ $report['client_ref'] ?? '' }}</span></td>
                <td><strong>AMS Ref. No:</strong> {{ $report['ams_ref'] ?? '' }}</td>
                <td><strong>Color:</strong> <span class="status-chip">{{ $resolvedReportColor }}</span></td>
            </tr>
        </table>

        <div class="section-title">GLOBAL DATABASE CHECK</div>

        <div class="row">
            <div class="col"><span class="label">Applicant Details:</span> {{ $report['applicant_name'] ?? '' }}</div>
            <div class="col"><span class="label">Date of Birth:</span> {{ $dob }}</div>
        </div>

        <table class="pair-head">
            <colgroup>
                <col style="width:22%">
                <col style="width:28%">
                <col style="width:22%">
                <col style="width:28%">
            </colgroup>
            <tr>
                <td colspan="2">Given</td>
                <td colspan="2">Verified</td>
            </tr>
        </table>
        <table class="pair-body">
            <colgroup>
                <col style="width:22%">
                <col style="width:28%">
                <col style="width:22%">
                <col style="width:28%">
            </colgroup>
            <tr>
                <td class="label">Country</td>
                <td>{{ $report['applicant_country_given'] ?? '' }}</td>
                <td class="label">Country</td>
                <td>{{ $report['applicant_country_verified'] ?? '' }}</td>
            </tr>
            <tr>
                <td class="label">Type of Search</td>
                <td>{{ $rows[0]['type_of_search'] ?? 'TRUEXAMINE CHECK' }}</td>
                <td class="label">Type of Search</td>
                <td>{{ $rows[0]['type_of_search'] ?? 'TRUEXAMINE CHECK' }}</td>
            </tr>
        </table>

        <table class="checks">
            <thead>
                <tr>
                    <th style="width:60%">Check Name</th>
                    <th>Check Result</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['check_name'] ?? '' }}</td>
                        <td>{{ $row['check_result'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="pair-head" style="margin-top:12pt;">
            <tr>
                <td>Contact Method</td>
                <td>Result of Verification</td>
            </tr>
        </table>
        <table class="pair-body">
            <tr>
                <td>{{ $report['research_contact_method'] ?? '' }}</td>
                <td>{{ $report['research_verification_result'] ?? '' }}</td>
            </tr>
        </table>

        <div style="margin-top:4pt;"><span class="label">Remarks:</span> {{ $report['research_remarks'] ?? '' }}</div>
        <div><span class="label">Key Findings:</span></div>
        <ul class="findings">
            @foreach($report['key_findings'] ?? [] as $finding)
                <li>{{ $finding }}</li>
            @endforeach
        </ul>

        <table class="pair-head" style="margin-top:6pt;">
            <tr>
                <td>Verifier Name</td>
                <td>Verifier Designation</td>
                <td>Verifier E-mail</td>
                <td>Verifier Phone</td>
            </tr>
        </table>
        <table class="pair-body">
            <tr>
                <td>{{ $report['verifier_name'] ?? '' }}</td>
                <td>{{ $report['verifier_designation'] ?? '' }}</td>
                <td>{{ $report['verifier_email'] ?? '' }}</td>
                <td>{{ $report['verifier_phone'] ?? '' }}</td>
            </tr>
        </table>

        <table class="legend">
            <tr>
                <td class="legend-major" style="width:25%;"><span class="box b-red"></span><span class="legend-label legend-nowrap">Major Discrepancy</span></td>
                <td style="width:25%;"><span class="box b-yellow"></span><span class="legend-label legend-nowrap">Minor Discrepancy</span></td>
                <td style="width:50%;"><span class="box b-amber"></span><span class="legend-label">Could not verify / Not enough information</span></td>
                <td style="width:25%;"><span class="box b-green"></span><span class="legend-label legend-nowrap">Clear Report</span></td>
            </tr>
        </table>

        <div class="certs">
            <img class="cert-urs" src="{{ public_path('pdf-assets/cert-urs-ukas.jpg') }}" alt="URS UKAS">
            <img class="cert-aicpa" src="{{ public_path('pdf-assets/cert-aicpa.png') }}" alt="AICPA SOC">
            <img class="cert-pbsa" src="{{ public_path('pdf-assets/cert-pbsa.png') }}" alt="PBSA">
        </div>

        <p class="disclaimer">
            Disclaimer: AMS INFORM makes it easy to interpret reports using Final status such as CLEAR / MAJOR DISCREPANCY / MINOR DISCREPANCY / UTV in each completed report. Those reports marked as CLEAR do not contain any potentially adverse information about candidate. Reports are also color coded for easy identification of final status of the report. This is a strictly confidential document and contains privileged information. This report has been generated under contractual agreement between AMS Inform and it's Client . It is intended only for the individual to whom or entity to which it is addressed as shown at the beginning of the report. If the reader of this report is not the intended recipient, you are hereby notified that any review, dissemination, distribution, uses, or copying of this report is strictly prohibited. If you have received this report in error, please notify us immediately at verify@amsinform.com. AMS Inform is not a legal counsel and does not provide any legal advice. It is advised that client/ intended recipient of this report should work with their legal counsel to ensure overall background screening/verification compliance. All the reports and information listed/provided by AMS Inform to it's Client must be used by it's Client in compliance with all applicable legal and regularity requirements.
        </p>
    </div>

    <div class="page-break"></div>
    <div class="annexure-box">
        <div class="annexure">ANNEXURE<br>&<br>ADDITIONAL DOCUMENTS</div>
    </div>

    <div class="page-break"></div>
    <img class="annexure-image" src="{{ public_path('pdf-assets/annexure-1.jpg') }}" alt="Annexure page one">

    <div class="page-break"></div>
    <img class="annexure-image" src="{{ public_path('pdf-assets/annexure-2.jpg') }}" alt="Annexure page two">
</body>
</html>
