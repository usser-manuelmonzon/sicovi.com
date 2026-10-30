<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;

class ProductionProxyTest extends DatabaseTestCase
{
    public function test_health_is_public_and_trusted_tls_proxy_generates_https_login_links(): void
    {
        config(['trustedproxy.proxies' => '*', 'session.secure' => true, 'session.encrypt' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-Host' => 'attacker.invalid'])
            ->get('/login')->assertOk()->assertSee('action="https://localhost/login"', false);
        $this->get('/up')->assertOk();
    }
}
