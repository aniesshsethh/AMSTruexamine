<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class GenerateTruexamineReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $pdf = File::types(['pdf'])
            ->max(15 * 1024);

        return [
            'uan_pf_download' => ['required', $pdf],
            'cv' => ['required', $pdf],
            'bgv_profile' => ['required', $pdf],
            'client_name' => ['required', 'string', 'max:255'],
            'client_ref' => ['required', 'string', 'max:255'],
            'ams_ref' => ['required', 'string', 'max:255'],
            'order_date' => ['nullable', 'date_format:Y-m-d'],
            'verified_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uan_pf_download.required' => 'The UAN / PF download PDF is required.',
            'cv.required' => 'The CV PDF is required.',
            'bgv_profile.required' => 'The BGV profile PDF is required.',
        ];
    }
}
