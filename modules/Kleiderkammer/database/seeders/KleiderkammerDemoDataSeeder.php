<?php

namespace Modules\Kleiderkammer\database\seeders;

use App\Enums\MemberGender;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;

class KleiderkammerDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCatalog();
        $this->seedMembers();
    }

    private function seedCatalog(): void
    {
        $schutzkleidung = ItemCategory::firstOrCreate(['name' => 'Schutzkleidung'], ['sort_order' => 1]);
        $uniform = ItemCategory::firstOrCreate(['name' => 'Uniform'], ['sort_order' => 2]);

        $jacket = ItemType::firstOrCreate(['name' => 'Einsatzjacke'], [
            'category_id' => $schutzkleidung->id,
            'code_prefix' => 'EJA',
            'is_tracked' => true,
            'has_size' => true,
            'is_washable' => true,
            'sort_order' => 1,
        ]);

        foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $i => $name) {
            Size::firstOrCreate(['item_type_id' => $jacket->id, 'name' => $name], ['sort_order' => $i]);
        }

        $trousers = ItemType::firstOrCreate(['name' => 'Einsatzhose'], [
            'category_id' => $schutzkleidung->id,
            'code_prefix' => 'EHO',
            'is_tracked' => true,
            'has_size' => true,
            'is_washable' => true,
            'sort_order' => 2,
        ]);

        foreach (['44', '46', '48', '50', '52', '54', '56', '58'] as $i => $name) {
            Size::firstOrCreate(['item_type_id' => $trousers->id, 'name' => $name], ['sort_order' => $i]);
        }

        $gloves = ItemType::firstOrCreate(['name' => 'Handschuhe'], [
            'category_id' => $schutzkleidung->id,
            'code_prefix' => 'HAN',
            'is_tracked' => false,
            'has_size' => true,
            'is_washable' => false,
            'sort_order' => 3,
        ]);

        foreach (['S', 'M', 'L', 'XL'] as $i => $name) {
            $size = Size::firstOrCreate(['item_type_id' => $gloves->id, 'name' => $name], ['sort_order' => $i]);
            Stock::firstOrCreate(['item_type_id' => $gloves->id, 'size_id' => $size->id], ['quantity' => 5]);
        }

        $shirt = ItemType::firstOrCreate(['name' => 'Diensthemd'], [
            'category_id' => $uniform->id,
            'code_prefix' => 'DHE',
            'is_tracked' => false,
            'has_size' => true,
            'is_washable' => false,
            'sort_order' => 1,
        ]);

        foreach (['S', 'M', 'L', 'XL', 'XXL'] as $i => $name) {
            $size = Size::firstOrCreate(['item_type_id' => $shirt->id, 'name' => $name], ['sort_order' => $i]);
            Stock::firstOrCreate(['item_type_id' => $shirt->id, 'size_id' => $size->id], ['quantity' => 3]);
        }
    }

    private function seedMembers(): void
    {
        $members = [
            ['name' => 'Hanna Hydrant',   'email' => 'hanna.hydrant@example.com',   'department' => 'Aktiver Dienst', 'gender' => MemberGender::Female, 'rank' => 'Gruppenführerin'],
            ['name' => 'Bernd Blaulicht', 'email' => 'bernd.blaulicht@example.com', 'department' => 'Aktiver Dienst', 'gender' => MemberGender::Male,   'rank' => 'Feuerwehrmann'],
            ['name' => 'Klara Lösch',     'email' => 'klara.loesch@example.com',    'department' => 'Jugendfeuerwehr', 'gender' => MemberGender::Female, 'rank' => 'Jugendfeuerwehrfrau'],
            ['name' => 'Tim Tanklösch',   'email' => 'tim.tankloesch@example.com',  'department' => 'Jugendfeuerwehr', 'gender' => MemberGender::Male,   'rank' => 'Jugendfeuerwehrmann'],
        ];

        foreach ($members as $data) {
            // Existing (e.g. Fahrzeugbuchung demo) users only get the member data filled in.
            $user = User::firstOrCreate(['email' => $data['email']], ['name' => $data['name']]);
            $user->update(array_intersect_key($data, array_flip(['gender', 'rank'])));
            $group = Group::firstOrCreate(['name' => $data['department']], ['sort_order' => (int) Group::max('sort_order') + 1]);
            $user->groups()->syncWithoutDetaching([$group->id]);
        }
    }
}
