<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks\Http;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvl\MailNotificationBridge\Webhooks\ReceiptConflict;
use Nvl\MailNotificationBridge\Webhooks\ReceiptStore;
use Nvl\Support\Config\PackageStorage;
use Throwable;

class ReceiveWebhook
{
    public function __invoke(Request $request, ReceiptStore $receipts, DatabaseManager $database): JsonResponse
    {
        if ($database->connection(PackageStorage::connection('mail-notification-bridge'))->transactionLevel() !== 0) {
            return response()->json(['message' => 'Webhook receipt could not be committed.'], 503);
        }
        $connection = $request->attributes->get('nvl-mail-notification-bridge.webhook.connection');
        $eventId = $request->attributes->get('nvl-mail-notification-bridge.webhook.id');
        if (! is_string($connection) || ! is_string($eventId)) {
            return response()->json(['message' => 'Invalid webhook authentication.'], 401);
        }
        try {
            $created = $receipts->accept(
                $connection,
                $eventId,
                $request->getContent(),
            );
        } catch (ReceiptConflict) {
            return response()->json(['message' => 'Webhook event ID already has a different payload.'], 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Webhook receipt could not be committed.'], 503);
        }

        return response()->json(['accepted' => true, 'duplicate' => ! $created], $created ? 202 : 200);
    }
}
