<?php

namespace Reprint\Importer\Sql;

final class SqlStatementInspector
{
    public static function extractInsertTable(string $query): string
    {
        if (preg_match('/INSERT\s+INTO\s+`([^`]+)`/i', $query, $m)) {
            return $m[1];
        }
        return '?';
    }

    public static function extractRowIdentifier(string $query, int $offset): string
    {
        $depth = 0;
        $row_start = -1;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $ch = $query[$i];
            if ($ch === ')') {
                $depth++;
            } elseif ($ch === '(') {
                if ($depth === 0) {
                    $row_start = $i + 1;
                    break;
                }
                $depth--;
            }
        }

        if ($row_start < 0) {
            return 'offset=?';
        }

        $after = substr($query, $row_start, 40);
        if (preg_match('/^(-?\d+)/', $after, $m)) {
            return 'pk=' . $m[1];
        }
        if (preg_match("/^'([^']{0,30})'/", $after, $m)) {
            return "pk=" . $m[1];
        }
        if (preg_match('/^NULL/i', $after)) {
            return 'pk=NULL';
        }

        return 'offset=?';
    }

    public static function extractOptionName(string $query, int $offset): ?string
    {
        $depth = 0;
        $row_start = -1;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $ch = $query[$i];
            if ($ch === ')') {
                $depth++;
            } elseif ($ch === '(') {
                if ($depth === 0) {
                    $row_start = $i + 1;
                    break;
                }
                $depth--;
            }
        }

        if ($row_start < 0) {
            return null;
        }

        $after = substr($query, $row_start, 200);
        $len = strlen($after);
        $d = 0;
        $comma_pos = -1;
        for ($j = 0; $j < $len; $j++) {
            $c = $after[$j];
            if ($c === '(') {
                $d++;
            } elseif ($c === ')') {
                $d--;
            } elseif ($c === ',' && $d === 0) {
                $comma_pos = $j;
                break;
            }
        }

        if ($comma_pos < 0) {
            return null;
        }

        $rest = ltrim(substr($after, $comma_pos + 1));
        if (isset($rest[0]) && $rest[0] === "'") {
            if (preg_match("/^'([^']{0,80})'/", $rest, $m)) {
                return $m[1];
            }
        }

        if (strpos($rest, 'FROM_BASE64(') === 0) {
            if (preg_match("/^FROM_BASE64\\('([A-Za-z0-9+\\/=]+)'\\)/", $rest, $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false) {
                    return substr($decoded, 0, 80);
                }
            }
        }

        return null;
    }

    public static function startsWithToken(string $sql, int $expected_token_id): bool
    {
        $lexer = new \WP_MySQL_Lexer($sql);
        while ($lexer->next_token()) {
            $token = $lexer->get_token();
            if (
                $token->id === \WP_MySQL_Lexer::WHITESPACE
                || $token->id === \WP_MySQL_Lexer::COMMENT
                || $token->id === \WP_MySQL_Lexer::MYSQL_COMMENT_START
                || $token->id === \WP_MySQL_Lexer::MYSQL_COMMENT_END
            ) {
                continue;
            }
            return $token->id === $expected_token_id;
        }

        return false;
    }
}
