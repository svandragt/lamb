<?php

namespace Tests\Support;

use Lamb\Micropub\LambMicropubAdapter;

/**
 * Subclass that stubs out the HTTP token introspection call for testing.
 */
class StubMicropubAdapter extends LambMicropubAdapter
{
    public ?array $stubResponse = null;

    protected function introspectToken(string $token, string $endpoint): ?array
    {
        return $this->stubResponse;
    }
}
