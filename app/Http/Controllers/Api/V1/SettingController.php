<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\IndexSettingRequest;
use App\Http\Requests\Setting\UpdateSettingRequest;
use App\Http\Resources\SettingResource;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    public function index(IndexSettingRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Setting::class);

        $validated = $request->validated();

        $query = Setting::query();

        if (!empty($validated['search'])) {
            $query->where('key', 'like', '%' . $validated['search'] . '%');
        }

        $query->orderBy('key');

        $perPage = $validated['per_page'] ?? 15;

        $settings = $query->paginate($perPage);

        return SettingResource::collection($settings)->response();
    }

    public function show(string $key): JsonResponse
    {
        Gate::authorize('viewAny', Setting::class);

        $setting = Setting::where('key', $key)->first();

        if (!$setting) {
            return response()->json(['message' => 'Setting not found.'], 404);
        }

        return (new SettingResource($setting))->response();
    }

    public function update(UpdateSettingRequest $request, string $key): JsonResponse
    {
        $validated = $request->validated();

        $existing = Setting::where('key', $key)->first();

        if ($existing) {
            Gate::authorize('update', $existing);
        } else {
            Gate::authorize('create', Setting::class);
        }

        $oldValue = $existing?->value;

        $setting = Setting::updateOrCreate(
            ['key' => $key],
            [
                'value'      => $validated['value'] ?? null,
                'updated_by' => auth('admin')->id(),
            ]
        );

        AuditLog::create([
            'user_id'     => auth('admin')->id(),
            'role'        => auth('admin')->user()->role->name ?? null,
            'action'      => $existing ? 'Updated' : 'Created',
            'module'      => 'settings',
            'target_type' => 'Setting',
            'target_id'   => $setting->id,
            'old_value'   => $existing ? ['value' => $oldValue] : null,
            'new_value'   => ['value' => $setting->value],
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
        ]);

        return (new SettingResource($setting))->response()->setStatusCode(200);
    }
}
