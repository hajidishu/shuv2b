<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

// Replay the historical SQL without hiding operational errors or destroying
// existing tables on an interrupted installation.
class DatabaseUpgrade
{
    private const RETIRED_TABLES = ['v2_server', 'v2_server_v2ray', 'v2_server_log', 'v2_server_stat', 'v2_tutorial', 'v2_stat_order'];

    public static function statements(string $sql, string $separator = ';'): array
    {
        $result = []; $buffer = ''; $quote = null; $depth = 0; $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i]; $next = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') { $buffer .= $next; $i++; }
                elseif ($char === $quote) {
                    if ($next === $quote) { $buffer .= $next; $i++; } else $quote = null;
                }
                continue;
            }
            if (($char === '-' && $next === '-' && ctype_space($sql[$i + 2] ?? ' ')) || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") $i++;
                $buffer .= ' '; continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) throw new \RuntimeException('SQL 注释未闭合');
                $i = $end + 1; $buffer .= ' '; continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) { $quote = $char; $buffer .= $char; continue; }
            if ($char === '(') $depth++;
            if ($char === ')') $depth--;
            if ($char === $separator && ($separator === ';' || $depth === 0)) {
                if (trim($buffer) !== '') $result[] = trim($buffer);
                $buffer = '';
            } else $buffer .= $char;
        }
        if ($quote !== null || $depth !== 0) throw new \RuntimeException('SQL 语句未闭合');
        if (trim($buffer) !== '') $result[] = trim($buffer);
        return $result;
    }

    public function run(string $sql, bool $updating): void
    {
        if ($updating) {
            // This old routine truncates historical statistics. Never replay a
            // destructive cleanup on each update. Duplicate data instead makes
            // the subsequent unique-index DDL fail visibly for manual repair.
            $sql = preg_replace('/DELIMITER \$\$\s+DROP PROCEDURE IF EXISTS `path-2022-03-29`.*?DELIMITER\s*;\s*CALL `path-2022-03-29`\(\);\s*DROP PROCEDURE IF EXISTS `path-2022-03-29`;/s', '', $sql);
        }
        $schema = DB::connection()->getSchemaBuilder();
        foreach (self::statements($sql) as $statement) {
            if ($updating && preg_match('/\AUPDATE\s+`([^`]+)`/i', $statement, $match) &&
                in_array($match[1], self::RETIRED_TABLES, true) && !$schema->hasTable($match[1])) continue;
            if (!$updating && preg_match('/\ADROP TABLE\b/i', $statement)) continue;
            if (preg_match('/\ACREATE TABLE\s+`([^`]+)`/i', $statement, $match)) {
                if ($schema->hasTable($match[1]) || ($updating && in_array($match[1], self::RETIRED_TABLES, true))) continue;
            }
            if ($updating && preg_match('/\AALTER TABLE\s+`([^`]+)`\s+(.+)\z/is', $statement, $match)) {
                $table = $match[1];
                if (!$schema->hasTable($table) && in_array($table, self::RETIRED_TABLES, true)) continue;
                if ($table === 'v2_user' && strpos($match[2], 'ADD UNIQUE `email_deleted_at`') !== false && !$schema->hasColumn($table, 'deleted_at')) continue;
                foreach (self::statements($match[2], ',') as $clause) {
                    if ($table === 'v2_stat_user' && preg_match('/\AADD (?:UNIQUE|INDEX|KEY)\b.*\([^)]*`server_id`/i', $clause) && !$schema->hasColumn($table, 'server_id')) continue;
                    if (preg_match('/\AADD\s+(?:COLUMN\s+)?`([^`]+)`/i', $clause, $field) && $schema->hasColumn($table, $field[1])) continue;
                    if (preg_match('/\ADROP\s+(?:COLUMN\s+)?`([^`]+)`\z/i', $clause, $field) && !$schema->hasColumn($table, $field[1])) continue;
                    if (preg_match('/\ACHANGE\s+`([^`]+)`\s+`([^`]+)`/i', $clause, $field) && !$schema->hasColumn($table, $field[1])) {
                        if ($field[1] !== $field[2] && $schema->hasColumn($table, $field[2])) continue;
                        // Fields explicitly removed later in this same history.
                        if ($field[1] === $field[2] && in_array($table . '.' . $field[1], ['v2_user.enable', 'v2_stat_user.server_id', 'v2_stat_user.server_type'], true)) continue;
                    }
                    // Column order does not affect the application's schema;
                    // old AFTER anchors may have been renamed or removed.
                    $clause = preg_replace('/\s+(?:AFTER\s+`[^`]+`|FIRST)\s*\z/i', '', $clause);
                    $this->execute('ALTER TABLE `' . $table . '` ' . $clause, true);
                }
            } else $this->execute($statement, $updating);
        }
    }

    private function execute(string $statement, bool $updating): void
    {
        try { DB::unprepared($statement); }
        catch (QueryException $error) {
            $code = (int)($error->errorInfo[1] ?? 0);
            // Only exact idempotency errors are tolerated; permissions, unknown
            // columns, lock timeouts, disk errors and invalid SQL must fail.
            if ($updating && (($code === 1061 && preg_match('/\AALTER TABLE `[^`]+` ADD (?:UNIQUE|INDEX|KEY)\b/i', $statement)) ||
                ($code === 1091 && preg_match('/\AALTER TABLE `[^`]+` DROP (?:INDEX|KEY)\b/i', $statement)) ||
                ($code === 1051 && preg_match('/\ADROP TABLE\b/i', $statement)))) return;
            throw new \RuntimeException('数据库操作失败，请保留备份并处理错误后重试（MySQL ' . $code . '）。语句：' . substr($statement, 0, 180), 0, $error);
        }
    }

    public function verify(string $installSql): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        foreach (self::statements($installSql) as $statement) {
            if (!preg_match('/\ACREATE TABLE `([^`]+)`\s*\((.*)\)\s*(?:ENGINE|COMMENT|COLLATE|\z)/is', $statement, $table)) continue;
            if (!$schema->hasTable($table[1])) throw new \RuntimeException('数据库缺少表：' . $table[1]);
            foreach (self::statements($table[2], ',') as $column) {
                if (preg_match('/\A`([^`]+)`\s/', $column, $field) && !$schema->hasColumn($table[1], $field[1])) {
                    throw new \RuntimeException('数据库缺少字段：' . $table[1] . '.' . $field[1]);
                }
            }
        }
    }
}
