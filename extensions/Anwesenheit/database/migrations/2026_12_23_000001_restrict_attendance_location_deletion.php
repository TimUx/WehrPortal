<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('x_locations')) {
            return;
        }
        foreach (['x_att_lists', 'x_att_templates'] as $name) {
            $this->replaceLocationKey($name, restrict: true);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('x_locations')) {
            return;
        }
        foreach (['x_att_lists', 'x_att_templates'] as $name) {
            $this->replaceLocationKey($name, restrict: false);
        }
    }

    private function replaceLocationKey(string $name, bool $restrict): void
    {
        if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'ext_location_id')) {
            return;
        }
        $keys = array_column(array_filter(Schema::getForeignKeys($name), fn (array $key) => $key['columns'] === ['ext_location_id']
            && $key['foreign_table'] === 'x_locations' && $key['foreign_columns'] === ['id']), 'name');
        if (! $restrict && $keys === []) {
            return;
        }
        Schema::table($name, function (Blueprint $table) use ($keys, $restrict) {
            foreach ($keys as $key) {
                $table->dropForeign($key);
            }
            $foreign = $table->foreign('ext_location_id')->references('id')->on('x_locations');
            $restrict ? $foreign->restrictOnDelete() : $foreign->nullOnDelete();
        });
    }
};
