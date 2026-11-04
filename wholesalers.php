<?php
require_once 'config.php';
require_once 'auth_check.php';
require_system_administrator();
redirect('customers.php?tab=wholesalers');
