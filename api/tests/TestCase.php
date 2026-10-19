<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Trava de segurança: aborta antes de qualquer migration/RefreshDatabase se a
     * suíte não estiver no SQLite em memória. Protege o banco de produção caso o
     * phpunit.xml seja ignorado (ex.: config cacheada, env exportada no shell).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $driver = $app['config']->get("database.connections.{$connection}.driver");
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Testes abortados: banco '{$connection}' ({$driver}: {$database}) não é SQLite em memória."
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // O MariaDB de produção tem REGEXP_REPLACE (busca por telefone só com
        // dígitos em login/sessão/cadastro); o SQLite dos testes não.
        $this->app['db']->connection()->getPdo()->sqliteCreateFunction(
            'REGEXP_REPLACE',
            fn ($subject, $pattern, $replacement) => preg_replace('#'.$pattern.'#u', $replacement, (string) $subject),
            3
        );
    }
}
