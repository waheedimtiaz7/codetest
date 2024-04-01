<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreJobRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'from_language_id' => 'required|integer',
            'due_date' => 'required_if:immediate,no|date_format:m/d/Y',
            'due_time' => 'required_if:immediate,no|date_format:H:i',
            'duration' => 'required|integer|min:1',
            'customer_phone_type' => 'required_without:customer_physical_type|in:yes,no',
            'customer_physical_type' => 'required_without:customer_phone_type|in:yes,no',
            'immediate' => 'required|in:yes,no',
        ];
    }

    /**
    * Get custom messages for validator errors.
    *
    * @return array
    */
    public function messages()
    {
        return [
            'required' => 'Du måste fylla in alla fält',
            'in' => 'Ogiltigt val angivet',
            'required_without' => 'Du måste göra ett val här',
            'date_format' => 'Ogiltigt datumformat',
            'integer' => 'Måste vara ett heltal',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $response = [
            'status' => 'fail',
            'message' => $validator->errors()->first(),
            'field_name' => array_keys($validator->errors()->toArray())[0],
        ];

        throw new ValidationException($validator, response()->json($response, 422));
    }
}