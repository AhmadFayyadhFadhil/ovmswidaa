<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Department;
use App\Models\Vehicle;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class TestUserDeletionScenarios extends Command
{
    protected $signature = 'ovms:test-user-deletion';
    protected $description = 'Automated Verification of 6 User Deletion Scenarios on DEV Database';

    public function handle(): int
    {
        $this->info('================================================================');
        $this->info('🧪 MEMULAI AUTOMATED VERIFICATION: 6 SKENARIO USER DELETION DEV');
        $this->info('================================================================');

        $controller = app(UserController::class);

        // Ensure roles exist
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'GA', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Approver', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Driver', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'sanctum']);

        $deptId = Department::value('id') ?? 1;
        $vehicleId = Vehicle::value('id');
        if (!$vehicleId && Schema::hasTable('vehicles')) {
            $vehicleId = DB::table('vehicles')->insertGetId([
                'name'         => 'Test Vehicle DEV',
                'plate_number' => 'N 9999 DEV',
                'capacity'     => 4,
                'status'       => 'available',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // Setup Test Actor (Superadmin)
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin_tester@ovms.dev'],
            [
                'nik'           => 'TEST001',
                'name'          => 'Superadmin Tester',
                'password'      => Hash::make('password'),
                'department_id' => $deptId,
                'is_active'     => 1,
            ]
        );
        $superAdmin->syncRoles(['Admin']);

        // Setup Test Actor (GA Coordinator)
        $gaActor = User::firstOrCreate(
            ['email' => 'ga_tester@ovms.dev'],
            [
                'nik'           => 'TEST002',
                'name'          => 'GA Coordinator Tester',
                'password'      => Hash::make('password'),
                'department_id' => $deptId,
                'is_active'     => 1,
            ]
        );
        $gaActor->syncRoles(['GA']);

        // Control User (Must never be deleted or touched)
        $controlUser = User::firstOrCreate(
            ['email' => 'control_innocent_user@ovms.dev'],
            [
                'nik'           => 'CTRL999',
                'name'          => 'Innocent Control User',
                'password'      => Hash::make('password'),
                'department_id' => $deptId,
                'is_active'     => 1,
            ]
        );
        $controlUserId = $controlUser->id;

        $results = [];

        // -------------------------------------------------------------
        // SKENARIO 1: User A (Tanpa data terkait - Kasus Sederhana)
        // -------------------------------------------------------------
        $userA = User::create([
            'nik'           => 'TEST_A_' . time(),
            'name'          => 'Dummy User A (Simple)',
            'email'         => 'dummy_user_a_' . time() . '@ovms.dev',
            'password'      => Hash::make('password'),
            'department_id' => $deptId,
            'is_active'     => 1,
        ]);
        $userA->syncRoles(['Employee']);

        Auth::login($superAdmin);
        $resA = $controller->destroy($userA);
        $statusA = $resA->getStatusCode();
        $isDeletedA = !User::where('id', $userA->id)->exists();

        $results[] = [
            'scenario' => '1. User A (Tanpa Data Terkait)',
            'actor'    => 'Superadmin',
            'expected' => '200 OK (Berhasil Hapus)',
            'actual'   => $statusA . ' - ' . ($isDeletedA ? 'Record Terhapus' : 'Record Masih Ada'),
            'pass'     => ($statusA === 200 && $isDeletedA),
        ];

        // -------------------------------------------------------------
        // SKENARIO 2: User B (Punya Request Selesai & Relasi Lengkap)
        // -------------------------------------------------------------
        $userB = User::create([
            'nik'           => 'TEST_B_' . time(),
            'name'          => 'Dummy User B (With Completed Requests)',
            'email'         => 'dummy_user_b_' . time() . '@ovms.dev',
            'password'      => Hash::make('password'),
            'department_id' => $deptId,
            'is_active'     => 1,
        ]);
        $userB->syncRoles(['Employee']);

        $reqBId = DB::table('requests')->insertGetId([
            'user_id'           => $userB->id,
            'department_id'     => $deptId,
            'destination_city'  => 'Surabaya',
            'destination_place' => 'Kantor Pusat',
            'purpose'           => 'Dinas Meeting',
            'start_time'        => now()->subDays(2),
            'end_time'          => now()->subDays(1),
            'passenger_count'   => 2,
            'priority'          => 'normal',
            'status'            => 'completed',
            'created_at'        => now()->subDays(2),
            'updated_at'        => now()->subDays(1),
        ]);

        if (Schema::hasTable('passengers')) {
            DB::table('passengers')->insert([
                'request_id' => $reqBId,
                'user_id'    => $userB->id,
                'name'       => $userB->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Auth::login($superAdmin);
        $resB = $controller->destroy($userB);
        $statusB = $resB->getStatusCode();
        $isDeletedB = !User::where('id', $userB->id)->exists();
        $isRequestBCleaned = !DB::table('requests')->where('id', $reqBId)->exists();

        $results[] = [
            'scenario' => '2. User B (Punya Request Completed)',
            'actor'    => 'Superadmin',
            'expected' => '200 OK (Cascade Bersih)',
            'actual'   => $statusB . ' - ' . ($isDeletedB && $isRequestBCleaned ? 'User & Child Terhapus' : 'Gagal'),
            'pass'     => ($statusB === 200 && $isDeletedB && $isRequestBCleaned),
        ];

        // -------------------------------------------------------------
        // SKENARIO 3: User C (Driver dengan Trip ON GOING -> WAJIB 422 GAGAL)
        // -------------------------------------------------------------
        $userC = User::create([
            'nik'           => 'TEST_C_' . time(),
            'name'          => 'Dummy Driver C (On Going Trip)',
            'email'         => 'dummy_driver_c_' . time() . '@ovms.dev',
            'password'      => Hash::make('password'),
            'department_id' => $deptId,
            'is_active'     => 1,
        ]);
        $userC->syncRoles(['Driver']);

        $reqCId = DB::table('requests')->insertGetId([
            'user_id'           => $controlUserId,
            'driver_id'         => $userC->id,
            'vehicle_id'        => $vehicleId,
            'department_id'     => $deptId,
            'destination_city'  => 'Malang',
            'destination_place' => 'Proyek Lapangan',
            'purpose'           => 'Operasional Aktif',
            'start_time'        => now()->subHours(2),
            'end_time'          => now()->addHours(3),
            'passenger_count'   => 1,
            'priority'          => 'urgent',
            'status'            => 'on_going',
            'created_at'        => now()->subHours(2),
            'updated_at'        => now()->subHours(2),
        ]);

        Auth::login($superAdmin);
        $resC = $controller->destroy($userC);
        $statusC = $resC->getStatusCode();
        $isStillExistsC = User::where('id', $userC->id)->exists();

        $results[] = [
            'scenario' => '3. User C (Driver Trip ON GOING)',
            'actor'    => 'Superadmin',
            'expected' => '422 Ditolak (Proteksi Trip Aktif)',
            'actual'   => $statusC . ' - ' . ($isStillExistsC ? 'Ditolak & User Utuh' : 'User Terhapus (Salah)'),
            'pass'     => ($statusC === 422 && $isStillExistsC),
        ];

        // Bersihkan trip C agar database rapi
        DB::table('requests')->where('id', $reqCId)->delete();
        $userC->delete();

        // -------------------------------------------------------------
        // SKENARIO 4: User D (Approver Request User Lain -> Safe Nullify)
        // -------------------------------------------------------------
        $userD = User::create([
            'nik'           => 'TEST_D_' . time(),
            'name'          => 'Dummy Approver D',
            'email'         => 'dummy_approver_d_' . time() . '@ovms.dev',
            'password'      => Hash::make('password'),
            'department_id' => $deptId,
            'is_active'     => 1,
            'is_department_head' => 1,
        ]);
        $userD->syncRoles(['Approver']);

        $reqDId = DB::table('requests')->insertGetId([
            'user_id'           => $controlUserId,
            'department_id'     => $deptId,
            'destination_city'  => 'Pasuruan',
            'destination_place' => 'Cabang',
            'purpose'           => 'Inspeksi',
            'start_time'        => now()->subDays(5),
            'end_time'          => now()->subDays(4),
            'passenger_count'   => 1,
            'priority'          => 'normal',
            'status'            => 'approved_department',
            'created_at'        => now()->subDays(5),
            'updated_at'        => now()->subDays(5),
        ]);

        if (Schema::hasTable('request_approvals')) {
            DB::table('request_approvals')->insert([
                'request_id'   => $reqDId,
                'approver_id'  => $userD->id,
                'step'         => 1,
                'status'       => 'approved',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        Auth::login($superAdmin);
        $resD = $controller->destroy($userD);
        $statusD = $resD->getStatusCode();
        $isDeletedD = !User::where('id', $userD->id)->exists();
        $isApprovalCleaned = Schema::hasTable('request_approvals') 
            ? !DB::table('request_approvals')->where('approver_id', $userD->id)->exists()
            : true;

        $results[] = [
            'scenario' => '4. User D (Approver Request User Lain)',
            'actor'    => 'Superadmin',
            'expected' => '200 OK & Approvals Cleaned',
            'actual'   => $statusD . ' - ' . ($isDeletedD && $isApprovalCleaned ? 'User & Approval Cleaned' : 'Gagal'),
            'pass'     => ($statusD === 200 && $isDeletedD && $isApprovalCleaned),
        ];

        // Bersihkan request D
        DB::table('requests')->where('id', $reqDId)->delete();

        // -------------------------------------------------------------
        // SKENARIO 5: User E (Admin Dihapus oleh GA Koordinator -> WAJIB 403)
        // -------------------------------------------------------------
        $userE = User::create([
            'nik'           => 'TEST_E_' . time(),
            'name'          => 'Dummy Admin E',
            'email'         => 'dummy_admin_e_' . time() . '@ovms.dev',
            'password'      => Hash::make('password'),
            'department_id' => $deptId,
            'is_active'     => 1,
        ]);
        $userE->syncRoles(['Admin']);

        Auth::login($gaActor);
        $resE = $controller->destroy($userE);
        $statusE = $resE->getStatusCode();
        $isStillExistsE = User::where('id', $userE->id)->exists();

        $results[] = [
            'scenario' => '5. User E (Admin dihapus oleh GA)',
            'actor'    => 'GA Coordinator',
            'expected' => '403 Forbidden (Ditolak)',
            'actual'   => $statusE . ' - ' . ($isStillExistsE ? 'Ditolak & Admin Utuh' : 'Terhapus (Salah)'),
            'pass'     => ($statusE === 403 && $isStillExistsE),
        ];

        $userE->delete();

        // -------------------------------------------------------------
        // SKENARIO 6: User F (Hapus Akun Sendiri -> WAJIB 422 GAGAL)
        // -------------------------------------------------------------
        $userF = User::create([
            'nik'           => 'TEST_F_' . time(),
            'name'          => 'Dummy User F (Self Delete)',
            'email'         => 'dummy_user_f_' . time() . '@ovms.dev',
            'password'      => Hash::make('password'),
            'department_id' => $deptId,
            'is_active'     => 1,
        ]);
        $userF->syncRoles(['Employee']);

        Auth::login($userF);
        $resF = $controller->destroy($userF);
        $statusF = $resF->getStatusCode();
        $isStillExistsF = User::where('id', $userF->id)->exists();

        $results[] = [
            'scenario' => '6. User F (Hapus Akun Sendiri)',
            'actor'    => 'User Sendiri',
            'expected' => '422 Ditolak (Self Delete Guard)',
            'actual'   => $statusF . ' - ' . ($isStillExistsF ? 'Ditolak & Akun Utuh' : 'Terhapus (Salah)'),
            'pass'     => ($statusF === 422 && $isStillExistsF),
        ];

        $userF->delete();

        // -------------------------------------------------------------
        // VERIFIKASI INTEGRITAS: CONTROL USER HARUS 100% UTUH
        // -------------------------------------------------------------
        $isControlUserSafe = User::where('id', $controlUserId)->exists();

        // Check foreign_key_checks status
        $fkStatusRow = DB::select("SHOW VARIABLES LIKE 'foreign_key_checks'");
        $fkValue = $fkStatusRow[0]->Value ?? 'UNKNOWN';

        $this->table(
            ['Skenario Pengujian', 'Aktor', 'Ekspektasi', 'Hasil Aktual', 'Status'],
            array_map(function ($r) {
                return [
                    $r['scenario'],
                    $r['actor'],
                    $r['expected'],
                    $r['actual'],
                    $r['pass'] ? '✅ PASS' : '❌ FAIL',
                ];
            }, $results)
        );

        $this->info('----------------------------------------------------------------');
        $this->info('🔍 HASIL AUDIT INTEGRITAS DATABASE:');
        $this->info('1. Data User Lain (Innocent Control User) : ' . ($isControlUserSafe ? '✅ 100% UTUH & AMAN' : '❌ RUSAK'));
        $this->info('2. Status MySQL FOREIGN_KEY_CHECKS         : ' . ($fkValue === 'ON' || $fkValue === '1' ? '✅ ON (1) NORMAL AKTIF' : '❌ ' . $fkValue));
        $this->info('----------------------------------------------------------------');

        $allPassed = collect($results)->every(fn($r) => $r['pass']) && $isControlUserSafe && ($fkValue === 'ON' || $fkValue === '1');

        if ($allPassed) {
            $this->info('🎉 SEMUA 6 SKENARIO PENGUJIAN STATUSNYA 100% PASS!');
            return 0;
        } else {
            $this->error('⚠️ ADA SKENARIO YANG GAGAL. SILAKAN PERIKSA DETAIL DI ATAS.');
            return 1;
        }
    }
}
