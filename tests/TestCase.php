<?php

namespace August6th\WorkflowBridge\Tests;

require_once __DIR__ . '/Support/DatabaseTestEnvironment.php';

if (PHP_VERSION_ID < 70100) {
    require_once __DIR__ . '/Support/LegacyTestCase.php';
} else {
    require_once __DIR__ . '/Support/ModernTestCase.php';
}
