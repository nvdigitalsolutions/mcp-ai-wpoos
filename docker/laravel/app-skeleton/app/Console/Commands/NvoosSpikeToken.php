<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class NvoosSpikeToken extends Command
{
    protected $signature = 'nvoos:spike-token {--email=spike@example.com}';

    protected $description = 'Create (or reuse) a spike user and print a Sanctum bearer token.';

    public function handle(): int
    {
        $email = (string) $this->option('email');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Spike User',
                'password' => bcrypt('password'),
            ],
        );

        $token = $user->createToken('spike')->plainTextToken;

        $this->line($token);

        return self::SUCCESS;
    }
}
