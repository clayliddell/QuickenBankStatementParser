<?php

/**
 * Generate JSON file of known Quicken payees from the supplied QIF file.
 */

// Ensure a file path was supplied.
if (!$transaction_file_path = $argv[1]) {
    die('Transaction file path required.');
}
// Attempt to retrieve output path from supplied arguments.
$output_path = $argv[2] ?? 'payees.json';
// Get the content of the transaction file being analyzed.
$transactions_file = file_get_contents($transactions_file_path);
// Extract transactions as payee and category records.
preg_match("/\nP(?P<payee>.*?)\n.*?L(?P<category>.*?)\n/s", $transactions_file, $transactions);
// Map payees to coorresponding categories.
$payees = array_replace([], ...array_map(
    fn ($transaction) => [trim($transaction['payee']) => trim($transaction['category'])],
    $transactions
));
// Write resulting array of payees and categories to a JSON file.
file_put_contents('payees.json', json_encode($payees));
