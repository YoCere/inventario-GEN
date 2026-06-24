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
        return [
            'receipt' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ];
    }
}
