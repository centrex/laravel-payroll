<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Controllers\Api;

use Centrex\Payroll\Support\PayrollEntityRegistry;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class EntityCrudController extends Controller
{
    public function index(Request $request, string $entity): JsonResponse
    {
        $model = PayrollEntityRegistry::makeModel($entity);
        $definition = PayrollEntityRegistry::definition($entity);
        $query = $model->newQuery()->latest($model->getKeyName());

        if ($request->search && $definition['search'] !== []) {
            $search = $request->search;
            $query->where(function ($builder) use ($definition, $search): void {
                foreach ($definition['search'] as $column) {
                    $builder->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request, string $entity): JsonResponse
    {
        $payload = PayrollEntityRegistry::fillablePayload($entity, $request->all());
        $validated = validator($payload, PayrollEntityRegistry::validationRules($entity, null, $payload))->validate();
        $model = PayrollEntityRegistry::makeModel($entity);
        $record = $model->newQuery()->create($validated);

        return response()->json(['data' => $record], 201);
    }

    public function show(string $entity, int $recordId): JsonResponse
    {
        $model = PayrollEntityRegistry::makeModel($entity);
        $record = $model->newQuery()->findOrFail($recordId);

        return response()->json(['data' => $record]);
    }

    public function update(Request $request, string $entity, int $recordId): JsonResponse
    {
        $model = PayrollEntityRegistry::makeModel($entity);
        $record = $model->newQuery()->findOrFail($recordId);
        $payload = PayrollEntityRegistry::fillablePayload($entity, $request->all());
        $validated = validator($payload, PayrollEntityRegistry::validationRules($entity, $record, $payload))->validate();
        $record->fill($validated)->save();

        return response()->json(['data' => $record->fresh()]);
    }

    public function destroy(string $entity, int $recordId): JsonResponse
    {
        $model = PayrollEntityRegistry::makeModel($entity);
        $model->newQuery()->findOrFail($recordId)->delete();

        return response()->json(null, 204);
    }
}
