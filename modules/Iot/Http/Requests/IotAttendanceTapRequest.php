<?php

declare(strict_types=1);

namespace Modules\Iot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates incoming ESP32 RFID/ID tap payloads including required card UID and optional timestamp.
 */
class IotAttendanceTapRequest extends FormRequest
{
    /**
     * Allow any device already authenticated by the AuthenticateIotDevice middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the attendance tap request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'uid' => ['required', 'string', 'max:100'],
            'device_timestamp' => ['nullable', 'date'],
        ];
    }
}
