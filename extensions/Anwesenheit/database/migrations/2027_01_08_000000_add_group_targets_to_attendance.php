<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_att_lists', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->index(['ext_location_id', 'group_id']);
        });

        Schema::table('x_att_templates', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->index(['ext_location_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('x_att_templates', function (Blueprint $table) {
            $table->dropIndex(['ext_location_id', 'group_id']);
            $table->dropConstrainedForeignId('group_id');
        });

        Schema::table('x_att_lists', function (Blueprint $table) {
            $table->dropIndex(['ext_location_id', 'group_id']);
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
