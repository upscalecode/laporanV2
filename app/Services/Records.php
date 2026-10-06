<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Typed collections retain the existing browser contract; writes run under the production lock. */
class Records
{
    public function all(string $kind): array
    {
        return DB::table('production_records')->where('kind', $kind)->orderBy('sequence')->get()
            ->map(fn ($row) => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR))->all();
    }

    public function get(string $kind, string $id): ?array
    {
        $row = DB::table('production_records')->where(['kind' => $kind, 'record_id' => $id])->first();

        return $row ? json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function put(string $kind, string $id, array $data): array
    {
        DB::table('production_records')->updateOrInsert(['kind' => $kind, 'record_id' => $id], [
            'payload' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return $data;
    }

    public function delete(string $kind, string $id): void
    {
        DB::table('production_records')->where(['kind' => $kind, 'record_id' => $id])->delete();
    }
}
