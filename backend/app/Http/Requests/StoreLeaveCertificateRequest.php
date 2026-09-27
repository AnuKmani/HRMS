<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use App\Rules\CertificateContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/leave/{leaveRequest}/certificate
 *
 * A medical document arriving on somebody's employment record, so the shape
 * is checked three ways before anything is written:
 *
 *  - declared extension (`mimes`) and sniffed content type (`mimetypes`) —
 *    an HTTP client may name a part anything it likes;
 *  - size, from the same config the storage layer re-checks;
 *  - content, through CertificateContent, so 3 MB of bytes renamed `note.pdf`
 *    is refused here with a message naming the field rather than discovered
 *    later by whoever cannot open it.
 *
 * Authorization is instance-level: you may file a certificate for a request
 * you may already read, and only while it can still accept one.
 */
class StoreLeaveCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leave = $this->route('leaveRequest');

        return $leave instanceof LeaveRequest
            ? ($this->user()?->can('uploadCertificate', $leave) ?? false)
            : false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) config('hrms.storage.certificate_max_kilobytes', 5120);

        return [
            'certificate' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                'max:'.$maxKilobytes,
                new CertificateContent,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'certificate.required' => 'Attach the medical certificate.',
            'certificate.mimes' => 'That file must be a PDF, JPG, PNG or WebP image.',
            'certificate.mimetypes' => 'That file must be a PDF, JPG, PNG or WebP image.',
            'certificate.max' => 'That certificate is too large. Keep the scan under the configured limit.',
            'certificate.certificate_content' => 'That file is not a PDF or an image a reader could open.',
        ];
    }
}
