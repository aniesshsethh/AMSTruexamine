import { Form, Head, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

const formAction = '/truexamine-report';
const xlsxDownloadUrl = '/truexamine-report/download';

export type TruexamineReport = {
    vendor_name: string;
    order_date: string;
    verified_date: string;
    client_name: string;
    client_ref: string;
    ams_ref: string;
    report_color: string;
    applicant_name: string;
    applicant_dob: string;
    applicant_country_given: string;
    applicant_country_verified: string;
    verification_checks: Array<{
        check_name: string;
        type_of_search: string;
        given: string;
        verified: string;
        check_result: string;
    }>;
    research_contact_method: string;
    research_verification_result: string;
    research_remarks: string;
    key_findings: string[];
    verifier_name: string;
    verifier_designation: string;
    verifier_email: string;
    verifier_phone: string;
};

type PageProps = {
    report: TruexamineReport | null;
    defaults: {
        client_name: string;
        client_ref: string;
        ams_ref: string;
    };
};

function colorBadgeClass(color: string): string {
    const c = color.toUpperCase();
    if (c.includes('RED')) {
        return 'bg-red-600 text-white';
    }
    if (c.includes('YELLOW') || c.includes('AMBER')) {
        return 'bg-amber-500 text-black';
    }
    if (c.includes('GREEN')) {
        return 'bg-emerald-600 text-white';
    }
    return 'bg-muted text-foreground';
}

export default function TruexamineReportPage() {
    const { report, defaults } = usePage<PageProps>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: dashboard().url },
        { title: 'Truexamine report', href: formAction },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Truexamine report" />

            <div className="mx-auto max-w-4xl space-y-8 p-4">
                <div className="flex items-start gap-3">
                    <FileText
                        className="mt-1 size-8 text-muted-foreground"
                        aria-hidden
                    />
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Truexamine report generator
                        </h1>
                        <p className="mt-1 text-muted-foreground text-sm">
                            Upload the UAN/PF PDF, CV PDF, and BGV profile PDF.
                            OpenAI generates a structured AMS-style TRUEXAMINE
                            report. Download the Truexamine Check Report and
                            Educational Qualifications tables as an Excel workbook
                            (.xlsx) after generation.
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <Heading
                        variant="small"
                        title="Inputs"
                        description="PDF only, up to 15 MB each. Set OPENAI_API_KEY in .env."
                    />

                    <Form
                        action={formAction}
                        method="post"
                        encType="multipart/form-data"
                        className="mt-6 space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="client_name">
                                            Client name
                                        </Label>
                                        <Input
                                            id="client_name"
                                            name="client_name"
                                            required
                                            defaultValue={defaults.client_name}
                                        />
                                        <InputError message={errors.client_name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="client_ref">
                                            Client ref
                                        </Label>
                                        <Input
                                            id="client_ref"
                                            name="client_ref"
                                            required
                                            defaultValue={defaults.client_ref}
                                        />
                                        <InputError message={errors.client_ref} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="ams_ref">AMS ref</Label>
                                        <Input
                                            id="ams_ref"
                                            name="ams_ref"
                                            required
                                            defaultValue={defaults.ams_ref}
                                        />
                                        <InputError message={errors.ams_ref} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="order_date">
                                            Order date (optional)
                                        </Label>
                                        <Input
                                            id="order_date"
                                            name="order_date"
                                            type="date"
                                        />
                                        <InputError message={errors.order_date} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="verified_date">
                                            Verified date (optional)
                                        </Label>
                                        <Input
                                            id="verified_date"
                                            name="verified_date"
                                            type="date"
                                        />
                                        <InputError
                                            message={errors.verified_date}
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="uan_pf_download">
                                        UAN / PF download PDF
                                    </Label>
                                    <Input
                                        id="uan_pf_download"
                                        name="uan_pf_download"
                                        type="file"
                                        accept="application/pdf"
                                        required
                                    />
                                    <InputError
                                        message={errors.uan_pf_download}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="cv">CV PDF</Label>
                                    <Input
                                        id="cv"
                                        name="cv"
                                        type="file"
                                        accept="application/pdf"
                                        required
                                    />
                                    <InputError message={errors.cv} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="bgv_profile">
                                        BGV profile PDF
                                    </Label>
                                    <Input
                                        id="bgv_profile"
                                        name="bgv_profile"
                                        type="file"
                                        accept="application/pdf"
                                        required
                                    />
                                    <InputError message={errors.bgv_profile} />
                                </div>

                                <InputError message={errors.report} />

                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Generating…'
                                        : 'Generate report'}
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                {report && (
                    <div className="space-y-6 rounded-xl border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold">
                                Generated report
                            </h2>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button variant="default" size="sm" asChild>
                                    <a href={xlsxDownloadUrl}>
                                        Download Excel (.xlsx)
                                    </a>
                                </Button>
                                <span
                                    className={`rounded-md px-3 py-1 font-medium text-sm ${colorBadgeClass(report.report_color)}`}
                                >
                                    {report.report_color}
                                </span>
                            </div>
                        </div>

                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground">
                                    Vendor
                                </dt>
                                <dd className="font-medium">
                                    {report.vendor_name}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Client</dt>
                                <dd className="font-medium">
                                    {report.client_name}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Client ref
                                </dt>
                                <dd>{report.client_ref}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">AMS ref</dt>
                                <dd>{report.ams_ref}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Order date
                                </dt>
                                <dd>{report.order_date}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Verified date
                                </dt>
                                <dd>{report.verified_date}</dd>
                            </div>
                        </dl>

                        <div className="border-t border-border pt-4">
                            <h3 className="font-medium">Applicant</h3>
                            <dl className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Name
                                    </dt>
                                    <dd>{report.applicant_name}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        DOB
                                    </dt>
                                    <dd>{report.applicant_dob}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Country (given)
                                    </dt>
                                    <dd>{report.applicant_country_given}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Country (verified)
                                    </dt>
                                    <dd>{report.applicant_country_verified}</dd>
                                </div>
                            </dl>
                        </div>

                        <div className="border-t border-border pt-4">
                            <h3 className="mb-2 font-medium">Verification checks</h3>
                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left">
                                            <th className="py-2 pr-4 font-medium">
                                                Check
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Type
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Given
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Verified
                                            </th>
                                            <th className="py-2 font-medium">
                                                Result
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {report.verification_checks.map(
                                            (row, i) => (
                                                <tr
                                                    key={`${row.check_name}-${i}`}
                                                    className="border-b border-border/60"
                                                >
                                                    <td className="py-2 pr-4 align-top">
                                                        {row.check_name}
                                                    </td>
                                                    <td className="py-2 pr-4 align-top">
                                                        {row.type_of_search}
                                                    </td>
                                                    <td className="py-2 pr-4 align-top">
                                                        {row.given}
                                                    </td>
                                                    <td className="py-2 pr-4 align-top">
                                                        {row.verified}
                                                    </td>
                                                    <td className="py-2 align-top">
                                                        {row.check_result}
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="border-t border-border pt-4">
                            <h3 className="font-medium">Research</h3>
                            <dl className="mt-2 space-y-2 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Contact method
                                    </dt>
                                    <dd>{report.research_contact_method}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Result
                                    </dt>
                                    <dd>{report.research_verification_result}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Remarks
                                    </dt>
                                    <dd className="whitespace-pre-wrap">
                                        {report.research_remarks}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div className="border-t border-border pt-4">
                            <h3 className="mb-2 font-medium">Key findings</h3>
                            <ul className="list-inside list-disc space-y-1 text-sm">
                                {report.key_findings.map((finding) => (
                                    <li key={finding}>{finding}</li>
                                ))}
                            </ul>
                        </div>

                        <div className="border-t border-border pt-4">
                            <h3 className="mb-2 font-medium">Verifier</h3>
                            <dl className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Verifier name
                                    </dt>
                                    <dd>{report.verifier_name}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Designation
                                    </dt>
                                    <dd>{report.verifier_designation}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        E-mail
                                    </dt>
                                    <dd>{report.verifier_email}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Phone
                                    </dt>
                                    <dd>{report.verifier_phone}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
