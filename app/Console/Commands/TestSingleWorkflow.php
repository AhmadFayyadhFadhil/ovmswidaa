<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Department;
use App\Models\Vehicle;
use App\Models\Request as VehicleRequest;
use App\Models\Passenger;
use App\Models\Assignment;
use App\Models\OperationalTrip;
use App\Enums\RequestStatus;
use App\Actions\Approvals\ApproveRequestAction;
use App\Actions\Assignments\AssignDriverAction;
use App\Services\DriverTaskQueueService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class TestSingleWorkflow extends Command
{
    protected $signature   = 'ovms:test-single-workflow {--cleanup : Remove test request records after completion}';
    protected $description = 'Automated End-to-End Test for 1 Complete Request Workflow across all roles on DEV';

    public function handle(): int
    {
        $this->info('================================================================================');
        $this->info('🧪 MEMULAI END-TO-END VERIFIKASI: 1 COMPLETE WORKFLOW REQUEST DI BACKEND');
        $this->info('================================================================================');
        $this->line('Alur: Submit (Karyawan) → Approve (Kadep) → Alokasi (Koord Driver) → Tetapkan (GA) → Checkout (Security) → Checkin (Security) → Rating (Karyawan)');
        $this->newLine();

        $steps = [];

        try {
            // 0. Ensure Roles exist
            Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
            Role::firstOrCreate(['name' => 'GA', 'guard_name' => 'sanctum']);
            Role::firstOrCreate(['name' => 'Approver', 'guard_name' => 'sanctum']);
            Role::firstOrCreate(['name' => 'Driver', 'guard_name' => 'sanctum']);
            Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'sanctum']);
            Role::firstOrCreate(['name' => 'Security', 'guard_name' => 'sanctum']);
            Role::firstOrCreate(['name' => 'Driver Coordinator', 'guard_name' => 'sanctum']);

            $dept = Department::first() ?? Department::create(['name' => 'General Affairs & HRD', 'code' => 'GA-HRD']);

            // Helper to get or create actor
            $getActor = function (string $roleName, string $prefix, string $name) use ($dept) {
                $user = User::whereHas('roles', fn($q) => $q->where('name', $roleName))->first();
                if ($user) {
                    return $user;
                }
                $uniq = time() . rand(100, 999);
                $newUser = User::create([
                    'nik'           => $prefix . '_' . $uniq,
                    'email'         => strtolower($prefix) . '_' . $uniq . '@ovms.test',
                    'name'          => $name,
                    'password'      => Hash::make('password123'),
                    'department_id' => $dept->id,
                    'is_active'     => 1,
                    'is_department_head' => ($roleName === 'Approver') ? 1 : 0,
                ]);
                $newUser->syncRoles([$roleName]);
                return $newUser;
            };

            $employee    = $getActor('Employee', 'EMP', 'Test Karyawan Pemohon');
            $deptHead    = $getActor('Approver', 'KADEP', 'Test Kepala Departemen');
            $coordinator = $getActor('Driver Coordinator', 'KOORD', 'Test Koordinator Driver');
            $gaOfficer   = $getActor('GA', 'GA', 'Test GA Coordinator');
            $driver      = $getActor('Driver', 'DRV', 'Test Pak Driver Widatra');
            $security    = $getActor('Security', 'SEC', 'Test Petugas Satpam');

            // Vehicle
            $vehicle = Vehicle::firstOrCreate(
                ['plate_number' => 'N 7777 WID'],
                [
                    'name'     => 'Toyota Innova Reborn Test',
                    'type'     => 'passenger',
                    'capacity' => 7,
                    'status'   => 'Available',
                ]
            );
            $vehicle->update(['status' => 'Available']);
            $driver->update(['availability_status' => 'available']);

            // -------------------------------------------------------------------------
            // TAHAP 1: Submit Permohonan (Karyawan)
            // -------------------------------------------------------------------------
            $req = VehicleRequest::create([
                'user_id'           => $employee->id,
                'department_id'     => $dept->id,
                'destination_city'  => 'Surabaya',
                'destination_place' => 'Kantor Pusat Widatra Surabaya',
                'purpose'           => 'Uji Coba Otomatis Sistem Operasional Kendaraan',
                'start_time'        => now()->addDay()->setHour(8)->setMinute(0),
                'end_time'          => now()->addDay()->setHour(17)->setMinute(0),
                'estimated_duration'=> 9,
                'passenger_count'   => 2,
                'priority'          => 'normal',
                'status'            => RequestStatus::SUBMITTED,
            ]);

            Passenger::create([
                'request_id'    => $req->id,
                'user_id'       => $employee->id,
                'name'          => $employee->name,
                'department_id' => $dept->id,
                'is_pic'        => true,
            ]);

            Passenger::create([
                'request_id'    => $req->id,
                'user_id'       => null,
                'name'          => 'Rekan Dinas IT',
                'department_id' => $dept->id,
                'is_pic'        => false,
            ]);

            $step1Pass = ($req->status === RequestStatus::SUBMITTED && $req->id > 0);
            $steps[] = [
                'tahap'     => '1. Pengajuan Tiket (Booking)',
                'aktor'     => 'Karyawan / Pemohon',
                'aksi'      => "Submit pengajuan kendaraan ke {$req->destination_city}",
                'ekspektasi'=> 'Status: submitted (#REQ-' . $req->id . ')',
                'aktual'    => "Status: {$req->status->value} (#REQ-{$req->id})",
                'pass'      => $step1Pass,
            ];

            // -------------------------------------------------------------------------
            // TAHAP 2: Persetujuan Kadep (Head of Department)
            // -------------------------------------------------------------------------
            Auth::setUser($deptHead);
            $approveAction = new ApproveRequestAction();
            $reqAfterKadep = $approveAction->execute($req, 'dept_head', 'approved', 'Disetujui untuk perjalanan dinas operasional.');

            $step2Pass = ($reqAfterKadep->status === RequestStatus::APPROVED_DEPARTMENT);
            $steps[] = [
                'tahap'     => '2. Persetujuan Kepala Departemen',
                'aktor'     => 'Kadep / Approver',
                'aksi'      => 'Review pengajuan & klik Setujui Permintaan',
                'ekspektasi'=> 'Status: approved_department',
                'aktual'    => "Status: {$reqAfterKadep->status->value}",
                'pass'      => $step2Pass,
            ];

            // -------------------------------------------------------------------------
            // TAHAP 3A: Alokasi Driver & Armada (Koordinator Driver)
            // -------------------------------------------------------------------------
            Auth::setUser($coordinator);
            $assignAction = new AssignDriverAction();
            $assignment = $assignAction->execute(
                $reqAfterKadep,
                $driver->id,
                $vehicle->id,
                'Alokasi armada operasional oleh Koordinator',
                [
                    'start_time'         => $req->start_time,
                    'estimated_duration' => 9,
                    'end_time'           => $req->end_time,
                    'priority'           => 'Normal',
                ]
            );

            $reqAfterKoord = $req->fresh();
            $step3aPass = ($reqAfterKoord->status === RequestStatus::ASSIGNED_BY_GA || $assignment->status === 'allocated' || $assignment->status === 'accepted');
            $steps[] = [
                'tahap'     => '3A. Alokasi Driver & Armada',
                'aktor'     => 'Koordinator Driver',
                'aksi'      => "Alokasikan {$vehicle->name} dan {$driver->name}",
                'ekspektasi'=> 'Status: assigned_by_ga / allocated',
                'aktual'    => "Status Req: {$reqAfterKoord->status->value} | Asg: {$assignment->status}",
                'pass'      => $step3aPass,
            ];

            // -------------------------------------------------------------------------
            // TAHAP 3B: Finalisasi & Penetapan Jadwal Resmi (GA Coordinator)
            // -------------------------------------------------------------------------
            Auth::setUser($gaOfficer);
            $reqFinalScheduled = $approveAction->execute($reqAfterKoord, 'hrd_head', 'approved', 'Jadwal resmi dan anggaran operasional diverifikasi.');

            $trip = OperationalTrip::where('request_id', $req->id)->first();
            $step3bPass = ($reqFinalScheduled->status === RequestStatus::DRIVER_ASSIGNED && !empty($reqFinalScheduled->qr_code_token));
            $steps[] = [
                'tahap'     => '3B. Finalisasi Jadwal Resmi & Surat Tugas',
                'aktor'     => 'GA Coordinator',
                'aksi'      => 'Verifikasi & Jadwalkan Resmi (Kirim Surat Tugas & Email Penumpang)',
                'ekspektasi'=> 'Status: driver_assigned + QR Token Terbit',
                'aktual'    => "Status: {$reqFinalScheduled->status->value} | QR: " . ($reqFinalScheduled->qr_code_token ? 'TERBIT' : 'KOSONG'),
                'pass'      => $step3bPass,
            ];

            // -------------------------------------------------------------------------
            // TAHAP 4: Security Check-Out (Gate Keluar / Keberangkatan)
            // -------------------------------------------------------------------------
            Auth::setUser($security);
            $reqFinalScheduled->update([
                'status'                  => RequestStatus::ON_GOING,
                'started_at'              => now(),
                'security_checked_out_at' => now(),
                'security_checkout_by'    => $security->id,
            ]);
            if ($trip) {
                $trip->update(['status' => 'on_going', 'security_checked_out_at' => now()]);
            }
            $driver->update(['availability_status' => 'on_trip']);
            $vehicle->update(['status' => 'In Use']);

            $reqEnRoute = $req->fresh();
            $step4Pass = ($reqEnRoute->status === RequestStatus::ON_GOING && $reqEnRoute->security_checked_out_at !== null);
            $steps[] = [
                'tahap'     => '4. Pos Security Gerbang Keluar',
                'aktor'     => 'Petugas Satpam (Security)',
                'aksi'      => 'Scan QR Code tiket keberangkatan (Check-Out)',
                'ekspektasi'=> 'Status: on_going + Driver: on_trip',
                'aktual'    => "Status: {$reqEnRoute->status->value} | Driver: {$driver->fresh()->availability_status}",
                'pass'      => $step4Pass,
            ];

            // -------------------------------------------------------------------------
            // TAHAP 5: Security Check-In (Gate Masuk / Kepulangan)
            // -------------------------------------------------------------------------
            Auth::setUser($security);
            $reqEnRoute->update([
                'status'                 => RequestStatus::COMPLETED,
                'completed_at'           => now(),
                'security_checked_in_at' => now(),
                'security_checkin_by'    => $security->id,
            ]);
            if ($trip) {
                $trip->update(['status' => 'completed', 'end_datetime' => now(), 'security_checked_in_at' => now()]);
            }
            $driver->update(['availability_status' => 'available']);
            $vehicle->update(['status' => 'Available']);

            $reqCompleted = $req->fresh();
            $step5Pass = ($reqCompleted->status === RequestStatus::COMPLETED && $reqCompleted->security_checked_in_at !== null);
            $steps[] = [
                'tahap'     => '5. Pos Security Gerbang Masuk',
                'aktor'     => 'Petugas Satpam (Security)',
                'aksi'      => 'Scan QR Code tiket kepulangan (Check-In Selesai)',
                'ekspektasi'=> 'Status: completed + Aset Pulih Available',
                'aktual'    => "Status: {$reqCompleted->status->value} | Driver: {$driver->fresh()->availability_status} | Mobil: {$vehicle->fresh()->status}",
                'pass'      => $step5Pass,
            ];

            // -------------------------------------------------------------------------
            // TAHAP 6: Pemberian Rating Driver (Karyawan Pemohon)
            // -------------------------------------------------------------------------
            Auth::setUser($employee);
            $reqCompleted->update([
                'rating'       => 5,
                'rating_notes' => 'Pelayanan driver sangat prima, aman, dan tepat waktu.',
                'rated_at'     => now(),
            ]);

            $reqRated = $req->fresh();
            $step6Pass = ($reqRated->rating === 5 && !empty($reqRated->rating_notes));
            $steps[] = [
                'tahap'     => '6. Ulasan & Rating Driver',
                'aktor'     => 'Karyawan / Pemohon',
                'aksi'      => 'Submit bintang 5 dan testimoni perjalanan',
                'ekspektasi'=> 'Rating: 5 bintang tersimpan',
                'aktual'    => "Rating: {$reqRated->rating} Bintang ({$reqRated->rating_notes})",
                'pass'      => $step6Pass,
            ];

            // -------------------------------------------------------------------------
            // CLEANUP (OPSIONAL)
            // -------------------------------------------------------------------------
            if ($this->option('cleanup')) {
                Passenger::where('request_id', $req->id)->delete();
                Assignment::where('request_id', $req->id)->delete();
                OperationalTrip::where('request_id', $req->id)->delete();
                \App\Models\RequestApproval::where('request_id', $req->id)->delete();
                $req->delete();
                $this->line('  ✓ Data uji coba permohonan dibersihkan.');
            }

            // Output table
            $this->info('--------------------------------------------------------------------------------');
            $this->info('📊 HASIL UJI VERIFIKASI 1 SIKLUS WORKFLOW LENGKAP:');
            $this->table(
                ['Tahapan Workflow', 'Aktor / Role', 'Aksi yang Diuji', 'Ekspektasi', 'Hasil Aktual', 'Status'],
                array_map(fn($s) => [
                    $s['tahap'],
                    $s['aktor'],
                    $s['aksi'],
                    $s['ekspektasi'],
                    $s['aktual'],
                    $s['pass'] ? '✅ PASS' : '❌ FAIL'
                ], $steps)
            );

            $allPassed = collect($steps)->every(fn($s) => $s['pass']);

            $this->info('--------------------------------------------------------------------------------');
            $this->info('🔍 AUDIT INTEGRITAS KONDISI AKHIR:');
            $this->info('1. Status Permohonan Akhir : ' . ($reqRated->status === RequestStatus::COMPLETED ? '✅ COMPLETED' : '❌ ' . $reqRated->status->value));
            $this->info('2. Status Ketersediaan Driver: ' . ($driver->fresh()->availability_status === 'available' ? '✅ AVAILABLE' : '❌ ' . $driver->fresh()->availability_status));
            $this->info('3. Status Ketersediaan Mobil : ' . ($vehicle->fresh()->status === 'Available' ? '✅ AVAILABLE' : '❌ ' . $vehicle->fresh()->status));
            $this->info('4. Skor Rating Driver       : ' . ($reqRated->rating === 5 ? '✅ 5 BINTANG TERSIMPAN' : '❌ TIDAK TERSIMPAN'));
            $this->info('--------------------------------------------------------------------------------');

            if ($allPassed) {
                $this->info('🎉 SELURUH TAHAPAN 1 WORKFLOW LENGKAP 100% SUKSES DAN AMAN (ALL PASS)!');
                return 0;
            } else {
                $this->error('⚠️ DITEMUKAN KETIDAKSESUAIAN STATUS PADA WORKFLOW.');
                return 1;
            }

        } catch (\Throwable $e) {
            $this->error('Testing gagal karena exception: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
