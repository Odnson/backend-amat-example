<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation untuk membuat observasi baru
 */
class StoreObservationRequest extends FormRequest
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
            'scientific_name' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'observation_date' => 'required|date|before_or_equal:today',
            'observation_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:2000',
            'count' => 'nullable|integer|min:1|max:9999',
            'is_wild' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
            
            // Media
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|mimes:jpeg,jpg,png,gif,webp,mp3,wav,ogg,aac,m4a|max:15360',
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
            'scientific_name.required' => 'Nama spesies harus diisi',
            'latitude.required' => 'Koordinat latitude harus diisi',
            'latitude.between' => 'Latitude harus antara -90 dan 90',
            'longitude.required' => 'Koordinat longitude harus diisi',
            'longitude.between' => 'Longitude harus antara -180 dan 180',
            'observation_date.required' => 'Tanggal observasi harus diisi',
            'observation_date.before_or_equal' => 'Tanggal observasi tidak boleh di masa depan',
            'media.max' => 'Maksimal 10 file media',
            'media.*.max' => 'Ukuran file maksimal 15MB',
        ];
    }
}
