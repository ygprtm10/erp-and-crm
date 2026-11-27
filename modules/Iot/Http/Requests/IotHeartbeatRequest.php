<?php

declare(strict_types=1);

namespace Modules\Iot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates optional ESP32 firmware and MAC telemetry on heartbeat requests.
 */
class IotHeartbeatRequest extends FormRequest
{
    /**
     * Allow any device already authenticated by the AuthenticateIotDevice middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the heartbeat request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'firmware_version' => ['nullable', 'string', 'max:64'],
            'mac_address' => ['nullable', 'string', 'max:32'],
        ];
    }
}
