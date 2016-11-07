<?php

namespace VSAC;

$do_pull = function ($url) {
    $response = http_get($url);
    if ($response['error']) {
        return 0;
    }

    $response = json_decode($response['body'], true);
    foreach (['to', 'subject', 'html'] as $part) {
        if (empty($response[$part])) {
            return 0;
        }
    }
    if (empty($response['text'])) {
        $response['text'] = '';
    }
    if (empty($response['merge_vars'])) {
        $response['merge_vars'] = array();
    }
    extract($response);
    if (!is_array($to)) {
        $merge_vars = array($to => $merge_vars);
        $to = array();
    }

    $sent = count($to);
    while (!empty($to)) {
        emailer_bulk($to, $subject, $html, $text = '', $merge_vars);
    }
    return $sent;
};


$cooloff = config('emailer_cooloff', 0);
$php_max = ini_get('max_execution_time') - 60 - $cooloff;
if ($php_max < (60 + $cooloff)) {
    email_tools_fatal_error('PHP max is too small to run this script');
}

$config_max = config('email_tools_process_limit', 0);
if ($config_max > $php_max) {
    email_tools_fatal_error('$config["email_tools_process_limit"] PHP max');
}



$batch_size = config('emailer_batch_size', 0);
log_log(' - Batch');
$pull_urls = config('email_tools_pull_urls', array());
$pulled_urls = array();

$end_at_time = time() + $config_max;

log_log(
    "Started %s \n batch size: %s \n end time: %s",
    date('Y-m-d H:i'),
    $batch_size,
    date('Y-m-d H:i', $end_at_time)
);

while(time() < $end_at_time) {
    $sent_total = 0;
    foreach ($pull_urls as $pull_url) {
        $sent = $do_pull($pull_url);
        log_log('Sent %d from %s', $sent, $pull_url);
        $sent_total += $sent;
    }
    if (!$sent_total) {
        sleep(30);
        log_log('Slept 30');
    }
}

?><script>if (confirm('run again?')) {location.reload()}</script>

