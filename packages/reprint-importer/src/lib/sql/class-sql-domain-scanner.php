<?php

namespace Reprint\Importer\Sql;

final class SqlDomainScanner
{
    private ?SqlDomainAuditLogger $audit_logger;

    public function __construct(?SqlDomainAuditLogger $audit_logger = null)
    {
        $this->audit_logger = $audit_logger;
    }

    /**
     * Drain complete SQL statements from a query stream and scan their
     * base64-decoded values for URL domains.
     */
    public function drainQueryStream(
        \WP_MySQL_Naive_Query_Stream $query_stream,
        \DomainCollector $domain_collector,
        ?int &$statements_counted = null
    ): void {
        while ($query_stream->next_query()) {
            $query = $query_stream->get_query();
            if ($statements_counted !== null) {
                $statements_counted++;
            }

            if (!SqlStatementInspector::startsWithToken($query, \WP_MySQL_Lexer::INSERT_SYMBOL)) {
                continue;
            }
            if (strpos($query, "FROM_BASE64(") === false) {
                continue;
            }

            $table = SqlStatementInspector::extractInsertTable($query);
            $is_options_table = substr($table, -8) === '_options';

            $scanner = new \Base64ValueScanner($query);
            while ($scanner->next_value()) {
                $option_name = null;
                $match_offset = $scanner->get_match_offset();

                if ($is_options_table) {
                    $option_name = SqlStatementInspector::extractOptionName($query, $match_offset);
                    if ($this->isTransientOption($option_name)) {
                        continue;
                    }
                }

                $new_domains = $domain_collector->scan($scanner->get_value());
                if (empty($new_domains)) {
                    continue;
                }

                $this->auditDiscoveredDomains(
                    $new_domains,
                    $table,
                    SqlStatementInspector::extractRowIdentifier($query, $match_offset),
                    $option_name,
                );
            }
        }
    }

    private function isTransientOption(?string $option_name): bool
    {
        if ($option_name === null) {
            return false;
        }

        return strpos($option_name, '_transient') === 0
            || strpos($option_name, '_site_transient') === 0;
    }

    /**
     * @param array<int,string> $domains
     */
    private function auditDiscoveredDomains(
        array $domains,
        string $table,
        string $row_id,
        ?string $option_name
    ): void {
        if ($this->audit_logger === null) {
            return;
        }

        $option_context = $option_name !== null ? ' option=' . $option_name : '';
        foreach ($domains as $domain) {
            $this->audit_logger->log_sql_domain_event(
                sprintf(
                    "NEW DOMAIN | %s | table=%s %s%s",
                    $domain,
                    $table,
                    $row_id,
                    $option_context,
                ),
            );
        }
    }
}
