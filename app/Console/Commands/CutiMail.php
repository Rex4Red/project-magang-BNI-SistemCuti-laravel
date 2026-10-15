<?php

namespace App\Console\Commands;

use App\Domain\MailOutbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cuti:mail {--limit=20 : Maximum messages processed per invocation}')]
#[Description('Process a bounded batch of due cuti email notifications')]
class CutiMail extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MailOutbox $outbox): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $count = 0;
        $deadline = microtime(true) + 45;
        while ($count < $limit && microtime(true) < $deadline && $outbox->processOne()) {
            $count++;
        }
        $this->info($count.' notifikasi diproses.');

        return self::SUCCESS;
    }
}
