<?php

/**
 * Layer 2: statement admission, before anything reaches the database.
 *
 * Proposal 167 sections 167.6.5 and 167.4.1, and amendment R3. This is a
 * **courtesy, not a control**: it turns a hostile or malformed statement into a
 * clean typed refusal, and it keeps obviously-wrong SQL out of the driver's error
 * path. The control is the role in layer 1 — the implementation plan's
 * L2-removal gate deletes this class's effect entirely and asserts the database
 * still refuses a write.
 *
 * ## Why normalisation matters
 *
 * Keyword matching against raw SQL is defeated by a `COMMIT` inside a string
 * literal, by a comment, or by a dollar-quoted body. Everything here therefore
 * runs against a *normalised* form: comments removed, string literals and quoted
 * identifiers blanked, dollar-quoted bodies blanked. Only then are the keyword
 * and semicolon checks applied, so `SELECT 'commit'` is not treated as a commit.
 *
 * ## Engine awareness
 *
 * PostgreSQL rejects stacked statements in a prepared statement by itself (that
 * is why the executor must always use `prepare()`), whereas MySQL permits them
 * unless `MYSQL_ATTR_MULTI_STATEMENTS` is off. The denylists differ accordingly:
 * `COPY … FROM PROGRAM` and `pg_read_file` exist only for PostgreSQL;
 * `LOAD_FILE` and `INTO OUTFILE` only for MySQL.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;

final class AdmissionClassifier
{
    /**
     * Keywords that may never appear in an admitted statement.
     *
     * Matched as whole words against the normalised form, so `inserted_at` is not
     * a hit but `INSERT` is.
     *
     * @var list<string>
     */
    private const DENIED_KEYWORDS = array(
        // Data modification.
        'insert', 'update', 'delete', 'merge', 'truncate', 'upsert',
        // Schema.
        'create', 'alter', 'drop', 'comment', 'reindex', 'cluster', 'vacuum', 'analyze',
        // Privilege and session.
        'grant', 'revoke', 'set', 'reset', 'discard', 'role', 'authorization',
        // Execution and procedural.
        'call', 'do', 'execute', 'prepare', 'deallocate', 'listen', 'notify', 'unlisten',
        // Bulk / file.
        'copy', 'load', 'outfile', 'dumpfile',
        // Locks.
        'lock', 'advisory',
        // Transaction control (the executor owns the transaction).
        'commit', 'rollback', 'begin', 'start', 'end', 'abort', 'savepoint', 'release',
    );

    /**
     * Function and relation names that must never be referenced.
     *
     * @var list<string>
     */
    private const DENIED_SYMBOLS = array(
        // PostgreSQL file/OS/network surfaces.
        'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir', 'pg_stat_file',
        'pg_current_logfile', 'lo_import', 'lo_export', 'dblink', 'dblink_exec',
        // Credential surfaces.
        'pg_authid', 'pg_shadow', 'pg_user', 'pg_roles', 'pg_settings',
        'pg_file_settings', 'pg_hba_file_rules', 'pg_stat_activity',
        // MySQL file surfaces.
        'load_file',
        // Deliberate delay primitives.
        'pg_sleep', 'sleep', 'benchmark',
    );

    /**
     * Admit a statement or refuse it.
     *
     * @param string $sql    Candidate statement.
     * @param string $engine One of the {@see ConnectionDescriptor} engine constants.
     *
     * @throws DatabaseRefusal When the statement is not a single read.
     */
    public function admit(string $sql, string $engine): void
    {
        $normalised = $this->normalise($sql);

        if ('' === $normalised) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Empty statement.');
        }

        // Stacked statements. A trailing semicolon is tolerated and removed, so
        // a well-formed statement is not refused for punctuation.
        $trimmed = rtrim($normalised, " \t\n\r;");

        if (str_contains($trimmed, ';')) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Multiple statements.');
        }

        if (! preg_match('/^(select|with)\b/', $trimmed)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Not a SELECT statement.');
        }

        foreach (self::DENIED_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $trimmed)) {
                throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Denied keyword: ' . $keyword);
            }
        }

        foreach (self::DENIED_SYMBOLS as $symbol) {
            if (preg_match('/\b' . preg_quote($symbol, '/') . '\b/', $trimmed)) {
                throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Denied symbol: ' . $symbol);
            }
        }

        // System schemas are readable but disclosive; a scoped agent has no
        // business enumerating them. Denied for every engine, not just MySQL —
        // the host adapter's own introspection never passes through this method.
        if (preg_match('/\binformation_schema\b|\bpg_catalog\b/', $trimmed)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'System schema reference.');
        }
    }

    /**
     * Normalise a statement for keyword matching.
     *
     * Removes line and block comments, and blanks single-quoted strings, quoted
     * identifiers and dollar-quoted bodies. Length is preserved where practical so
     * an error position would still be meaningful, though the position is not
     * surfaced.
     */
    public function normalise(string $sql): string
    {
        $out = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // Line comment.
            if ('-' === $char && '-' === $next) {
                $end = strpos($sql, "\n", $i);
                $i = false === $end ? $length : $end;
                $out .= ' ';
                continue;
            }

            // Block comment (nesting tolerated).
            if ('/' === $char && '*' === $next) {
                $depth = 1;
                $i += 2;

                while ($i < $length && $depth > 0) {
                    if ('/' === $sql[$i] && $i + 1 < $length && '*' === $sql[$i + 1]) {
                        ++$depth;
                        $i += 2;
                        continue;
                    }

                    if ('*' === $sql[$i] && $i + 1 < $length && '/' === $sql[$i + 1]) {
                        --$depth;
                        $i += 2;
                        continue;
                    }

                    ++$i;
                }

                $out .= ' ';
                continue;
            }

            // Single-quoted string.
            if ("'" === $char) {
                $i = $this->skipQuoted($sql, $i, "'");
                $out .= "''";
                continue;
            }

            // Double-quoted identifier.
            if ('"' === $char) {
                $i = $this->skipQuoted($sql, $i, '"');
                $out .= '""';
                continue;
            }

            // Backtick identifier (MySQL).
            if ('`' === $char) {
                $i = $this->skipQuoted($sql, $i, '`');
                $out .= '``';
                continue;
            }

            // Dollar-quoted body (PostgreSQL), e.g. $$ … $$ or $tag$ … $tag$.
            if ('$' === $char && preg_match('/^\$[a-zA-Z_0-9]*\$/', substr($sql, $i), $m)) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));

                $i = false === $end ? $length : $end + strlen($tag);
                $out .= ' ';
                continue;
            }

            $out .= $char;
            ++$i;
        }

        return strtolower(trim($out));
    }

    /**
     * Advance past a quoted run, tolerating the doubled-quote escape.
     */
    private function skipQuoted(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            if ($sql[$i] === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }

            ++$i;
        }

        return $length;
    }
}
