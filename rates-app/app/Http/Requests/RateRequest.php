<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => [
                'bail',
                'required',
                'string',
                'date_format:Y-m-d',
                Rule::date()->beforeOrEqual(now('Europe/Moscow')->format('Y-m-d'))
            ],
            'target' => [
                'bail',
                'required',
                'string',
                'regex:/\A[A-Z]{3}\z/'
            ],
            'base' => [
                'bail',
                'required',
                'string',
                'regex:/\A[A-Z]{3}\z/',
                'different:target'
            ]
        ];
    }

    public function prepareForValidation(): void
    {
        $params = [
            'target' => $this->input('target'),
            'base' => $this->input('base', 'RUR')
        ];

        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $value = strtoupper(trim($value));
                $params[$key] = $value;
            }
        }

        $this->merge($params);
    }

    public function messages(): array
    {
        return [
            'date.date_format' => 'Date must use YYYY-MM-DD format.',
            'date.before_or_equal' => 'Date must not be in the future.',
            'base.different' => 'Target and base currencies must differ.'
        ];
    }
}
