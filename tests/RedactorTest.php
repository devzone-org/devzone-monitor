<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    /** @var Redactor */
    private $redactor;

    protected function setUp(): void
    {
        $this->redactor = new Redactor();
    }

    public function testSensitiveKeysAreReplacedAtAnyDepth(): void
    {
        $out = $this->redactor->redact([
            'email' => 'user@example.com',
            'password' => 'hunter2',
            'nested' => [
                'api_token' => 'abc',
                'cardPin' => '1234',
                'bank_account_no' => '0011223344',
                'shipping' => 'kept',
                'pinned' => 'kept',
            ],
            'headers' => ['Authorization' => 'Bearer xyz'],
        ]);

        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('[REDACTED]', $out['nested']['api_token']);
        $this->assertSame('[REDACTED]', $out['nested']['cardPin']);
        $this->assertSame('[REDACTED]', $out['nested']['bank_account_no']);
        $this->assertSame('kept', $out['nested']['shipping']);
        $this->assertSame('kept', $out['nested']['pinned']);
        $this->assertSame('[REDACTED]', $out['headers']['Authorization']);
        // Value-level pattern still applies to non-sensitive keys.
        $this->assertSame('[REDACTED]', $out['email']);
    }

    public function testKnownPatternsAreStrippedFromStrings(): void
    {
        $cases = [
            'CNIC 35202-1234567-1 on file' => 'CNIC [REDACTED] on file',
            'CNIC 3520212345671 on file' => 'CNIC [REDACTED] on file',
            'card 4111 1111 1111 1111 declined' => 'card [REDACTED] declined',
            'card 4111111111111111 declined' => 'card [REDACTED] declined',
            'mail john.doe+test@example.co.uk bounced' => 'mail [REDACTED] bounced',
            'hash $2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUV012345678 stored'
                => 'hash [REDACTED] stored',
        ];

        foreach ($cases as $in => $expected) {
            $this->assertSame($expected, $this->redactor->redactString($in), $in);
        }
    }

    public function testKeyValuePairsInsideStringsAreRedacted(): void
    {
        $this->assertSame(
            'login failed password=[REDACTED]&remember=1',
            $this->redactor->redactString('login failed password=hunter2&remember=1')
        );
        $this->assertSame(
            '{"password":"[REDACTED]","name":"x"}',
            $this->redactor->redactString('{"password":"hunter2","name":"x"}')
        );
        $this->assertSame(
            'Authorization: Bearer [REDACTED] sent',
            $this->redactor->redactString('Authorization: Bearer eyJhbGciOi sent')
        );
        $this->assertSame('shipping: express', $this->redactor->redactString('shipping: express'));
    }

    public function testQueryExceptionBindingsAreStripped(): void
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry "
            . "(SQL: insert into users (email, password, cnic) values (a@b.com, hunter2, 3520212345671))";

        $this->assertSame(
            'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (SQL: insert into users (email, password, cnic) values [REDACTED])',
            $this->redactor->redactString($message)
        );

        $this->assertSame(
            'x (SQL: update users set [REDACTED])',
            $this->redactor->redactString('x (SQL: update users set password = hunter2 where id = 5)')
        );
    }

    public function testTraceArgumentsAreStripped(): void
    {
        $trace = "#0 /app/Http/Controllers/AuthController.php(42): App\\Auth->login('john', 'hunter2')\n"
            . "#1 [internal function]: App\\Http\\Controllers\\AuthController->store(Object(Illuminate\\Http\\Request))\n"
            . "#2 {main}";

        $this->assertSame(
            "#0 /app/Http/Controllers/AuthController.php(42): App\\Auth->login(...)\n"
            . "#1 [internal function]: App\\Http\\Controllers\\AuthController->store(...)\n"
            . "#2 {main}",
            $this->redactor->redactString($trace)
        );
    }

    public function testConfigurableKeysAndPatterns(): void
    {
        $redactor = Redactor::fromConfig([
            'keys' => ['ssn'],
            'patterns' => ['/\bACME-\d+\b/'],
            'replacement' => '***',
            'sql_bindings' => false,
            'trace_arguments' => false,
        ]);

        $out = $redactor->redact(['ssn' => '1', 'password' => 'visible', 'note' => 'ref ACME-42']);
        $this->assertSame('***', $out['ssn']);
        $this->assertSame('visible', $out['password']);
        $this->assertSame('ref ***', $out['note']);
        $this->assertSame('x (SQL: select 1 where a = b)', $redactor->redactString('x (SQL: select 1 where a = b)'));
    }

    public function testXmlSoapElementsAreRedactedByName(): void
    {
        $xml = '<soapenv:Body><ns:Verify><ns:AccountNo>0011223344</ns:AccountNo><ns:Pin type="x">4321</ns:Pin><ns:Name>Bob</ns:Name></ns:Verify></soapenv:Body>';

        $this->assertSame(
            '<soapenv:Body><ns:Verify><ns:AccountNo>[REDACTED]</ns:AccountNo><ns:Pin type="x">[REDACTED]</ns:Pin><ns:Name>Bob</ns:Name></ns:Verify></soapenv:Body>',
            $this->redactor->redactString($xml)
        );
    }

    public function testBodiesAreRedactedByFormat(): void
    {
        $this->assertSame('{"cnic":"[REDACTED]","amount":100}', $this->redactor->redactBody('{"cnic":"3520212345671","amount":100}'));
        $this->assertSame('pin=[REDACTED]&amount=100', $this->redactor->redactBody('pin=4321&amount=100'));
        $this->assertSame('where email = ? -- [REDACTED]', $this->redactor->redactPatterns('where email = ? -- bob@example.com'));
    }

    public function testNonStringScalarsPassThrough(): void
    {
        $this->assertSame([1, 2.5, true, null], $this->redactor->redact([1, 2.5, true, null]));
    }
}
