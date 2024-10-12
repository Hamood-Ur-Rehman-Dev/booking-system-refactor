<?php

namespace App\Http\Requests;


use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class JobActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'job_id'     => 'required|jobs,id',
            'user_id'    => 'sometimes|users,id',
        ];
    }

    public function messages()
    {
        return [
            // Already Existed Messages
        ];
    }

    /**
     * Customize Form Request Errors for a consistent API response
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator)
    {
        // Suggestion: Send an error response rightaway if validation fails from here
        if($this->expectsJson()){
            $errors = (new ValidationException($validator))->errors();

            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'data'    => $errors,
                    'message' => __('Invalid data provided in the request')
                ], 422)
            );
        }

        parent::failedValidation($validator);
    }
}