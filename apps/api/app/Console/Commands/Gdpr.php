<?php

namespace App\Console\Commands;

use App\Domain\Privacy\DataRequest;
use App\Domain\Security\Audit;
use Illuminate\Console\Command;

/**
 * A person's GDPR request, by e-mail address. `export` prints JSON (redirect it into a file and
 * send it to them); `delete` shows what would go and changes nothing until --apply is given.
 */
class Gdpr extends Command
{
    protected $signature = 'factory:gdpr {action : export|delete} {email} {--apply : really delete}';

    protected $description = 'Export or erase everything held about one e-mail address (GDPR Art. 15, 17, 20)';

    public function handle(DataRequest $requests): int
    {
        $email = (string) $this->argument('email');
        try {
            if ($this->argument('action') === 'export') {
                $this->line(json_encode($requests->export($email), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                Audit::system('gdpr.export', null, ['email_sha256' => hash('sha256', strtolower(trim($email)))]);

                return self::SUCCESS;
            }
            if ($this->argument('action') !== 'delete') {
                $this->error('Action is export or delete.');

                return self::INVALID;
            }
            $apply = (bool) $this->option('apply');
            foreach ($requests->delete($email, $apply) as $what => $n) {
                $this->line(str_pad($what, 26).' '.$n);
            }
            $this->info($apply ? 'Done.' : 'Nothing changed. Run again with --apply to delete.');

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
