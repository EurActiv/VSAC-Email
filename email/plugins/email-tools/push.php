<?php

namespace VSAC;

if (!apikey_is_valid()) {
    email_tools_fatal_error('invalid or missing api key');
}

$html = request_request('html', '');
$text = request_request('text', '');
$subject = request_request('subject', '');
$to = request_request('to', '');
$merge_vars = request_request('merge_vars', array());

if (empty($to)) {
    email_tools_fatal_error('to address missing');
}
if (empty($subject)) {
    email_tools_fatal_error('subject missing');
}
if (empty($html)) {
    email_tools_fatal_error('html body missing');
}
if (!is_array($merge_vars)) {
    $merge_vars = array();
    email_tools_warn('merge vars was not an array');
}

if (is_string($to)) {
    $merge_vars = array($to => $merge_vars);
    $to = array($to);
}

$count = 0;
$errors = array();
while (!empty($to)) {
    $result = emailer_bulk($to, $subject, $html, $text, $merge_vars);
    $count += $result['count'];
    $errors = array_merge($errors, $result['errors']);
}

$response = email_tools_response();
$response['data']['count'] = $count;
$response['warnings'] = $errors;

response_send_json($response);
