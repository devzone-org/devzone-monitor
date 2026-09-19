<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Capture\SqlNormalizer;
use PHPUnit\Framework\TestCase;

final class SqlNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'placeholders untouched' => ['select * from `transfers` where `id` = ?', 'select * from `transfers` where `id` = ?'],
            'string and number literals' => ["select * from users where email = 'bob@example.com' and id = 42", 'select * from users where email = ? and id = ?'],
            'escaped quotes' => ["update users set name = 'O''Brien', note = 'it\\'s' where id = 7", 'update users set name = ?, note = ? where id = ?'],
            'decimals and negatives' => ['update accounts set balance = -120.50 where rate > 1.5e3', 'update accounts set balance = ? where rate > ?'],
            'identifiers with digits kept' => ['select `t1`.`col2` from t1 join table_2 on t1.id = table_2.t1_id', 'select `t1`.`col2` from t1 join table_2 on t1.id = table_2.t1_id'],
            'in list collapsed' => ['select * from a where b IN (?, ?, ?, ?)', 'select * from a where b in (...)'],
            'multi row insert collapsed' => ['insert into `logs` (`a`, `b`) values (?, ?), (?, ?), (?, ?)', 'insert into `logs` (`a`, `b`) values (...)'],
            'whitespace collapsed' => ["select  *\n\tfrom x", 'select * from x'],
        ];
    }

    public function testNormalize(): void
    {
        foreach (self::cases() as $name => $case) {
            $this->assertSame($case[1], SqlNormalizer::normalize($case[0]), $name);
        }
    }

    public function testDialects(): void
    {
        $cases = [
            ['pgsql', 'select * from "users" where "id" = 5 and name = \'x\'', 'select * from "users" where "id" = ? and name = ?'],
            ['pgsql', 'select $$secret$$, $body$it\'s$body$, $1 from t', 'select ?, ?, $1 from t'],
            ['pgsql', "select E'a\\'b', U&'x' from t", 'select ?, ? from t'],
            ['pgsql', "select 'a\\' from t", 'select ? from t'],
            ['mysql', 'select `a` from t where b = "x" and c in ("y", \'z\') -- note', 'select `a` from t where b = ? and c in (...)'],
            ['mysql', "select 1 # note\nfrom t where a = _utf8mb4'x' and b = X'4C' and c = 0x1F", 'select ? from t where a = ? and b = ? and c = ?'],
            ['sqlsrv', "select * from [dbo].[t2] where x = N'y'", 'select * from [dbo].[t2] where x = ?'],
            ['sqlite', 'select "a" from `t` where b = \'c\' /* note */', 'select "a" from `t` where b = ?'],
        ];
        foreach ($cases as [$driver, $sql, $expected]) {
            $this->assertSame($expected, SqlNormalizer::normalize($sql, $driver), "{$driver}: {$sql}");
        }
    }

    public function testUnclosedLiteralsAndCommentsAreNotKept(): void
    {
        foreach ([
            ['mysql', "select * from t where a = 'secret"],
            ['mysql', 'select * from t where a = "secret'],
            ['pgsql', 'select $$secret'],
            ['pgsql', 'select 1 /* secret'],
            ['', "select 'a''"],
        ] as [$driver, $sql]) {
            $this->assertSame(SqlNormalizer::UNPARSED, SqlNormalizer::normalize($sql, $driver), $sql);
        }
    }

    public function testLongStatementsAreMaskedBeforeTheyAreCut(): void
    {
        $sql = "select '" . str_repeat('a', 9990) . "secretword' as a, b from t";
        $this->assertSame("select ? as a, b from t", SqlNormalizer::normalize($sql, 'mysql'));

        $long = 'select ' . implode(', ', array_fill(0, 3000, 'col_name')) . " from t where a = 'secretword'";
        $normalized = SqlNormalizer::normalize($long, 'mysql');
        $this->assertLessThanOrEqual(SqlNormalizer::MAX_LENGTH + 4, strlen($normalized));
        $this->assertStringNotContainsString('secretword', $normalized);

        $this->assertSame(SqlNormalizer::TOO_LONG, SqlNormalizer::normalize(str_repeat('x', SqlNormalizer::MAX_INPUT + 1)));
    }

    public function testSameShapeSameHash(): void
    {
        $a = SqlNormalizer::hash('mysql', SqlNormalizer::normalize("select * from users where id = 1"));
        $b = SqlNormalizer::hash('mysql', SqlNormalizer::normalize("select * from users where id = 99"));
        $c = SqlNormalizer::hash('reporting', SqlNormalizer::normalize("select * from users where id = 99"));

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c, 'connection is part of the identity');
    }
}
