<?php
// QuickBooks Online Integration — STUB
// Wire in when developer credentials are available in config.php

function qb_push_invoice(array $order, array $order_items, array $customer): string|false {
    // TODO: Implement QB API call using $qb_client_id, $qb_client_secret, etc. from config.php
    // Should return the QB invoice ID string on success, false on failure
    return false;
}

function qb_sync_customers(PDO $db): int {
    // TODO: Pull customers from QB API and upsert into customers table
    // Returns number of customers synced
    return 0;
}

function qb_is_configured(): bool {
    global $qb_client_id, $qb_realm_id;
    return !empty($qb_client_id) && !empty($qb_realm_id);
}
