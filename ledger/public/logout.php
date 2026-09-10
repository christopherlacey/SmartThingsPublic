<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
logout();

header('Location: login.php?bye=1');
exit;
