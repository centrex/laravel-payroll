<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire\Entities;

use Centrex\Payroll\Support\PayrollEntityRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EntityFormPage extends Component
{
    public string $entity = '';

    public ?int $recordId = null;

    public array $form = [];

    public function mount(string $entity, ?int $recordId = null): void
    {
        $definition = PayrollEntityRegistry::definition($entity);

        $this->entity = $entity;
        $this->recordId = $recordId;
        $this->form = PayrollEntityRegistry::defaultFormData($entity);

        if ($recordId !== null) {
            $record = $this->record();

            foreach ($definition['form_fields'] as $field) {
                $this->form[$field['name']] = $record->getAttribute($field['name']);
            }
        }
    }

    public function save(): \Illuminate\Http\RedirectResponse
    {
        $record = $this->record(false);
        $payload = PayrollEntityRegistry::fillablePayload($this->entity, $this->form);
        $validated = validator($payload, PayrollEntityRegistry::validationRules($this->entity, $record, $payload))->validate();

        if ($record instanceof Model) {
            $record->fill($validated)->save();
        } else {
            $model = PayrollEntityRegistry::makeModel($this->entity);
            $record = $model->newQuery()->create($validated);
        }

        session()->flash('payroll.status', PayrollEntityRegistry::definition($this->entity)['singular'] . ' saved.');

        return redirect()->route("payroll.entities.{$this->entity}.edit", ['recordId' => $record->getKey()]);
    }

    public function render(): View
    {
        return view('payroll::livewire.entities.form-page', [
            'definition' => PayrollEntityRegistry::definition($this->entity),
            'options'    => PayrollEntityRegistry::formOptions($this->entity),
        ]);
    }

    private function record(bool $failIfMissing = true): ?Model
    {
        if ($this->recordId === null) {
            return null;
        }

        $model = PayrollEntityRegistry::makeModel($this->entity);
        $query = $model->newQuery();

        return $failIfMissing
            ? $query->findOrFail($this->recordId)
            : $query->find($this->recordId);
    }
}
