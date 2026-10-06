<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_values')) {
            return;
        }

        DB::transaction(function () {
            DB::table('production_locks')->where('id', 1)->lockForUpdate()->first();

            foreach (DB::table('master_values')->whereIn('category', ['operator', 'produk', 'botol'])->orderBy('position')->get() as $row) {
                $value = trim($row->value);
                if ($value === '') {
                    continue;
                }

                $key = ['kind' => 'master', 'record_id' => hash('sha256', $row->category.'|'.mb_strtolower($value))];
                if (! DB::table('production_records')->where($key)->exists()) {
                    DB::table('production_records')->insert($key + [
                        'payload' => json_encode(['category' => $row->category, 'value' => $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Preserve imported master values: they may already be used by production entries.
    }
};
