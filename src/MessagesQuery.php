<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Nvl\MailNotificationBridge\Concerns\HasMailNotifications;
use Throwable;

/** Immutable client-scoped delivery history filters and bounded pagination.
 * @api
 */
final readonly class MessagesQuery
{
    /** @param array<string, mixed> $filters */
    public function __construct(private MailNotificationsClient $client, private array $filters = []) {}

    public function forReference(string $type, string|int $id): self
    {
        $id = (string) $id;
        $this->validateReference($type, $id);

        return $this->with(['reference_type' => $type, 'reference_id' => $id], ['reference_ids']);
    }

    /** @param array<array-key, int|string> $ids */
    public function forReferences(string $type, array $ids): self
    {
        $ids = array_map($this->normalizeReferenceId(...), array_values($ids));
        $this->validateList($ids);
        foreach ($ids as $id) {
            $this->validateReference($type, $id);
        }

        return $this->with(['reference_type' => $type, 'reference_ids' => $ids], ['reference_id']);
    }

    public function forModel(Model $model): self
    {
        if (! in_array(HasMailNotifications::class, class_uses_recursive($model), true) || ! method_exists($model, 'mailNotificationReference')) {
            throw new InvalidArgumentException('The model must use HasMailNotifications.');
        }
        $reference = $model->mailNotificationReference();

        if (! is_array($reference) || ! is_string($reference['type'] ?? null) || (! is_string($reference['id'] ?? null) && ! is_int($reference['id'] ?? null))) {
            throw new InvalidArgumentException('The model returned an invalid mail reference.');
        }

        return $this->forReference($reference['type'], $reference['id']);
    }

    public function forRecipient(string $email): self
    {
        return $this->with(['recipient' => $email]);
    }

    public function status(string $status): self
    {
        return $this->with(['status' => $status], ['statuses']);
    }

    public function type(string $type): self
    {
        return $this->with(['type' => $type]);
    }

    public function category(string $category): self
    {
        return $this->with(['category' => $category]);
    }

    public function language(string $language): self
    {
        return $this->with(['language' => $language]);
    }

    public function tag(string $tag): self
    {
        return $this->with(['tag' => $tag]);
    }

    /** @param array<array-key, string> $statuses */
    public function statuses(array $statuses): self
    {
        $this->validateList($statuses);

        return $this->with(['statuses' => array_values($statuses)], ['status']);
    }

    /** @param array<array-key, string> $ids */
    public function ids(array $ids): self
    {
        $this->validateList($ids);

        return $this->with(['ids' => array_values($ids)]);
    }

    public function createdBetween(DateTimeInterface|string $from, DateTimeInterface|string $to): self
    {
        try {
            $from = $this->parseDate($from);
            $to = $this->parseDate($to);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Supply valid date bounds.', previous: $exception);
        }
        if ($from >= $to) {
            throw new InvalidArgumentException('The start date must precede the exclusive end date.');
        }

        return $this->with(['created_from' => $this->formatDate($from),
            'created_to' => $this->formatDate($to)]);
    }

    /** @return array<string, mixed> */
    public function paginate(int $page = 1, int $perPage = 25): array
    {
        $this->validatePagination($page, $perPage);

        return $this->client->messages([...$this->filters, 'page' => $page, 'per_page' => $perPage]);
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    public function lazyAll(int $perPage = 100, int $maxPages = 10000): LazyCollection
    {
        $this->validatePagination($maxPages, $perPage);

        return LazyCollection::make(function () use ($perPage, $maxPages) {
            for ($page = 1; $page <= $maxPages; $page++) {
                $response = $this->paginate($page, $perPage);
                $rows = $response['data'] ?? [];
                if (! is_array($rows) || $rows === []) {
                    return;
                }
                foreach ($rows as $row) {
                    yield $row;
                }
                $meta = $response['meta'] ?? null;
                $currentPage = is_array($meta) ? ($meta['current_page'] ?? null) : null;
                $lastPage = is_array($meta) ? ($meta['last_page'] ?? null) : null;
                if (! is_int($currentPage) || ! is_int($lastPage) || $currentPage !== $page || $lastPage <= $page) {
                    return;
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $remove
     */
    private function with(array $filters, array $remove = []): self
    {
        return new self($this->client, [...array_diff_key($this->filters, array_flip($remove)), ...$filters]);
    }

    private function normalizeReferenceId(mixed $id): string
    {
        if (! is_int($id) && ! is_string($id)) {
            throw new InvalidArgumentException('Reference IDs must be strings or integers.');
        }

        return (string) $id;
    }

    private function validateReference(string $type, string $id): void
    {
        if (! preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $type)
            || mb_strlen($id) > 191 || ! preg_match('/\A[^\s\x00-\x1f\x7f](?:[^\x00-\x1f\x7f]*[^\s\x00-\x1f\x7f])?\z/uD', $id)) {
            throw new InvalidArgumentException('Use a stable reference alias and a non-empty reference ID.');
        }
    }

    private function parseDate(DateTimeInterface|string $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('Supply a valid calendar date.');
        }

        return $date;
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format($date->format('u') === '000000' ? DATE_ATOM : 'Y-m-d\TH:i:s.uP');
    }

    private function validatePagination(int $page, int $perPage): void
    {
        if ($page < 1 || $page > 10000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Use pages 1–10000 and a page size of 1–100.');
        }
    }

    /** @param array<array-key, mixed> $values */
    private function validateList(array $values): void
    {
        if ($values === [] || count($values) > 100) {
            throw new InvalidArgumentException('Supply between 1 and 100 values.');
        }
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Filter values must be non-empty strings.');
            }
        }
    }
}
