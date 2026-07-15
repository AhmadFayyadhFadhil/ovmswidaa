<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update users table
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('password');
            $table->boolean('can_request')->default(true)->after('is_active');
            $table->time('availability_start')->nullable()->default('07:30')->after('can_request');
            $table->time('availability_end')->nullable()->default('16:30')->after('availability_start');
        });

        // Activate existing seeded users so they don't get locked out
        \Illuminate\Support\Facades\DB::table('users')->update(['is_active' => true]);

        // 2. Update requests table
        Schema::table('requests', function (Blueprint $table) {
            $table->integer('estimated_duration')->nullable()->after('end_time'); // in hours
            $table->boolean('is_external')->default(false)->after('estimated_duration');
            $table->decimal('third_party_cost', 12, 2)->default(0.00)->after('is_external');
            $table->string('qr_code_token')->nullable()->unique()->after('third_party_cost');
            
            // Security logs
            $table->dateTime('security_checked_out_at')->nullable()->after('qr_code_token');
            $table->dateTime('security_checked_in_at')->nullable()->after('security_checked_out_at');
            $table->string('security_checkout_by')->nullable()->after('security_checked_in_at');
            $table->string('security_checkin_by')->nullable()->after('security_checkout_by');
            $table->text('security_checkout_notes')->nullable()->after('security_checkin_by');
            $table->text('security_checkin_notes')->nullable()->after('security_checkout_notes');
        });

        // Generate tokens for existing requests
        $requests = \Illuminate\Support\Facades\DB::table('requests')->get();
        foreach ($requests as $req) {
            \Illuminate\Support\Facades\DB::table('requests')
                ->where('id', $req->id)
                ->update(['qr_code_token' => 'REQ-' . $req->id . '-' . bin2hex(random_bytes(4))]);
        }

        // 3. Add max_requests_per_day to settings table if it exists
        if (Schema::hasTable('settings')) {
            \Illuminate\Support\Facades\DB::table('settings')->insertOrIgnore([
                'key' => 'max_requests_per_day',
                'value' => '10',
                'type' => 'integer',
                'group' => 'general',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'can_request', 'availability_start', 'availability_end']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_duration', 'is_external', 'third_party_cost', 'qr_code_token',
                'security_checked_out_at', 'security_checked_in_at',
                'security_checkout_by', 'security_checkin_by',
                'security_checkout_notes', 'security_checkin_notes'
            ]);
        });

        if (Schema::hasTable('settings')) {
            \Illuminate\Support\Facades\DB::table('settings')
                ->where('key', 'max_requests_per_day')
                ->delete();
        }
    }
};
