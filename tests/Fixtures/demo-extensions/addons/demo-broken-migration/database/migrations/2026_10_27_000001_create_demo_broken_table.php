<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_broken', function (Blueprint $table) {
            $table->id();
        });

        // A query against a table that is not there, as a broken add-on update would send.
        DB::table('demo_broken_missing')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_broken');
    }
};
