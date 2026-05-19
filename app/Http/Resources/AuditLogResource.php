<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape estándar de un audit log entry para el activity feed del Show de
 * cualquier módulo. Compartido — cuando clones a Patients/Doctors/etc, el
 * feed usa este mismo Resource sin tocar nada.
 *
 * Asume `with('user:id,name,email')` en el query origen para evitar N+1.
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'event'      => $this->event,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'created_at' => $this->created_at?->toIso8601String(),
            'user'       => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ] : null,
        ];
    }
}
