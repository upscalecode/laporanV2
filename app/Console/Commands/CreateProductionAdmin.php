<?php

namespace App\Console\Commands;

use App\Services\Accounts;
use Illuminate\Console\Command;

class CreateProductionAdmin extends Command
{
    protected $signature = 'production:admin {username} {--name=Administrator}';

    protected $description = 'Buat Super User produksi dengan password interaktif';

    public function handle(Accounts $accounts): int
    {
        $password = $this->secret('Password baru (minimal 8 karakter)');
        if (! $password || $password !== $this->secret('Ulangi password')) {
            $this->error('Password tidak sama.');

            return self::FAILURE;
        }
        $accounts->add(['username' => $this->argument('username'), 'name' => $this->option('name'), 'password' => $password, 'role' => 'superuser']);
        $this->info('Super User berhasil dibuat.');

        return self::SUCCESS;
    }
}
