<?php

namespace App\Http\Requests\BusinessManagement\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId   = $this->user()?->tenant_id;
        $customer   = $this->route('customer');
        $customerId = is_object($customer) ? $customer->id : null;

        return [
            // Unicidad de name case + accent insensitive dentro del workspace,
            // ignorando el propio customer y soft-deleted. Sin esta validación,
            // un update a un nombre duplicado revienta con 500 (partial unique
            // index del DB) en lugar de devolver 422.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($tenantId, $customerId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('customers')
                        ->where('tenant_id', $tenantId)
                        ->whereNull('deleted_at')
                        ->when($customerId, fn ($qq) => $qq->where('id', '!=', $customerId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('customers.name_unique'));
                    }
                },
            ],
            'cod'        => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'cod')
                    ->ignore($customerId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
