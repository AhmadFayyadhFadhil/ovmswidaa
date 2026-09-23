<?php

namespace Database\Seeders;

use App\Models\GaTeamApprover;
use Illuminate\Database\Seeder;

class GaTeamApproverSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultApprovers = [
            [
                'name' => 'Pak Agus',
                'position' => 'GA Supervisor',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Melodi Bella Astria',
                'position' => 'GA Head - Atas Nama',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Tim GA Operasional',
                'position' => 'Tim Operasional',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Staff GA Standby',
                'position' => 'Staff Standby',
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($defaultApprovers as $data) {
            GaTeamApprover::firstOrCreate(
                ['name' => $data['name']],
                $data
            );
        }
    }
}
