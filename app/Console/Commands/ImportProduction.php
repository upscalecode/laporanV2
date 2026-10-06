<?php

namespace App\Console\Commands;

use App\Services\Permissions;
use App\Services\Records;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportProduction extends Command
{
    protected $signature = 'production:import {file : Snapshot JSON dari tools/export-laravel.gs} {--apply : Simpan; tanpa opsi ini hanya validasi}';

    protected $description = 'Validasi atau impor snapshot Google Sheets ke database produksi kosong';

    public function handle(Records $records): int
    {
        try {
            $path = $this->argument('file');
            if (! is_file($path) || ! is_readable($path)) {
                throw new \RuntimeException('File tidak dapat dibaca.');
            }
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            Validator::make($data, [
                'schemaVersion' => 'required|in:1', 'master' => 'required|array', 'settings' => 'required|array',
                'users' => 'present|array', 'entries' => 'present|array', 'spkEntries' => 'present|array',
                'apdEntries' => 'present|array', 'adjustments' => 'present|array', 'downtimeEntries' => 'present|array',
                'audits' => 'present|array', 'photos' => 'present|array',
                'master.operator' => 'present|array', 'master.produk' => 'present|array', 'master.botol' => 'present|array',
                'master.operator.*' => 'required|string|max:200', 'master.produk.*' => 'required|string|max:200', 'master.botol.*' => 'required|string|max:200',
                'settings.kpiFillingOutputTargetMonthly' => 'required|integer|min:1', 'settings.kpiPressOutputTargetMonthly' => 'required|integer|min:1',
                'users.*.username' => 'required|string|max:100|distinct', 'users.*.name' => 'required|string|max:255',
                'users.*.role' => 'required|in:user,superuser', 'users.*.permissions' => 'present|array',
                'photos.*.id' => 'required|string', 'photos.*.owner' => 'required|string|max:100',
            ])->validate();
            $map = ['entries' => ['entry', 'id'], 'spkEntries' => ['spk', 'batchNo'], 'apdEntries' => ['apd', 'id'], 'adjustments' => ['adjustment', 'id'], 'downtimeEntries' => ['downtime', 'tanggal'], 'audits' => ['audit', 'id']];
            $photoMap = [];
            foreach ($data['photos'] as $photo) {
                Permissions::check(! isset($photoMap[$photo['id'] ?? '']), 'ID foto duplikat.');
                $photoMap[$photo['id']] = (string) Str::uuid();
                Permissions::check((bool) preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $photo['dataUrl'] ?? '', $m), 'Format foto tidak valid.');
                $bytes = base64_decode($m[2], true);
                Permissions::check($bytes !== false && @getimagesizefromstring($bytes) !== false, 'Isi foto tidak valid.');
            }
            foreach ($map as $key => [$kind, $idKey]) {
                $seen = [];
                foreach ($data[$key] as $row) {
                    Permissions::check(is_array($row) && ! empty($row[$idKey]) && strlen((string) $row[$idKey]) <= 191, "ID $key tidak valid.");
                    Permissions::check(! isset($seen[$row[$idKey]]), "ID $key duplikat.");
                    $seen[$row[$idKey]] = true;
                    if (in_array($kind, ['entry', 'spk', 'apd'])) {
                        Validator::make($row, ['tanggal' => 'required|date_format:Y-m-d', 'createdBy' => 'required|string', 'createdAt' => 'required|string'])->validate();
                    }
                    if ($kind === 'entry') {
                        Validator::make($row, ['tab' => 'required|in:filling,press', 'reportId' => 'required|string', 'operator' => 'required|string', 'produk' => 'required|string', 'botol' => 'required|string', 'totalQty' => 'required|numeric|min:0', 'qtyKardus' => 'required|numeric|min:0', 'qtyBotolPerKardus' => 'required|numeric|min:0', 'updateCount' => 'required|integer|min:0'])->validate();
                    }
                    if ($kind === 'apd') {
                        Validator::make($row, ['operator' => 'required|string', 'scores' => 'required|array', 'totalPoints' => 'required|numeric', 'percentage' => 'required|numeric|min:0|max:100', 'photoFileIds' => 'present|array|max:3'])->validate();
                        foreach ($row['photoFileIds'] ?? [] as $id) {
                            Permissions::check(isset($photoMap[$id]), 'Foto APD tidak ada dalam snapshot.');
                        }
                    }
                    if ($kind === 'spk') {
                        Validator::make($row, ['produk' => 'required|string', 'botol' => 'required|string', 'produksiDus' => 'required|numeric|min:0', 'qtyPerDus' => 'required|numeric|min:0', 'qty' => 'required|numeric|min:0', 'updateCount' => 'required|integer|min:0', 'status' => 'required|string'])->validate();
                    }
                    if ($kind === 'adjustment') {
                        Validator::make($row, ['tanggal' => 'required|date_format:Y-m-d', 'produk' => 'required|string', 'botol' => 'required|string', 'qtyDitutup' => 'required|numeric|min:0', 'qtyBotolPerKardus' => 'required|numeric|min:0', 'createdAt' => 'present|string'])->validate();
                    }
                    if ($kind === 'downtime') {
                        Validator::make($row, ['tanggal' => 'required|date_format:Y-m-d', 'timestamp' => 'required|string', 'downTime' => 'required|numeric'])->validate();
                    }
                    if ($kind === 'audit') {
                        Validator::make($row, ['tab' => 'required|in:filling,press', 'tanggal' => 'required|date_format:Y-m-d', 'produk' => 'required|string', 'botol' => 'required|string', 'batchNo' => 'present|string', 'nextUpdateCount' => 'required|integer|min:1', 'restoredEntryId' => 'present|string'])->validate();
                    }
                }
                $this->line($key.': '.count($data[$key]));
            }
            $this->line('photos: '.count($photoMap));
            if (! $this->option('apply')) {
                $this->info('Validasi selesai. Tidak ada data yang disimpan. Gunakan --apply untuk impor.');

                return self::SUCCESS;
            }
            DB::transaction(function () use ($data, $map, $photoMap, $records) {
                DB::table('production_locks')->where('id', 1)->lockForUpdate()->first();
                Permissions::check(! DB::table('production_records')->exists() && ! DB::table('production_photos')->exists(), 'Impor hanya diizinkan pada database produksi kosong; data yang ada tidak akan ditimpa.');
                foreach (['operator', 'produk', 'botol'] as $category) {
                    foreach ($data['master'][$category] ?? [] as $value) {
                        $records->put('master', hash('sha256', $category.'|'.mb_strtolower(trim($value))), ['category' => $category, 'value' => trim($value)]);
                    }
                }
                $records->put('settings', 'kpi', $data['settings']);
                foreach ($data['users'] as $user) {
                    $username = mb_strtolower(trim($user['username']));
                    if (DB::table('production_users')->where('username', $username)->exists()) {
                        continue;
                    }
                    DB::table('production_users')->insert(['username' => $username, 'name' => $user['name'], 'role' => $user['role'], 'active' => $user['active'] ?? true, 'permissions' => json_encode(Permissions::normalize($user['role'], $user['permissions'] ?? [])), 'password' => Hash::make(Str::random(64)), 'created_at' => now()]);
                }
                foreach ($data['photos'] as $photo) {
                    preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $photo['dataUrl'], $m);
                    DB::table('production_photos')->insert(['id' => $photoMap[$photo['id']], 'owner' => $photo['owner'], 'mime' => $m[1], 'content' => $m[2], 'created_at' => now()]);
                }
                foreach ($map as $key => [$kind, $idKey]) {
                    foreach ($data[$key] as $row) {
                        if ($kind === 'apd') {
                            $row['photoFileIds'] = array_map(fn ($id) => $photoMap[$id], $row['photoFileIds'] ?? []);
                            $row['photoFileId'] = count($row['photoFileIds']) > 1 ? json_encode($row['photoFileIds']) : ($row['photoFileIds'][0] ?? '');
                        }
                        if ($kind === 'audit') {
                            $row['key'] = $row['tab'].'|'.$row['tanggal'].'|'.($row['batchNo'] ?: mb_strtolower($row['produk'].'|'.$row['botol']));
                        }
                        $records->put($kind, (string) $row[$idKey], $row);
                    }
                }
            });
            $this->info('Impor selesai. Reset password akun hasil impor melalui Super User; sesi/password lama tidak dipindahkan.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
