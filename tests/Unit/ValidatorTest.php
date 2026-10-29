<?php

declare(strict_types=1);

use Ipase\Event\Validator;
use Ipase\Refusal;

it('checks event names', function (string $name, ?string $reason) {
    expect(Validator::name($name))->toBe($reason);
})->with([
    'ordinary' => ['invoice_paid', null],
    '128 characters' => [str_repeat('é', 128), null],
    'empty' => ['', Refusal::INVALID_NAME],
    '129 characters' => [str_repeat('a', 129), Refusal::INVALID_NAME],
    'reserved prefix' => ['$checkout', Refusal::RESERVED_NAME],
    'reserved list name from a host app' => ['$tap', Refusal::RESERVED_NAME],
    'invalid UTF-8' => ["bad\xC3", Refusal::INVALID_NAME],
]);

it('checks user ids', function (?string $userId, ?string $reason) {
    expect(Validator::userId($userId))->toBe($reason);
})->with([
    [null, null],
    ['u_123', null],
    [str_repeat('u', 128), null],
    ['', Refusal::INVALID_USER_ID],
    [str_repeat('u', 129), Refusal::INVALID_USER_ID],
]);

it('checks properties and traits', function (array $properties, ?string $reason) {
    expect(Validator::properties($properties))->toBe($reason);
})->with([
    'scalars and null' => [['s' => 'x', 'i' => 1, 'f' => 1.5, 'b' => false, 'n' => null], null],
    '100 keys' => [array_fill_keys(array_map(fn ($i) => "k{$i}", range(0, 99)), 1), null],
    '101 keys' => [array_fill_keys(array_map(fn ($i) => "k{$i}", range(0, 100)), 1), Refusal::PROPERTIES_TOO_MANY],
    'numeric key' => [[7 => 'seven'], null],
    'empty key' => [['' => 1], Refusal::PROPERTY_KEY_TOO_LONG],
    '65 character key' => [[str_repeat('k', 65) => 1], Refusal::PROPERTY_KEY_TOO_LONG],
    '$ key' => [['$plan' => 'pro'], Refusal::RESERVED_PROPERTY_KEY],
    '1024 character value' => [['v' => str_repeat('ü', 1024)], null],
    '1025 character value' => [['v' => str_repeat('v', 1025)], Refusal::PROPERTY_VALUE_TOO_LONG],
    'array value' => [['v' => [1, 2]], Refusal::INVALID_PROPERTY],
    'object value' => [['v' => new stdClass], Refusal::INVALID_PROPERTY],
    'infinite' => [['v' => INF], Refusal::INVALID_PROPERTY],
    'NaN' => [['v' => NAN], Refusal::INVALID_PROPERTY],
    'invalid UTF-8 value' => [['v' => "\xFF"], Refusal::INVALID_PROPERTY],
]);
