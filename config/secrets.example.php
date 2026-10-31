<?php
// Template for config/secrets.local.php — safe to commit.
// Copy this file to config/secrets.local.php and fill in your own values.

return [
    // PhilSMS API token
    'sms_token' => '',

    // Gmail SMTP (App Password, not the normal account password)
    'smtp_user' => '',
    'smtp_pass' => '',

    // Remote production database (e.g. InfinityFree). Leave blank for local-only use.
    'db_host_remote' => '',
    'db_name_remote' => '',
    'db_user_remote' => '',
    'db_pass_remote' => '',
];
