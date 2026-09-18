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

    public function testSameShapeSameHash(): void
    {
        $a = SqlNormalizer::hash('mysql', SqlNormalizer::normalize("select * from users where id = 1"));
        $b = SqlNormalizer::hash('mysql', SqlNormalizer::normalize("select * from users where id = 99"));
        $c = SqlNormalizer::hash('reporting', SqlNormalizer::normalize("select * from users where id = 99"));

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c, 'connection is part of the identity');
    }
}
