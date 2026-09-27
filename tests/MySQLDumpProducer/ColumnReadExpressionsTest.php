<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

class ColumnReadExpressionsTest extends MySQLDumpProducerTestBase
{
    public static function column_types(): array
    {
        return [
            ['LONGTEXT CHARACTER SET utf8mb4', "é漢🙂"],
            ['LONGTEXT CHARACTER SET latin1', "\xe9"],
            ['LONGBLOB', "\xff\xe9"],
        ];
    }

    /** @dataProvider column_types */
    public function testReplacementSurvivesOversizedReadsAndResume(string $type, string $bytes): void
    {
        $this->pdo->exec("CREATE TABLE custom_options (option_id bigint PRIMARY KEY, option_name varchar(191), option_value {$type}, autoload varchar(10))");
        $plugins = [];
        for ($index = 0; $index < 300; ++$index) {
            $plugins[] = 'plugin-' . $index . '-' . str_repeat($bytes, 5) . '/index.php';
        }
        $original = serialize(array_merge(['reprint/index.php'], $plugins));
        $replacement = serialize($plugins);
        $insert = $this->pdo->prepare('INSERT INTO custom_options VALUES (?, ?, FROM_BASE64(?), ?)');
        $insert->execute([1, 'active_plugins', base64_encode($original), 'yes']);
        $insert->execute([2, 'another_setting', base64_encode($original), 'no']);
        $encoded = base64_encode($replacement);
        $options = [
            'max_statement_size' => 2048,
            'batch_size' => 1,
            'column_read_expressions' => ['custom_options' => ['option_value' =>
                "CASE WHEN option_name = 'active_plugins' THEN FROM_BASE64('{$encoded}') ELSE option_value END"]],
        ];
        $producer = $this->createProducer($options);
        $sql = '';
        $steps = 0;
        while ($producer->next_sql_fragment()) {
            $this->assertLessThan(500, ++$steps, 'The resumed export must reach the end of the changed value.');
            $sql .= $producer->get_sql_fragment();
            if ($producer->is_finished()) {
                break;
            }
            $options['cursor'] = $producer->get_reentrancy_cursor();
            $producer->close();
            $producer = $this->createProducer($options);
        }
        $this->assertStringContainsString('UPDATE `custom_options`', $sql, 'Exercise the oversized substring path, not just a normal INSERT.');
        $target = $this->executeDumpInNewDatabase($sql);
        $rows = $target->query('SELECT CAST(option_value AS BINARY) AS option_value, autoload FROM custom_options ORDER BY option_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([
            ['option_value' => $replacement, 'autoload' => 'yes'],
            ['option_value' => $original, 'autoload' => 'no'],
        ], $rows);
        $this->assertSame($original, $this->pdo->query('SELECT CAST(option_value AS BINARY) FROM custom_options WHERE option_id = 1')->fetchColumn());
    }
}
