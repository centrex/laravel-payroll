<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Support;

use Centrex\Payroll\Models\{Employee, PayrollAccount};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\{Arr, Str};
use Illuminate\Validation\Rule;

class PayrollEntityRegistry
{
    public static function entities(): array
    {
        return [
            'employees' => [
                'label'         => 'Employees',
                'singular'      => 'Employee',
                'model'         => Employee::class,
                'search'        => ['code', 'name', 'email', 'phone'],
                'index_columns' => ['code', 'name', 'department', 'designation', 'employment_type', 'monthly_salary', 'currency', 'is_active'],
                'form_fields'   => [
                    self::field('code', 'text', ['required', 'string', 'max:30']),
                    self::field('name', 'text', ['required', 'string', 'max:300']),
                    self::field('email', 'email', ['nullable', 'email', 'max:200']),
                    self::field('phone', 'text', ['nullable', 'string', 'max:50']),
                    self::field('address', 'textarea', ['nullable', 'string']),
                    self::field('city', 'text', ['nullable', 'string', 'max:100']),
                    self::field('country', 'text', ['nullable', 'string', 'max:100']),
                    self::field('department', 'text', ['nullable', 'string', 'max:100']),
                    self::field('designation', 'text', ['nullable', 'string', 'max:100']),
                    self::field('employment_type', 'text', ['required', 'string', 'max:50'], 'full_time'),
                    self::field('joining_date', 'date', ['nullable', 'date']),
                    self::field('monthly_salary', 'number', ['nullable', 'numeric', 'min:0'], 0),
                    self::field('bank_account_name', 'text', ['nullable', 'string', 'max:200']),
                    self::field('bank_account_number', 'text', ['nullable', 'string', 'max:100']),
                    self::field('emergency_contact_name', 'text', ['nullable', 'string', 'max:200']),
                    self::field('emergency_contact_phone', 'text', ['nullable', 'string', 'max:50']),
                    self::field('tax_id', 'text', ['nullable', 'string', 'max:50']),
                    self::field('currency', 'text', ['required', 'string', 'size:3'], 'BDT'),
                    self::field('credit_limit', 'number', ['nullable', 'numeric', 'min:0'], 0),
                    self::field('payment_terms', 'number', ['nullable', 'integer', 'min:0'], 30),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                ],
            ],
            'payroll-accounts' => [
                'label'         => 'Payroll Accounts',
                'singular'      => 'Payroll Account',
                'model'         => PayrollAccount::class,
                'search'        => ['code', 'name', 'description'],
                'index_columns' => ['code', 'name', 'currency', 'is_active'],
                'form_fields'   => [
                    self::field('code', 'text', ['required', 'string', 'max:30']),
                    self::field('name', 'text', ['required', 'string', 'max:200']),
                    self::field('description', 'textarea', ['nullable', 'string']),
                    self::field('currency', 'text', ['required', 'string', 'size:3'], 'BDT'),
                    self::field('particulars', 'textarea', ['nullable', 'string']),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                ],
            ],
        ];
    }

    public static function masterDataEntities(): array
    {
        return array_keys(self::entities());
    }

    public static function definition(string $entity): array
    {
        $definition = self::entities()[$entity] ?? null;

        if (!$definition) {
            throw new \InvalidArgumentException("Unknown payroll entity [{$entity}].");
        }

        return $definition;
    }

    public static function modelClass(string $entity): string
    {
        return self::definition($entity)['model'];
    }

    public static function makeModel(string $entity): Model
    {
        $modelClass = self::modelClass($entity);

        return new $modelClass();
    }

    public static function validationRules(string $entity, ?Model $record = null, array $payload = []): array
    {
        $definition = self::definition($entity);
        $rules = [];
        $model = self::makeModel($entity);
        $table = $model->getTable();

        foreach ($definition['form_fields'] as $field) {
            $fieldRules = $field['rules'];

            if (in_array($field['name'], ['code'], true)) {
                $fieldRules[] = Rule::unique($table, $field['name'])->ignore($record?->getKey());
            }

            $rules[$field['name']] = $fieldRules;
        }

        return $rules;
    }

    public static function fillablePayload(string $entity, array $payload): array
    {
        $definition = self::definition($entity);
        $output = [];

        foreach ($definition['form_fields'] as $field) {
            $name = $field['name'];
            $value = Arr::get($payload, $name, $field['default']);

            if ($field['type'] === 'checkbox') {
                $value = (bool) $value;
            }

            if ($value === '' && str_contains(implode('|', $field['rules']), 'nullable')) {
                $value = null;
            }

            if (is_string($value) && in_array($field['type'], ['text', 'email'], true)) {
                $value = trim($value);
                $value = $field['name'] === 'currency' || $field['name'] === 'country_code'
                    ? Str::upper($value)
                    : $value;
            }

            $output[$name] = $value;
        }

        return $output;
    }

    public static function defaultFormData(string $entity): array
    {
        $definition = self::definition($entity);
        $defaults = [];

        foreach ($definition['form_fields'] as $field) {
            $defaults[$field['name']] = $field['default'];
        }

        return $defaults;
    }

    public static function formOptions(string $entity): array
    {
        $definition = self::definition($entity);
        $options = [];

        foreach ($definition['form_fields'] as $field) {
            if (($field['type'] ?? null) !== 'select' || empty($field['related_model'])) {
                continue;
            }

            $related = new $field['related_model']();
            $options[$field['name']] = $related->newQuery()
                ->orderBy($field['related_label'])
                ->get(['id', $field['related_label']])
                ->map(fn (Model $model) => [
                    'value' => (string) $model->getKey(),
                    'label' => (string) $model->getAttribute($field['related_label']),
                ])
                ->all();
        }

        return $options;
    }

    public static function indexColumns(string $entity): array
    {
        return self::definition($entity)['index_columns'];
    }

    public static function searchableColumns(string $entity): array
    {
        return self::definition($entity)['search'];
    }

    private static function field(
        string $name,
        string $type,
        array $rules,
        mixed $default = null,
        ?string $relatedModel = null,
        ?string $relatedLabel = null,
    ): array {
        return [
            'name'          => $name,
            'label'         => Str::of($name)->replace('_', ' ')->title()->toString(),
            'type'          => $type,
            'rules'         => $rules,
            'default'       => $default,
            'related_model' => $relatedModel,
            'related_label' => $relatedLabel,
        ];
    }
}
