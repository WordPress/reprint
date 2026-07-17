<?php

namespace Reprint\Importer\Sql;

interface SqlDomainAuditLogger
{
    public function log_sql_domain_event(string $message): void;
}
