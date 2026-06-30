<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Acepta una sola imagen (`receipt`) o varias páginas (`receipts[]`).
        // 15MB por imagen (fotos de celular full-res), máx 20 páginas.
        return [
            'receipt'    => ['required_without:receipts', 'image', 'mimes:jpeg,jpg,png,webp', 'max:15360'],
            'receipts'   => ['required_without:receipt', 'array', 'max:20'],
            'receipts.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:15360'],
        ];
    }
}
