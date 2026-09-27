<?php

$db = new SQLite3(__DIR__ . '/database.sqlite');

$db->exec("
    CREATE TABLE IF NOT EXISTS patients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mrn TEXT UNIQUE NOT NULL,
        full_name TEXT NOT NULL,
        age INTEGER NOT NULL,
        gender TEXT NOT NULL,
        modality TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL,
        details TEXT NOT NULL,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

?>