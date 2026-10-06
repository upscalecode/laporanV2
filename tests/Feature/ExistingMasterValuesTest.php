<?php

namespace Tests\Feature;

use App\Services\ProductionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExistingMasterValuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_master_values_are_imported_in_order_without_overwriting_or_duplicating(): void
    {
        Schema::create('master_values', function (Blueprint $table) {
            $table->string('category');
            $table->string('value');
            $table->integer('position');
        });
        DB::table('master_values')->insert([
            ['category' => 'produk', 'value' => ' Produk B ', 'position' => 2],
            ['category' => 'produk', 'value' => 'Produk A', 'position' => 1],
            ['category' => 'operator', 'value' => 'OPERATOR', 'position' => 1],
            ['category' => 'botol', 'value' => 'Botol 1', 'position' => 1],
            ['category' => 'botol', 'value' => '  ', 'position' => 2],
        ]);
        $service = app(ProductionService::class);
        $service->masterWrite('operator', 'Operator');
        $migration = require database_path('migrations/2026_10_06_000002_import_existing_master_values.php');
        $migration->up();
        $migration->up();

        $this->assertSame([
            'operator' => ['Operator'],
            'produk' => ['Produk A', 'Produk B'],
            'botol' => ['Botol 1'],
            'botolpecah' => ['Botol 1'],
        ], $service->master());
        $this->assertSame(4, DB::table('production_records')->where('kind', 'master')->count());
        $this->assertSame(5, DB::table('master_values')->count());

        $service->masterWrite('produk', 'Produk A', true);
        $this->assertSame(['Produk B'], $service->master()['produk']);
    }
}
