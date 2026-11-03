<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotificationBridge\Webhooks\ReceiptStore;

class RecoverWebhooks extends Command
{
    protected $signature = 'nvl:mail-notification-bridge:webhooks:recover {--limit=100 : Maximum receipts to enqueue (1-1000)}';

    protected $description = 'Queue pending webhook receipts and recover expired processing leases';

    public function handle(ReceiptStore $receipts): int
    {
        if (! $receipts->usesDurableQueue()) {
            $this->error('Configure a durable asynchronous webhook queue connection before recovering receipts.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        $this->info('Queued '.$receipts->recover($limit).' webhook receipt(s).');

        return self::SUCCESS;
    }
}
