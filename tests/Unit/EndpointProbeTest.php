<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\EndpointProbe;

/**
 * EndpointProbe must hand back a child's output as soon as the child is done,
 * whatever the child does with its pipes and with the fake token endpoint.
 */
class EndpointProbeTest extends TestCase
{
    public function testAHandlerThatNeverReachesTheTokenEndpointDoesNotMakeTheProbeWait(): void
    {
        $start = microtime(true);
        $result = EndpointProbe::run('echo "done";', ['token' => '{"me":"https://example.com"}']);

        $this->assertSame('done', $result['body']);
        $this->assertLessThan(5, microtime(true) - $start, 'The probe waited for an introspection request that never came.');
    }

    public function testAChildThatWritesMoreThanAPipeHoldsToStderrStillFinishes(): void
    {
        $result = EndpointProbe::run('fwrite(STDERR, str_repeat("x", 300000)); echo "done";');

        $this->assertSame('done', $result['body']);
        $this->assertSame(300000, strlen($result['stderr']));
    }

    public function testTheTokenEndpointAnswersWhileTheChildWritesToStderr(): void
    {
        $call = 'fwrite(STDERR, str_repeat("x", 300000));'
            . '$answer = file_get_contents($config["token_endpoint"]); echo $answer;';
        $result = EndpointProbe::run($call, ['token' => '{"me":"https://example.com"}']);

        $this->assertSame('{"me":"https://example.com"}', $result['body']);
    }
}
