<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Nvl\MailNotificationBridge\MailNotificationsClient;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('message queries compose immutable filters and preserve pagination envelopes', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 'a']], 'meta' => ['current_page' => 2, 'last_page' => 2]])]);
    $base = (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()->forReference('order', 42);
    $filtered = $base->forRecipient('customer@example.com')->statuses(['delivered', 'failed'])->ids(['a', 'b'])
        ->createdBetween(new DateTimeImmutable('2026-11-01T13:00:00+02:00'), new DateTimeImmutable('2026-11-02T13:00:00+02:00'));

    expect($filtered->paginate(2, 10))->toHaveKeys(['data', 'meta']);
    $base->paginate();

    $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]->data());
    expect($requests[0])->toBe(['reference_type' => 'order', 'reference_id' => '42', 'recipient' => 'customer@example.com',
        'statuses' => ['delivered', 'failed'], 'ids' => ['a', 'b'], 'created_from' => '2026-11-01T11:00:00+00:00',
        'created_to' => '2026-11-02T11:00:00+00:00', 'page' => 2, 'per_page' => 10]);
    expect($requests[1])->toBe(['reference_type' => 'order', 'reference_id' => '42', 'page' => 1, 'per_page' => 25]);
});

test('singular status replaces plural statuses without mutating the original', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $base = (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()->statuses(['failed']);
    $base->status('delivered')->paginate();
    $base->paginate();
    expect(Http::recorded()[0][0]->data())->toBe(['status' => 'delivered', 'page' => 1, 'per_page' => 25]);
    expect(Http::recorded()[1][0]->data())->toBe(['statuses' => ['failed'], 'page' => 1, 'per_page' => 25]);
});

test('lazy message iteration requests pages only as consumed and stops at the reported last page', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [['id' => 'a'], ['id' => 'b']], 'meta' => ['current_page' => 1, 'last_page' => 2]])
        ->push(['data' => [['id' => 'c']], 'meta' => ['current_page' => 2, 'last_page' => 2]])]);
    $lazy = (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()->status('delivered')->lazyAll(2);
    Http::assertNothingSent();

    expect($lazy->all())->toBe([['id' => 'a'], ['id' => 'b'], ['id' => 'c']]);

    Http::assertSentCount(2);
    expect(Http::recorded()[1][0]->data())->toBe(['status' => 'delivered', 'page' => 2, 'per_page' => 2]);
});

test('lazy iteration can be stopped without loading a following page', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 'a']], 'meta' => ['current_page' => 1, 'last_page' => 10]])]);
    expect((new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()->lazyAll(1)->take(1)->all())->toBe([['id' => 'a']]);
    Http::assertSentCount(1);
});

test('lazy iteration stops safely for empty missing or inconsistent pagination metadata', function (array $response, int $maxPages) {
    Http::fake(['*' => Http::response($response)]);
    (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()->lazyAll(1, $maxPages)->all();
    Http::assertSentCount(1);
})->with([
    [['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 100]], 10],
    [['data' => [['id' => 'a']]], 10],
    [['data' => [['id' => 'a']], 'meta' => ['current_page' => 99, 'last_page' => 100]], 10],
    [['data' => [['id' => 'a']], 'meta' => ['current_page' => 1, 'last_page' => 100]], 1],
]);

test('invalid query limits and unsafe reference values fail before HTTP', function (Closure $apply) {
    $query = (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query();
    expect(fn () => $apply($query))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    fn ($query) => $query->paginate(0),
    fn ($query) => $query->paginate(1, 101),
    fn ($query) => $query->lazyAll(0),
    fn ($query) => $query->lazyAll(10, 10001),
    fn ($query) => $query->forReference('App\\Order', 42),
    fn ($query) => $query->forReference('order', "42\n"),
    fn ($query) => $query->ids([]),
    fn ($query) => $query->createdBetween('2026-11-02', '2026-11-01'),
]);

test('creation windows preserve subsecond precision as UTC half open bounds', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()
        ->createdBetween('2026-11-01T13:00:00.1+02:00', '2026-11-01T13:00:00.2+02:00')->paginate();

    expect(Http::recorded()[0][0]->data())->toBe(['created_from' => '2026-11-01T11:00:00.100000+00:00',
        'created_to' => '2026-11-01T11:00:00.200000+00:00', 'page' => 1, 'per_page' => 25]);
});

test('equal creation bounds are rejected before HTTP', function () {
    expect(fn () => (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()
        ->createdBetween('2026-11-01T13:00:00.1+02:00', '2026-11-01T11:00:00.100000Z'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('batch reference queries normalize IDs and preserve immutable single and batch constraints', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'secret');
    $single = $client->query()->forReference('order', 42);
    $batch = $single->forReferences('order', [42, '43']);

    $batch->paginate();
    $batch->forReference('invoice', 'invoice-17')->paginate();
    $single->paginate();
    $batch->paginate();

    $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();
    expect($requests[0])->toBe(['reference_type' => 'order', 'reference_ids' => ['42', '43'], 'page' => 1, 'per_page' => 25]);
    expect($requests[1])->toBe(['reference_type' => 'invoice', 'reference_id' => 'invoice-17', 'page' => 1, 'per_page' => 25]);
    expect($requests[2])->toBe(['reference_type' => 'order', 'reference_id' => '42', 'page' => 1, 'per_page' => 25]);
    expect($requests[3])->toBe($requests[0]);
});

test('invalid batch reference constraints fail locally', function (string $type, array $ids) {
    expect(fn () => (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()->forReferences($type, $ids))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    ['order', []],
    ['order', range(1, 101)],
    ['App\\Order', [42]],
    ['order', [42, "43\n"]],
    ['order', [true]],
    ['order', [['nested']]],
]);

test('invalid calendar bounds are rejected instead of silently normalized', function (string $from, string $to) {
    expect(fn () => (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()->createdBetween($from, $to))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    ['2026-02-30T00:00:00Z', '2026-03-05T00:00:00Z'],
    ['2026-02-01T00:00:00Z', '2026-02-30T00:00:00Z'],
]);

test('classification filters compose without changing the base message query', function () {
    Http::fake(['service.test/api/v1/messages*' => Http::response(['data' => []])]);
    $base = (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->query()->forReference('order', 42);

    $base->type('marketing')->category('newsletter')->language('en-GB')->tag('autumn')->paginate();
    $base->paginate();

    expect(Http::recorded()[0][0]->data())->toBe(['reference_type' => 'order', 'reference_id' => '42',
        'type' => 'marketing', 'category' => 'newsletter', 'language' => 'en-GB', 'tag' => 'autumn', 'page' => 1, 'per_page' => 25]);
    expect(Http::recorded()[1][0]->data())->toBe(['reference_type' => 'order', 'reference_id' => '42', 'page' => 1, 'per_page' => 25]);
    Http::assertSentCount(2);
});
