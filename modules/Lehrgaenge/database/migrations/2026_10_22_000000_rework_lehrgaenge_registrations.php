<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lehrgänge become one process only: the Lehrgänge without fixed date of the Kreis (fox112) are the
 * catalog, a Kamerad announces Bedarf, the Ausbildungsleiter enters the confirmed days, the Kamerad
 * can cancel (kept with reason), the Ausbildungsleiter sets "absolviert". Everything else goes:
 * dated Termine, own catalog, validity, certificates, prerequisites, promotion rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lq_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('offering_id'));
        foreach (['lq_offering_mail_optouts', 'lq_offerings', 'lq_certificates', 'lq_promotion_rule_course', 'lq_promotion_rules', 'lq_course_prerequisites'] as $table) {
            Schema::dropIfExists($table);
        }

        // Courses nobody has a record of go; the import brings the catalog back from the portal.
        // Courses with records stay, under their portal name if they had one.
        DB::table('lq_courses')->whereNotExists(fn ($q) => $q->from('lq_records')->whereColumn('lq_records.course_id', 'lq_courses.id'))->delete();
        foreach (DB::table('lq_course_aliases')->orderBy('id')->get() as $alias) {
            if (! DB::table('lq_courses')->where('name', $alias->name)->exists()) {
                DB::table('lq_courses')->where('id', $alias->course_id)->update(['name' => $alias->name]);
            }
        }
        Schema::dropIfExists('lq_course_aliases');

        Schema::table('lq_courses', function (Blueprint $table) {
            $table->dropColumn(['abbreviation', 'category', 'validity_months', 'description', 'is_active', 'sort_order', 'imported', 'show_offerings']);
            // Full names in the portal, e.g. "Atemschutzgeräteträger (Wochenendlehrgang)"
            $table->json('titles')->nullable();
            // Listed in the portal at the last import: only these can be asked for
            $table->boolean('listed')->default(false);
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::table('lq_records', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('start_reminded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
        });

        // requested | confirmed | completed | cancelled
        DB::table('lq_records')->whereIn('status', ['registered', 'planned'])->update(['status' => 'requested']);
        DB::table('lq_records')->where('status', 'failed')->update(['status' => 'cancelled', 'cancelled_at' => DB::raw('updated_at'), 'cancel_reason' => 'Nicht bestanden']);
        DB::table('lq_records')->where('status', 'cancelled')->whereNull('cancel_reason')->update(['cancelled_at' => DB::raw('updated_at'), 'cancel_reason' => 'Abgelehnt']);

        Schema::table('lq_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['expires_on', 'provider', 'expiry_reminded_at']);
        });

        // The confirmed days of a Lehrgang with time and place
        Schema::create('lq_record_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('lq_records')->cascadeOnDelete();
            $table->date('date');
            $table->string('starts_at', 5)->nullable();
            $table->string('ends_at', 5)->nullable();
            $table->string('location', 255)->nullable();
            $table->timestamps();
            $table->index(['record_id', 'date']);
        });
    }

    public function down(): void
    {
        // The removed data can't come back; only the new structure is undone
        Schema::dropIfExists('lq_record_sessions');
        Schema::table('lq_records', fn (Blueprint $table) => $table->dropColumn(['confirmed_at', 'start_reminded_at', 'cancelled_at', 'cancel_reason']));
        Schema::table('lq_courses', fn (Blueprint $table) => $table->dropColumn(['titles', 'listed', 'last_seen_at']));
    }
};
