<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire\Entities;

use Centrex\Payroll\Support\PayrollEntityRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class EntityIndexPage extends Component
{
    use WithPagination;

    public string $entity = '';

    public string $search = '';

    public function mount(string $entity): void
    {
        PayrollEntityRegistry::definition($entity);

        $this->entity = $entity;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $recordId): void
    {
        $model = PayrollEntityRegistry::makeModel($this->entity);
        $model->newQuery()->findOrFail($recordId)->delete();

        session()->flash('payroll.status', 'Record deleted.');
        $this->resetPage();
    }

    public function render(): View
    {
        $definition = PayrollEntityRegistry::definition($this->entity);
        $model = PayrollEntityRegistry::makeModel($this->entity);
        $query = $model->newQuery()->latest($model->getKeyName());

        if ($this->search !== '' && $definition['search'] !== []) {
            $search = $this->search;
            $query->where(function ($builder) use ($definition, $search): void {
                foreach ($definition['search'] as $column) {
                    $builder->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        return view('payroll::livewire.entities.index-page', [
            'definition' => $definition,
            'columns'    => PayrollEntityRegistry::indexColumns($this->entity),
            'records'    => $query->paginate(15),
        ]);
    }
}
