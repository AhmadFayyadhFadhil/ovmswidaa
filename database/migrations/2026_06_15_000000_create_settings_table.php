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
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->string('group')->default('general');
            $table->timestamps();
        });

        // Insert Default Settings
        $defaults = [
            ['key' => 'system_name', 'value' => 'OVMS', 'type' => 'string', 'group' => 'general', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'timezone', 'value' => 'GMT +7 (Western Indonesia Time)', 'type' => 'string', 'group' => 'general', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'date_format', 'value' => 'YYYY-MM-DD', 'type' => 'string', 'group' => 'general', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'system_language', 'value' => 'Bahasa Indonesia', 'type' => 'string', 'group' => 'general', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_name', 'value' => 'Enterprise Fleet', 'type' => 'string', 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'support_email', 'value' => 'support@ovms.test', 'type' => 'string', 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'hq_address', 'value' => 'Kawasan Industri Subang, Jawa Barat, Indonesia', 'type' => 'string', 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'email_alerts', 'value' => '1', 'type' => 'boolean', 'group' => 'notifications', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'sms_alerts', 'value' => '0', 'type' => 'boolean', 'group' => 'notifications', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'push_notifs', 'value' => '1', 'type' => 'boolean', 'group' => 'notifications', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'digest_mode', 'value' => '0', 'type' => 'boolean', 'group' => 'notifications', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_logo', 'value' => null, 'type' => 'string', 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($defaults as $setting) {
            \Illuminate\Support\Facades\DB::table('settings')->insert($setting);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
