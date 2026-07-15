<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create departments table
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Seed initial departments
        $departments = [
            'Information and Technology',
            'Finance and Accounting',
            'HRD & GA',
            'Supply Chain',
            'Technical and Development',
            'Quality Assurance',
            'Quality Control',
            'Production',
            'Regulatory Affairs & PV',
            'Legal & Compliance',
            'Plant Management',
        ];

        foreach ($departments as $name) {
            DB::table('departments')->insert([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Transition users table
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('new_department_id')->nullable()->after('department_id');
        });
        $users = DB::table('users')->get();
        foreach ($users as $user) {
            if ($user->department_id) {
                // If it is an old name, map it
                $mappedName = $user->department_id;
                if ($mappedName === 'IT') $mappedName = 'Information and Technology';
                elseif ($mappedName === 'FA') $mappedName = 'Finance and Accounting';
                elseif (in_array($mappedName, ['HRD', 'GA', 'HR&GA', 'HRD&GA'])) $mappedName = 'HRD & GA';
                elseif ($mappedName === 'QA') $mappedName = 'Quality Assurance';
                elseif ($mappedName === 'QC') $mappedName = 'Quality Control';
                elseif ($mappedName === 'PRODUKSI') $mappedName = 'Production';
                elseif (in_array($mappedName, ['TECHNICAL', 'ENGINEERING'])) $mappedName = 'Technical and Development';
                elseif ($mappedName === 'SUPPLY CHAIN') $mappedName = 'Supply Chain';
                elseif ($mappedName === 'SECURITY') $mappedName = 'Legal & Compliance';

                $dept = DB::table('departments')->where('name', $mappedName)->first();
                if ($dept) {
                    DB::table('users')->where('id', $user->id)->update(['new_department_id' => $dept->id]);
                }
            }
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('new_department_id', 'department_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
        });

        // 3. Transition requests table
        Schema::table('requests', function (Blueprint $table) {
            $table->unsignedBigInteger('new_department_id')->nullable()->after('department_id');
        });
        $requests = DB::table('requests')->get();
        foreach ($requests as $request) {
            if ($request->department_id) {
                $mappedName = $request->department_id;
                if ($mappedName === 'IT') $mappedName = 'Information and Technology';
                elseif ($mappedName === 'FA') $mappedName = 'Finance and Accounting';
                elseif (in_array($mappedName, ['HRD', 'GA', 'HR&GA', 'HRD&GA'])) $mappedName = 'HRD & GA';
                elseif ($mappedName === 'QA') $mappedName = 'Quality Assurance';
                elseif ($mappedName === 'QC') $mappedName = 'Quality Control';
                elseif ($mappedName === 'PRODUKSI') $mappedName = 'Production';
                elseif (in_array($mappedName, ['TECHNICAL', 'ENGINEERING'])) $mappedName = 'Technical and Development';
                elseif ($mappedName === 'SUPPLY CHAIN') $mappedName = 'Supply Chain';
                elseif ($mappedName === 'SECURITY') $mappedName = 'Legal & Compliance';

                $dept = DB::table('departments')->where('name', $mappedName)->first();
                if ($dept) {
                    DB::table('requests')->where('id', $request->id)->update(['new_department_id' => $dept->id]);
                }
            }
        }
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
        Schema::table('requests', function (Blueprint $table) {
            $table->renameColumn('new_department_id', 'department_id');
        });
        Schema::table('requests', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
        });

        // 4. Transition passengers table
        Schema::table('passengers', function (Blueprint $table) {
            $table->unsignedBigInteger('new_department_id')->nullable()->after('department_id');
        });
        $passengers = DB::table('passengers')->get();
        foreach ($passengers as $passenger) {
            if ($passenger->department_id) {
                $mappedName = $passenger->department_id;
                if ($mappedName === 'IT') $mappedName = 'Information and Technology';
                elseif ($mappedName === 'FA') $mappedName = 'Finance and Accounting';
                elseif (in_array($mappedName, ['HRD', 'GA', 'HR&GA', 'HRD&GA'])) $mappedName = 'HRD & GA';
                elseif ($mappedName === 'QA') $mappedName = 'Quality Assurance';
                elseif ($mappedName === 'QC') $mappedName = 'Quality Control';
                elseif ($mappedName === 'PRODUKSI') $mappedName = 'Production';
                elseif (in_array($mappedName, ['TECHNICAL', 'ENGINEERING'])) $mappedName = 'Technical and Development';
                elseif ($mappedName === 'SUPPLY CHAIN') $mappedName = 'Supply Chain';
                elseif ($mappedName === 'SECURITY') $mappedName = 'Legal & Compliance';

                $dept = DB::table('departments')->where('name', $mappedName)->first();
                if ($dept) {
                    DB::table('passengers')->where('id', $passenger->id)->update(['new_department_id' => $dept->id]);
                }
            }
        }
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
        Schema::table('passengers', function (Blueprint $table) {
            $table->renameColumn('new_department_id', 'department_id');
        });
        Schema::table('passengers', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign keys first
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });
        Schema::table('requests', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        // 1. Revert passengers table
        Schema::table('passengers', function (Blueprint $table) {
            $table->string('old_department_id')->nullable()->after('department_id');
        });
        $passengers = DB::table('passengers')->get();
        foreach ($passengers as $passenger) {
            if ($passenger->department_id) {
                $dept = DB::table('departments')->where('id', $passenger->department_id)->first();
                if ($dept) {
                    DB::table('passengers')->where('id', $passenger->id)->update(['old_department_id' => $dept->name]);
                }
            }
        }
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
        Schema::table('passengers', function (Blueprint $table) {
            $table->renameColumn('old_department_id', 'department_id');
        });

        // 2. Revert requests table
        Schema::table('requests', function (Blueprint $table) {
            $table->string('old_department_id')->nullable()->after('department_id');
        });
        $requests = DB::table('requests')->get();
        foreach ($requests as $request) {
            if ($request->department_id) {
                $dept = DB::table('departments')->where('id', $request->department_id)->first();
                if ($dept) {
                    DB::table('requests')->where('id', $request->id)->update(['old_department_id' => $dept->name]);
                }
            }
        }
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
        Schema::table('requests', function (Blueprint $table) {
            $table->renameColumn('old_department_id', 'department_id');
        });

        // 3. Revert users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('old_department_id')->nullable()->after('department_id');
        });
        $users = DB::table('users')->get();
        foreach ($users as $user) {
            if ($user->department_id) {
                $dept = DB::table('departments')->where('id', $user->department_id)->first();
                if ($dept) {
                    DB::table('users')->where('id', $user->id)->update(['old_department_id' => $dept->name]);
                }
            }
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('old_department_id', 'department_id');
        });

        // 4. Drop departments table
        Schema::dropIfExists('departments');
    }
};
