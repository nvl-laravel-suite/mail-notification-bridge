<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/** Prepared-content API operations preserving the service response envelope.
 * @api
 */
class MailNotificationsClient
{
    public function __construct(private readonly string $baseUrl, #[\SensitiveParameter] private readonly string $token, private readonly int $connectTimeout = 5, private readonly int $timeout = 30)
    {
        if (trim($baseUrl) === '' || trim($token) === '') {
            throw new InvalidArgumentException('Configure the Mail Notifications API URL and client key.');
        }
        if ($connectTimeout < 1 || $timeout < 1) {
            throw new InvalidArgumentException('HTTP timeouts must be positive seconds.');
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function send(array $message, string $idempotencyKey): array
    {
        return $this->responseMap($this->request()->asJson()->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/messages', $message));
    }

    /** @return array<string, mixed> */
    public function uploadAsset(string $path, ?string $name = null): array
    {
        $stream = @fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('The asset upload file could not be opened.');
        }
        try {
            return $this->responseMap($this->request()->attach('file', $stream, basename($path))
                ->post('/assets', $name === null ? [] : ['name' => $name]));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  array<string, string>  $fields  Flattened PHP names, such as to[0][email].
     * @param  array<string, string>  $files  File field => local path, such as attachments[0][file].
     * @return array<string, mixed>
     */
    public function sendMultipart(array $fields, array $files, string $idempotencyKey): array
    {
        return $this->multipart('/messages', $fields, $files, $idempotencyKey);
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $files
     * @return array<string, mixed>
     */
    private function multipart(string $endpoint, array $fields, array $files, string $idempotencyKey): array
    {
        $streams = [];
        try {
            $request = $this->request()->asMultipart()->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            foreach ($files as $field => $path) {
                $stream = @fopen($path, 'rb');
                if (! is_resource($stream)) {
                    throw new RuntimeException('A message attachment file could not be opened.');
                }
                $streams[] = $stream;
                $request->attach($field, $stream, basename($path));
            }

            return $this->responseMap($request->post($endpoint, $fields));
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function message(string $id): array
    {
        return $this->responseMap($this->request()->get('/messages/'.rawurlencode($id)));
    }

    /** @return array<string, mixed> */
    public function events(string $id, int $page = 1, int $perPage = 50): array
    {
        return $this->responseMap($this->request()->get('/messages/'.rawurlencode($id).'/events', ['page' => $page, 'per_page' => $perPage]));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function messages(array $filters = []): array
    {
        return $this->responseMap($this->request()->get('/messages', $filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function marketingOptOuts(array $filters = []): array
    {
        return $this->responseMap($this->request()->get('/marketing-opt-outs', $filters));
    }

    /** @return array<string, mixed> */
    public function putMarketingOptOut(string $email): array
    {
        return $this->responseMap($this->request()->asJson()->put('/marketing-opt-outs', ['email' => $email]));
    }

    public function deleteMarketingOptOut(string $optOut): void
    {
        $this->successfulResponse($this->request()->delete('/marketing-opt-outs/'.rawurlencode($optOut)));
    }

    /** @return array<string, mixed> */
    public function client(): array
    {
        return $this->responseMap($this->request()->get('/client'));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function updateClient(array $settings): array
    {
        return $this->responseMap($this->request()->asJson()->patch('/client', $settings));
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        return $this->responseMap($this->request()->get('/configuration'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function assets(array $filters = []): array
    {
        return $this->responseMap($this->request()->get('/assets', $filters));
    }

    /** @return array<string, mixed> */
    public function asset(string $asset): array
    {
        return $this->responseMap($this->request()->get('/assets/'.rawurlencode($asset)));
    }

    /** @return array<string, mixed> */
    public function publishAsset(string $asset): array
    {
        return $this->responseMap($this->request()->asJson()->post('/assets/'.rawurlencode($asset).'/publish'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function flags(array $filters = []): array
    {
        return $this->responseMap($this->request()->get('/flags', $filters));
    }

    /** @return array<string, mixed> */
    public function flag(string $flag): array
    {
        return $this->responseMap($this->request()->get('/flags/'.rawurlencode($flag)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createFlag(array $data): array
    {
        return $this->responseMap($this->request()->asJson()->post('/flags', $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateFlag(string $flag, array $data): array
    {
        return $this->responseMap($this->request()->asJson()->patch('/flags/'.rawurlencode($flag), $data));
    }

    /** @return array<string, mixed> */
    public function cancelMessage(string $message): array
    {
        return $this->responseMap($this->request()->asJson()->post('/messages/'.rawurlencode($message).'/cancel'));
    }

    /** @return array<string, mixed> */
    public function retryMessage(string $message): array
    {
        return $this->responseMap($this->request()->asJson()->post('/messages/'.rawurlencode($message).'/retry'));
    }

    /** @return array<string, mixed> */
    public function messageProvider(string $message): array
    {
        return $this->responseMap($this->request()->get('/messages/'.rawurlencode($message).'/provider'));
    }

    public function archiveAsset(string $asset): void
    {
        $this->successfulResponse($this->request()->delete('/assets/'.rawurlencode($asset)));
    }

    public function deleteFlag(string $flag): void
    {
        $this->successfulResponse($this->request()->delete('/flags/'.rawurlencode($flag)));
    }

    public function query(): MessagesQuery
    {
        return new MessagesQuery($this);
    }

    /** @param resource $destination A caller-owned writable stream. */
    public function downloadAsset(string $asset, mixed $destination): void
    {
        $this->download('/assets/'.rawurlencode($asset).'/download', $destination);
    }

    /** @param resource $destination A caller-owned writable stream. */
    public function downloadMessageAttachment(string $message, string $attachment, mixed $destination): void
    {
        $this->download('/messages/'.rawurlencode($message).'/attachments/'.rawurlencode($attachment).'/download', $destination);
    }

    /** @param resource $destination */
    private function download(string $path, mixed $destination): void
    {
        if (! is_resource($destination) || get_resource_type($destination) !== 'stream'
            || ! preg_match('/[waxc+]/', stream_get_meta_data($destination)['mode'])) {
            throw new InvalidArgumentException('Downloads require a writable stream owned by the caller.');
        }
        $response = $this->request()->withOptions(['stream' => true])->get($path);
        $body = $response->toPsrResponse()->getBody();
        try {
            $response->throw();
            if (! $response->successful()) {
                throw new RuntimeException('The download did not return a successful response.');
            }
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($destination, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('The download could not be written to the destination stream.');
                    }
                    $offset += $written;
                }
            }
        } finally {
            $body->close();
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function webhookEndpoints(array $filters = []): array
    {
        return $this->responseMap($this->request()->get('/webhook-endpoints', $filters));
    }

    /**
     * @return array<string, mixed>
     */
    public function webhookEndpoint(string $endpoint): array
    {
        return $this->responseMap($this->request()->get('/webhook-endpoints/'.rawurlencode($endpoint)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createWebhookEndpoint(array $data): array
    {
        return $this->responseMap($this->request()->asJson()->post('/webhook-endpoints', $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateWebhookEndpoint(string $endpoint, array $data): array
    {
        return $this->responseMap($this->request()->asJson()->patch('/webhook-endpoints/'.rawurlencode($endpoint), $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function rotateWebhookSecret(string $endpoint): array
    {
        return $this->responseMap($this->request()->asJson()->post('/webhook-endpoints/'.rawurlencode($endpoint).'/rotate-secret'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function webhookDeliveries(string $endpoint, array $filters = []): array
    {
        return $this->responseMap($this->request()->get('/webhook-endpoints/'.rawurlencode($endpoint).'/deliveries', $filters));
    }

    public function deleteWebhookEndpoint(string $endpoint): void
    {
        $this->successfulResponse($this->request()->delete('/webhook-endpoints/'.rawurlencode($endpoint)));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function keys(array $filters = []): array
    {
        return $this->responseMap($this->request()->get('/keys', $filters));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createKey(array $data): array
    {
        return $this->responseMap($this->request()->asJson()->post('/keys', $data));
    }

    public function revokeKey(string $key): void
    {
        $this->successfulResponse($this->request()->delete('/keys/'.rawurlencode($key)));
    }

    /** Validate the remote JSON envelope before exposing it to callers.
     * @return array<string, mixed>
     */
    private function responseMap(Response $response): array
    {
        $this->successfulResponse($response);
        $value = $response->json();
        if (! $response->object() instanceof stdClass || ! is_array($value)) {
            throw new RuntimeException('The Mail Notifications API did not return a JSON object.');
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException('The Mail Notifications API did not return a JSON object.');
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /** Preserve HTTP errors and reject other non-success outcomes without replay. */
    private function successfulResponse(Response $response): Response
    {
        $response->throw();
        if (! $response->successful()) {
            throw new RuntimeException('The Mail Notifications API did not return a successful response.');
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))->withToken($this->token)->acceptJson()
            ->connectTimeout($this->connectTimeout)->timeout($this->timeout)->withOptions(['allow_redirects' => false]);
    }
}
