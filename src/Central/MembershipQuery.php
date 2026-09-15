<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Central;

final readonly class MembershipQuery
{
    public function __construct(
        string $identityId,
        string $accountId,
        string $product,
        string $requestId,
    ) {
        $this->identityId = CentralValue::uuid(value: $identityId, field: 'identity_id');
        $this->accountId = CentralValue::uuid(value: $accountId, field: 'account_id');
        $this->product = CentralValue::product(value: $product);
        $this->requestId = CentralValue::uuid(value: $requestId, field: 'request_id');
    }

    public string $identityId;

    public string $accountId;

    public string $product;

    public string $requestId;

    public function wireQuery(): string
    {
        return http_build_query(['identity_id' => $this->identityId, 'account_id' => $this->accountId, 'product' => $this->product, 'request_id' => $this->requestId], '', '&', PHP_QUERY_RFC3986);
    }

    public static function fromWireQuery(string $wire): self
    {
        $fields = [];

        foreach (explode('&', $wire) as $pair) {
            $parts = explode('=', $pair);

            if (count($parts) !== 2 || str_contains($pair, '+') || preg_match('/%(?![0-9a-fA-F]{2})/', $pair)) {
                throw new CentralProtocolViolation('Invalid scalar query encoding.');
            }

            $key = rawurldecode($parts[0]);
            $value = rawurldecode($parts[1]);

            if (array_key_exists($key, $fields) || $value === '') {
                throw new CentralProtocolViolation('Duplicate or empty scalar query.');
            }

            $fields[$key] = $value;
        }

        CentralValue::fields(payload: $fields, fields: ['identity_id', 'account_id', 'product', 'request_id']);
        $query = new self(identityId: $fields['identity_id'], accountId: $fields['account_id'], product: $fields['product'], requestId: $fields['request_id']);

        if ($query->wireQuery() !== $wire) {
            throw new CentralProtocolViolation('Noncanonical query bytes.');
        }

        return $query;
    }
}
