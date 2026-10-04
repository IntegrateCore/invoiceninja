<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Requests\ClientPortal\Documents;

use App\Models\Document;
use App\Utils\Traits\MakesHash;
use Illuminate\Foundation\Http\FormRequest;

class DownloadMultipleDocumentsRequest extends FormRequest
{
    use MakesHash;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var \App\Models\ClientContact $contact */
        $contact = auth()->guard('contact')->user();

        if (!$contact) {
            return false;
        }
        $document_ids = $this->transformKeys($this->file_hash ?? []);

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Document> $documents */
        $documents = Document::query()
            ->whereIn('id', $document_ids)
            ->where('company_id', $contact->company_id)
            ->get();

        // Fail if any requested document doesn't exist in this company
        if ($documents->count() !== count($document_ids)) {
            return false;
        }

        foreach ($documents as $document) {
            if (!(new ShowDocumentRequest())->contactCanAccessDocument($contact, $document)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'file_hash' => ['required', 'array'],
        ];
    }
}
