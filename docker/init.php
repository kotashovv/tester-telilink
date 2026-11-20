<?php
declare(strict_types=1);
foreach (['db_password', 'root_password', 'platform_password', 'application_id'] as $name) {
    $path = '/run/daily-pass/' . $name;
    if (!is_file($path)) {
        file_put_contents($path, bin2hex(random_bytes(24)), LOCK_EX);
        chmod($path, 0644); // Local compose containers share this read-only volume.
    }
}
