<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation untuk update observasi
 */
class UpdateObservationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scientific_name' => 'sometimes|string|max:255',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'observation_date' => 'sometimes|date|before_or_equal:today',
            'observation_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:2000',
            'count' => 'nullable|integer|min:1|max:9999',
            'is_wild' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.between' => 'Latitude harus antara -90 dan 90',
            'longitude.between' => 'Longitude harus antara -180 dan 180',
            'observation_date.before_or_equal' => 'Tanggal observasi tidak boleh di masa depan',
        ];
    }
}
