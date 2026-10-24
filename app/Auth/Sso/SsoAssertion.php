<?php

namespace App\Auth\Sso;

/**
 * A verified statement from Koha that a staff member is authenticated and
 * may open the MAM.
 */
final class SsoAssertion
{
    public function __construct(
        public readonly string $kohaInstanceId,
        public readonly int $patronId,
        public readonly string $userid,
        public readonly ?int $biblioId,
        /** Home library reported by Koha; informational only. */
        public readonly ?string $branchHint = null,
        /** Koha correlation identifier of the exchange. */
        public readonly string $tokenId = '',
        /**
         * Koha plugin capabilities: an upper bound on MAM capabilities.
         *
         * @var list<string>|null
         */
        public readonly ?array $kohaCapabilities = null,
        /** Koha item of the record (plugin 2.5+ item-level "Attach Media"); null for the record itself. */
        public readonly ?int $itemId = null,
    ) {}
}
