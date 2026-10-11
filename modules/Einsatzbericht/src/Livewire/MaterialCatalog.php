<?php

namespace Modules\Einsatzbericht\src\Livewire;

use Illuminate\Validation\Rule;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Material;
use Modules\Einsatzbericht\src\Models\ReportMaterial;

/** Admins: the catalog of Verbrauchsmaterial with category and fixed unit */
class MaterialCatalog extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $category = 'loeschmittel';

    public string $unit = 'liter';

    public bool $isActive = true;

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('einsatzbericht-materials'), 403);
    }

    public function edit(int $id): void
    {
        $material = Material::findOrFail($id);
        $this->editingId = $material->id;
        $this->name = $material->name;
        $this->category = $material->category;
        $this->unit = $material->unit;
        $this->isActive = $material->is_active;
    }

    public function resetForm(): void
    {
        $this->reset('editingId', 'name', 'isActive');
        $this->category = 'loeschmittel';
        $this->unit = 'liter';
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('eb_materials', 'name')->where('unit', $this->unit)->ignore($this->editingId)],
            'category' => ['required', Rule::in(array_keys(Material::CATEGORIES))],
            'unit' => ['required', Rule::in(array_keys(Material::UNITS))],
        ], ['name.required' => 'Wie heißt das Material?', 'name.unique' => 'Dieses Material gibt es mit dieser Einheit schon.']);

        $material = $this->editingId ? Material::findOrFail($this->editingId) : new Material(['sort_order' => (int) Material::max('sort_order') + 1]);
        $material->fill(['name' => trim($this->name), 'category' => $this->category, 'unit' => $this->unit, 'is_active' => $this->isActive])->save();

        $this->flash = '„'.$material->label().'“ gespeichert.';
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $material = Material::findOrFail($id);

        if (ReportMaterial::where('material_id', $material->id)->exists()) {
            $material->update(['is_active' => false]);
            $this->flash = '„'.$material->label().'“ ist in Berichten verwendet und wurde deaktiviert statt gelöscht.';

            return;
        }

        $material->delete();
        $this->flash = 'Gelöscht.';
    }

    public function render()
    {
        return view('einsatzbericht::livewire.material-catalog', [
            'materials' => Material::orderBy('category')->orderBy('sort_order')->orderBy('name')->get()->groupBy('category'),
        ]);
    }
}
