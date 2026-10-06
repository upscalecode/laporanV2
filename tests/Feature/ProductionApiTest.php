<?php

namespace Tests\Feature;

use App\Services\Accounts;
use App\Services\ApdService;
use App\Services\Permissions;
use App\Services\ProductionService;
use App\Services\Records;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        app(Accounts::class)->add(['username' => 'admin', 'name' => 'Admin', 'password' => 'test-password', 'role' => 'superuser']);
        $this->token = $this->postJson('/api/production', ['action' => 'login', 'username' => 'admin', 'password' => 'test-password'])->json('token');
        foreach (['operator' => 'Operator 1', 'produk' => 'Produk 1', 'botol' => 'Botol 1'] as $category => $value) {
            app(ProductionService::class)->masterWrite($category, $value);
        }
    }

    private function api(string $action, array $payload = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/production', ['action' => $action] + $payload);
    }

    private function spk(array $overrides = []): array
    {
        return $this->api('spk.create', ['data' => $overrides + ['produk' => 'Produk 1', 'botol' => 'Botol 1', 'produksiDus' => 10, 'qtyPerDus' => 12]])->assertJson(['ok' => true])->json('spk');
    }

    private function entry(string $batch, string $line = 'filling', array $extra = []): array
    {
        return $extra + ['line' => $line, 'batchNo' => $batch, 'tanggal' => now()->toDateString(), 'operator' => 'Operator 1', 'produk' => 'Produk 1', 'botol' => 'Botol 1', 'qtyKardus' => 5, 'qtyBotolPerKardus' => 12, 'qtyBotolPecah' => 0, 'qtyKardusBasah' => 0];
    }

    public function test_pages_are_rendered_by_laravel(): void
    {
        foreach (['index', 'login', 'filling', 'press', 'spk', 'apd', 'laporan', 'setting'] as $page) {
            $this->get('/'.$page.'.html')->assertOk()->assertSee('<!doctype html>', false)->assertDontSee('@verbatim', false);
        }
    }

    public function test_login_hashes_tokens_logout_revokes_and_get_cannot_write(): void
    {
        $this->assertDatabaseHas('production_tokens', ['hash' => hash('sha256', $this->token)]);
        $this->assertDatabaseMissing('production_tokens', ['hash' => $this->token]);
        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/production?action=entry.delete&id=anything')->assertJson(['ok' => false]);
        $this->api('logout')->assertJson(['ok' => true]);
        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/production?action=appdata')->assertJson(['ok' => false]);
    }

    public function test_login_throttling_survives_failed_request_transaction(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/production', ['action' => 'login', 'username' => 'unknown', 'password' => 'wrong'])->assertJson(['ok' => false]);
        }
        $this->postJson('/api/production', ['action' => 'login', 'username' => 'unknown', 'password' => 'wrong'])->assertJsonPath('message', 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.');
    }

    public function test_capacity_balance_and_delete_dependency(): void
    {
        $batch = $this->spk()['batchNo'];
        $fill = $this->api('entry.create', ['data' => $this->entry($batch)])->assertJson(['ok' => true])->json('entry');
        $this->api('entry.create', ['data' => $this->entry($batch, 'filling', ['qtyKardus' => 6])])->assertJson(['ok' => false]);
        $this->api('entry.create', ['data' => $this->entry($batch, 'press', ['qtyKardus' => 6])])->assertJson(['ok' => false]);
        $press = $this->api('entry.create', ['data' => $this->entry($batch, 'press', ['qtyKardus' => 2])])->assertJson(['ok' => true])->json('entry');
        $this->assertEquals(36, app(ProductionService::class)->model()['remainders'][0]['sisaQty']);
        $this->api('entry.delete', ['id' => $fill['id']])->assertJson(['ok' => false]);
        $this->api('entry.delete', ['id' => $press['id']])->assertJson(['ok' => true]);
        $this->api('entry.delete', ['id' => $fill['id']])->assertJson(['ok' => true]);
        $restored = $this->api('entry.create', ['data' => $this->entry($batch)])->assertJson(['ok' => true])->json('entry');
        $this->assertEquals(1, $restored['updateCount']);
        $this->assertNotEmpty(app(Records::class)->all('audit')[1]['restoredEntryId']);
    }

    public function test_press_cannot_consume_future_or_other_batch(): void
    {
        $batch = $this->spk()['batchNo'];
        $other = $this->spk()['batchNo'];
        $this->api('entry.create', ['data' => $this->entry($batch, 'filling', ['tanggal' => now()->addDay()->toDateString()])])->assertJson(['ok' => true]);
        $this->api('entry.create', ['data' => $this->entry($batch, 'press')])->assertJson(['ok' => false]);
        $this->api('entry.create', ['data' => $this->entry($other, 'press', ['tanggal' => now()->addDays(2)->toDateString()])])->assertJson(['ok' => false]);
    }

    public function test_batch_failure_rolls_back_and_request_id_is_idempotent(): void
    {
        $batch = $this->spk()['batchNo'];
        $payload = $this->entry($batch, 'filling', ['clientRequestId' => 'request-1234567890']);
        $this->api('entry.batchCreate', ['data' => [$payload, $this->entry($batch, 'press', ['qtyKardus' => 6])]])->assertJson(['ok' => false]);
        $this->assertCount(0, app(Records::class)->all('entry'));
        $this->api('entry.batchCreate', ['data' => [$payload]])->assertJson(['ok' => true]);
        $this->api('entry.batchCreate', ['data' => [$payload]])->assertJsonPath('duplicateIds.0', 'request-1234567890');
        $this->assertCount(1, app(Records::class)->all('entry'));
    }

    public function test_spk_identity_sync_preserves_other_batches_and_audits(): void
    {
        $batch = $this->spk()['batchNo'];
        $other = $this->spk()['batchNo'];
        $fill = $this->api('entry.create', ['data' => $this->entry($batch)])->json('entry');
        $unrelated = $this->api('entry.create', ['data' => $this->entry($other)])->json('entry');
        app(ProductionService::class)->masterWrite('produk', 'Produk baru');
        $data = ['produk' => 'Produk baru', 'botol' => 'Botol 1', 'produksiDus' => 10, 'qtyPerDus' => 12];
        $this->api('spk.update', ['batchNo' => $batch, 'data' => $data])->assertJson(['ok' => true]);
        $this->assertSame('Produk baru', app(Records::class)->get('entry', $fill['id'])['produk']);
        $this->assertSame(1, app(Records::class)->get('entry', $fill['id'])['updateCount']);
        $this->assertSame('Produk 1', app(Records::class)->get('entry', $unrelated['id'])['produk']);
        $data['produksiDus'] = 20;
        $this->api('spk.update', ['batchNo' => $batch, 'data' => $data])->assertJson(['ok' => false]);
        $this->api('spk.delete', ['batchNo' => $batch])->assertJson(['ok' => false]);
    }

    public function test_press_batch_close_accepts_browser_payload(): void
    {
        $batch = $this->spk()['batchNo'];
        $this->api('entry.create', ['data' => $this->entry($batch)])->assertJson(['ok' => true]);
        $payload = ['rows' => [['produk' => 'Produk 1', 'botol' => 'Botol 1', 'qtyBotolPerKardus' => 12, 'targetBatchNo' => $batch, 'targetTanggalAsal' => now()->toDateString()]], 'alasan' => 'Ditutup karena rusak'];
        $this->api('press.adjustment.closeBatch', ['data' => $payload])->assertJson(['ok' => true, 'remainders' => []])->assertJsonPath('adjustments.0.qtyDitutup', 60);
        $this->api('entry.create', ['data' => $this->entry($batch, 'press')])->assertJson(['ok' => false]);
    }

    public function test_readonly_permissions_and_report_parent_are_enforced(): void
    {
        app(Accounts::class)->add(['username' => 'reader', 'name' => 'Reader', 'password' => 'test-password', 'role' => 'user']);
        $permissions = Permissions::normalize('user', ['levels' => ['filling' => 'read', 'reports' => 'none', 'kpiFilling' => 'admin']]);
        DB::table('production_users')->where('username', 'reader')->update(['permissions' => json_encode($permissions)]);
        $this->token = $this->postJson('/api/production', ['action' => 'login', 'username' => 'reader', 'password' => 'test-password'])->json('token');
        $this->api('master.add', ['category' => 'produk', 'value' => 'Forbidden'])->assertJson(['ok' => false]);
        $this->api('user.add', ['username' => 'hack', 'password' => 'test-password', 'role' => 'superuser', 'name' => 'Hack'])->assertJson(['ok' => false]);
        $this->api('entry.create', ['data' => $this->entry('01-06102026')])->assertJson(['ok' => false]);
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/production?action=appdata')->assertJson(['ok' => true]);
        $this->assertFalse($response->json('user.permissions.accessKpiFillingReport'));
        $this->assertSame([], $response->json('users'));
    }

    public function test_apd_scoring_duplicates_and_photo_authorization(): void
    {
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
        $id = $this->api('apd.photo.upload', ['dataUrl' => $image])->assertJson(['ok' => true])->json('photoFileId');
        $scores = array_fill_keys(array_keys(ApdService::WEIGHTS), 3);
        $row = ['clientRequestId' => 'apd-request-12345678', 'tanggal' => now()->toDateString(), 'operator' => 'Operator 1', 'scores' => $scores, 'photoFileIds' => [$id]];
        $this->api('apd.batchCreate', ['data' => [$row]])->assertJson(['ok' => true])->assertJsonPath('entries.0.percentage', 100);
        $this->api('apd.photo.discard', ['photoFileId' => $id])->assertJson(['ok' => false]);
        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/production?action=apd.photo.get&id=apd-request-12345678')->assertJson(['ok' => true])->assertJsonCount(1, 'dataUrls');
        $row['clientRequestId'] = 'different-apd-12345678';
        $this->api('apd.batchCreate', ['data' => [$row]])->assertJson(['ok' => false]);
        $this->api('apd.delete', ['id' => 'apd-request-12345678'])->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('production_photos', ['id' => $id]);
    }

    public function test_downtime_and_kpi_settings(): void
    {
        $this->api('downtime.upsert', ['data' => ['arrivalTimestamp' => now()->setTime(9, 0)->toISOString(), 'alasan' => 'Menunggu QC']])->assertJson(['ok' => true])->assertJsonPath('entry.downTime', 30);
        $this->api('settings.kpiTargets.set', ['fillingValue' => 200000, 'pressValue' => 90000])->assertJson(['ok' => true])->assertJsonPath('settings.kpiFillingOutputTargetMonthly', 200000);
        $this->api('settings.kpiTargets.set', ['fillingValue' => 0, 'pressValue' => 90000])->assertJson(['ok' => false]);
    }

    public function test_import_typo_matching_does_not_merge_distinct_products(): void
    {
        $service = app(ProductionService::class);
        $this->assertSame('Chanel', $service->approximateMaster(['Chanel'], 'Channel'));
        $this->assertSame('Jolibliss Scandalove', $service->approximateMaster(['Jolibliss Scandalove'], 'Jolibliss Scandalouse'));
        $this->assertSame('', $service->approximateMaster(['Jolibliss Wild Berry'], 'Jolibliss Pink Berry'));
        $this->assertSame('', $service->approximateMaster(['Botol 30 ml'], 'Botol 50 ml'));
    }

    public function test_user_cannot_modify_other_users_work_or_preview_photo(): void
    {
        $batch = $this->spk()['batchNo'];
        $fill = $this->api('entry.create', ['data' => $this->entry($batch)])->json('entry');
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
        $photo = $this->api('apd.photo.upload', ['dataUrl' => $image])->json('photoFileId');
        app(Accounts::class)->add(['username' => 'operator', 'name' => 'Operator', 'password' => 'test-password', 'role' => 'user']);
        $this->token = $this->postJson('/api/production', ['action' => 'login', 'username' => 'operator', 'password' => 'test-password'])->json('token');
        $this->api('entry.delete', ['id' => $fill['id']])->assertJson(['ok' => false]);
        $this->api('entry.update', ['id' => $fill['id'], 'data' => $this->entry($batch)])->assertJson(['ok' => false]);
        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/production?action=apd.photo.preview&photoFileId='.$photo)->assertJson(['ok' => false]);
        $this->api('apd.photo.discard', ['photoFileId' => $photo])->assertJson(['ok' => false]);
    }

    public function test_reset_password_revokes_sessions(): void
    {
        app(Accounts::class)->add(['username' => 'operator', 'name' => 'Operator', 'password' => 'test-password', 'role' => 'user']);
        $operatorToken = $this->postJson('/api/production', ['action' => 'login', 'username' => 'operator', 'password' => 'test-password'])->json('token');
        $this->api('user.password.reset', ['username' => 'operator', 'password' => 'new-test-password'])->assertJson(['ok' => true]);
        $this->withHeader('Authorization', 'Bearer '.$operatorToken)->getJson('/api/production?action=bootstrap')->assertJson(['ok' => false]);
        $this->postJson('/api/production', ['action' => 'login', 'username' => 'operator', 'password' => 'new-test-password'])->assertJson(['ok' => true]);
    }

    public function test_snapshot_import_is_dry_by_default_preserves_photos_and_refuses_overwrite(): void
    {
        $batch = $this->spk()['batchNo'];
        $this->api('entry.create', ['data' => $this->entry($batch)])->assertJson(['ok' => true]);
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
        $photo = $this->api('apd.photo.upload', ['dataUrl' => $image])->json('photoFileId');
        $row = ['clientRequestId' => 'apd-snapshot-12345678', 'tanggal' => now()->toDateString(), 'operator' => 'Operator 1', 'scores' => array_fill_keys(array_keys(ApdService::WEIGHTS), 3), 'photoFileIds' => [$photo]];
        $this->api('apd.batchCreate', ['data' => [$row]])->assertJson(['ok' => true]);
        $records = app(Records::class);
        $snapshot = ['schemaVersion' => 1, 'master' => app(ProductionService::class)->master(), 'settings' => ['kpiFillingOutputTargetMonthly' => 150000, 'kpiPressOutputTargetMonthly' => 70000], 'users' => app(Accounts::class)->all(), 'entries' => $records->all('entry'), 'spkEntries' => $records->all('spk'), 'apdEntries' => $records->all('apd'), 'adjustments' => [], 'downtimeEntries' => [], 'audits' => [], 'photos' => [['id' => $photo, 'owner' => 'admin', 'dataUrl' => $image]]];
        $file = tempnam(sys_get_temp_dir(), 'production-test-');
        file_put_contents($file, json_encode($snapshot));
        try {
            $this->artisan('production:import', ['file' => $file])->assertSuccessful();
            $this->artisan('production:import', ['file' => $file, '--apply' => true])->assertFailed();
            DB::table('production_records')->delete();
            DB::table('production_photos')->delete();
            $this->artisan('production:import', ['file' => $file])->assertSuccessful();
            $this->assertDatabaseCount('production_records', 0);
            $this->artisan('production:import', ['file' => $file, '--apply' => true])->assertSuccessful();
            $this->assertCount(1, $records->all('entry'));
            $this->assertEquals(60, app(ProductionService::class)->model()['remainders'][0]['sisaQty']);
            $newPhoto = $records->all('apd')[0]['photoFileIds'][0];
            $this->assertNotSame($photo, $newPhoto);
            $this->assertDatabaseHas('production_photos', ['id' => $newPhoto]);
            $this->postJson('/api/production', ['action' => 'login', 'username' => 'admin', 'password' => 'test-password'])->assertJson(['ok' => true]);
        } finally {
            unlink($file);
        }
    }
}
