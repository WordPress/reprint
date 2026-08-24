<?php

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\DatabaseRowsReader;
use WordPress\Reprint\Server\MySQLDumpProducer;

final class PdoLessAdapterExceptionTest extends TestCase {
    public function testReaderReportsAnOrdinaryQueryExceptionWithContext(): void
    {
        $connection = new class() {
            public function query($query)
            {
                throw new Exception('metadata unavailable');
            }
        };
        $reader = new DatabaseRowsReader($connection, ['tables_to_process' => ['wp_posts']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to get primary key columns for `wp_posts`: metadata unavailable');
        $reader->move_to_next_table();
    }

    public function testProducerReportsAnOrdinaryQueryExceptionWithContext(): void
    {
        $connection = new class() {
            public function query($query)
            {
                if (strpos($query, 'SHOW INDEX FROM ') === 0) {
                    return new class() {
                        private $rows = [
                            ['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Seq_in_index' => 1],
                        ];

                        public function fetch($mode = null)
                        {
                            return array_shift($this->rows) ?: false;
                        }
                    };
                }

                if (strpos($query, 'SHOW FULL COLUMNS FROM ') === 0) {
                    return new class() {
                        private $rows = [
                            ['Field' => 'id', 'Type' => 'int(11)'],
                        ];

                        public function fetch($mode = null)
                        {
                            return array_shift($this->rows) ?: false;
                        }
                    };
                }

                throw new Exception('create statement unavailable');
            }
        };
        $producer = new MySQLDumpProducer($connection, [
            'tables_to_process' => ['wp_posts'],
            'max_statement_size' => 1024 * 1024,
        ]);

        $this->assertTrue($producer->next_sql_fragment());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to get CREATE TABLE for `wp_posts`: create statement unavailable');
        $producer->next_sql_fragment();
    }

    public function testPacketSizeDetectionUsesItsDefaultForAnOrdinaryException(): void
    {
        $connection = new class() {
            public function query($query)
            {
                throw new Exception('packet size unavailable');
            }
        };
        $producer = new MySQLDumpProducer($connection);
        $property = ( new ReflectionObject($producer) )->getProperty('max_statement_size');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $this->assertSame(1024 * 1024, $property->getValue($producer));
    }
}
