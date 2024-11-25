<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('replication', function (Blueprint $table) {
            $table->text('json_binlog')->nullable(false);
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
        });

        DB::table('replication')->insert([
            'json_binlog' => '{"file": null, "position": null}',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('replication');
    }
};
