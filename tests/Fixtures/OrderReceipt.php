<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Nvl\MailNotificationBridge\Concerns\SubmitsMailNotifications;

/** Host-owned mail with bilingual content and a real attachment. */
class OrderReceipt extends Mailable
{
    use SubmitsMailNotifications;

    public function build(): static
    {
        return $this->to('recipient@example.test')->cc('copy@example.test')->bcc('private@example.test')
            ->subject(app()->getLocale() === 'bg' ? 'Поръчка 42' : 'Order 42')
            ->view('receipt', ['orderId' => '42'])
            ->attachData('%PDF-1.4 bridge fixture', 'voucher.pdf', ['mime' => 'application/pdf']);
    }
}
