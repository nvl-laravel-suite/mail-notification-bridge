<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Mail;

use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Converts a rendered host Mailable at Laravel's real MIME boundary without delivery.
 *
 * @internal Use SubmitsMailNotifications for the public host integration.
 */
class MailablePreparation
{
    private const int MAX_FILE_BYTES = 10 * 1024 * 1024;

    private const int MAX_TOTAL_BYTES = 20 * 1024 * 1024;

    public function __construct(private Factory $views) {}

    /**
     * Clone, build and render once, retaining the complete supported envelope.
     *
     * Supported options: type, category, language, data, metadata, tags,
     * external_reference, references, send_at and css. Language also selects
     * Laravel's render locale. Explicit tags/metadata replace Mailable values.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function prepare(Mailable $mailable, array $options = []): array
    {
        $allowed = ['type', 'category', 'language', 'data', 'metadata', 'tags', 'external_reference', 'references', 'send_at', 'css'];
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw ValidationException::withMessages(['options' => 'Only managed message attributes may be supplied as preparation options.']);
        }
        if (array_key_exists('language', $options) && $options['language'] !== null && ! is_string($options['language'])) {
            throw ValidationException::withMessages(['language' => 'The language must be a locale string.']);
        }
        $copy = clone $mailable;
        if (method_exists($copy, 'withoutMailTracking')) {
            $copy->withoutMailTracking();
        }
        if (is_string($options['language'] ?? null)) {
            $copy->locale($options['language']);
        }

        $transport = new ArrayTransport;
        $mailer = new Mailer('managed-preparation', $this->views, $transport);
        $mailer->alwaysFrom('managed-preparation@example.invalid');
        $copy->send($mailer);
        $captured = $transport->messages()->sole();
        if (! $captured instanceof SentMessage) {
            throw ValidationException::withMessages(['mailable' => 'Preparation did not capture a message.']);
        }
        $email = $captured->getOriginalMessage();
        if (! $email instanceof Email) {
            throw ValidationException::withMessages(['mailable' => 'Preparation requires a standard Symfony Email.']);
        }
        $payload = [
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
        ];
        if ($copy->locale !== null) {
            $payload['language'] = mb_strtolower(str_replace('_', '-', $copy->locale));
        }
        $payload = array_replace($payload, $this->headers($email));
        $total = 0;
        $attachments = [];
        $inlineAssets = [];
        foreach ($email->getAttachments() as $index => $part) {
            $bytes = $this->attachmentBytes($part);
            $total += strlen($bytes);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw ValidationException::withMessages(['attachments' => 'Attachments must not exceed 20 MiB in total.']);
            }
            $disposition = $part->getDisposition();
            if (! in_array($disposition, ['inline', 'attachment'], true)) {
                throw ValidationException::withMessages(['attachments' => 'Unsupported attachment disposition.']);
            }
            $descriptor = ['content_base64' => base64_encode($bytes), 'name' => $part->getFilename() ?? $part->getName() ?? 'attachment', 'disposition' => $disposition];
            if ($disposition === 'inline' || $part->hasContentId()) {
                $contentId = $part->getContentId();
                if (preg_match('/\A[0-9a-f]{32}@symfony\z/', $contentId)) {
                    $stableId = hash('sha256', $index.':'.$bytes).'@managed';
                    $html = $payload['html'];
                    if (is_string($html)) {
                        $payload['html'] = str_replace('cid:'.$contentId, 'cid:'.$stableId, $html);
                    }
                    $contentId = $stableId;
                }
                $descriptor['content_id'] = $contentId;
            }
            if ($disposition === 'inline') {
                $inlineAssets[] = $descriptor;
            } else {
                $attachments[] = $descriptor;
            }
        }

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }
        if ($inlineAssets !== []) {
            $payload['inline_assets'] = $inlineAssets;
        }

        $payload = array_replace($payload, $options);
        if (is_string($payload['language'] ?? null)) {
            $payload['language'] = mb_strtolower(str_replace('_', '-', $payload['language']));
        }

        return $payload;
    }

    /** @param array<Address> $addresses
     * @return list<array{email:string,name:?string}>
     */
    private function addresses(array $addresses): array
    {
        return array_map(static fn (Address $address): array => ['email' => $address->getAddress(), 'name' => $address->getName() !== '' ? $address->getName() : null], array_values($addresses));
    }

    /** @return array{tags?:list<string>,metadata?:array<string,string>} */
    private function headers(Email $email): array
    {
        $tags = [];
        $metadata = [];
        foreach ($email->getHeaders()->all() as $header) {
            if (! $header instanceof HeaderInterface) {
                throw ValidationException::withMessages(['headers' => 'Unsupported MIME header.']);
            }
            if ($header instanceof TagHeader) {
                $tags[] = $header->getBody();
            } elseif ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getBody();
            } elseif (! in_array(strtolower($header->getName()), ['from', 'reply-to', 'to', 'cc', 'bcc', 'subject'], true)) {
                throw ValidationException::withMessages(['headers' => 'Managed delivery cannot preserve the '.$header->getName().' header.']);
            }
        }
        foreach ([$email->getHtmlCharset(), $email->getTextCharset()] as $charset) {
            if ($charset !== null && strtolower($charset) !== 'utf-8') {
                throw ValidationException::withMessages(['mailable' => 'Managed bodies must use UTF-8.']);
            }
        }

        return ($tags !== [] ? ['tags' => $tags] : []) + ($metadata !== [] ? ['metadata' => $metadata] : []);
    }

    /** Read encoded MIME chunks with an upper bound before allocating raw bytes. */
    private function attachmentBytes(DataPart $part): string
    {
        $encoded = '';
        foreach ($part->bodyToIterable() as $chunk) {
            if (! is_string($chunk)) {
                throw ValidationException::withMessages(['attachments' => 'Unsupported MIME content.']);
            }
            $encoded .= $chunk;
            if (strlen($encoded) > self::MAX_FILE_BYTES * 3 + 1024) {
                throw ValidationException::withMessages(['attachments' => 'Each attachment must be at most 10 MiB.']);
            }
        }
        $encoding = strtolower($part->getPreparedHeaders()->get('Content-Transfer-Encoding')?->getBodyAsString() ?? '8bit');
        $bytes = match ($encoding) {
            'base64' => base64_decode($encoded, true),
            'quoted-printable' => quoted_printable_decode($encoded),
            '8bit', 'binary', '7bit' => $encoded,
            default => false,
        };
        if ($bytes === false || strlen($bytes) > self::MAX_FILE_BYTES) {
            throw ValidationException::withMessages(['attachments' => 'Each attachment must be at most 10 MiB and use a supported encoding.']);
        }

        return $bytes;
    }
}
