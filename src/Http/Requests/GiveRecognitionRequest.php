<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Giving a recognition (#11): the subject, the person (by their profile address, never an account
 * id) and the type. Whether it may be given is RecognitionsInterface::give()'s to decide.
 */
final class GiveRecognitionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sourceType' => ['required', 'string', 'max:100'],
            'subjectId' => ['required', 'string', 'max:191'],
            'receiver' => ['required', 'string', 'max:30'],
            'type' => ['required', 'string', 'uuid'],
        ];
    }
}
