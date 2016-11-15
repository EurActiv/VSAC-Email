<?php

namespace VSAC;

if (!auth_is_authenticated()) {
    ?><p>You must log in to use this resource.</p><?php
}

?>

<p>Test a page that you are developing against this email.</p>

<?php

$source = request_post(
    'send_source',
    router_plugin_url(realpath(__DIR__ . '/../01-builder/email.html'), true)
);
if (!filter_var($source, FILTER_VALIDATE_URL)) {
    $source = '';
}

$template = request_post(
    'send_template',
    router_plugin_url(realpath(__DIR__ . '/../../templates/email.html'), true)
);
if (!filter_var($template, FILTER_VALIDATE_URL)) {
    $template = '';
}

$email_to = request_post('email_to', '');
if (!filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
    $email_to = '';
}

$data = array(
    'source'            => $source,
    'template'          => $template,
    'email_to'          => $email_to,
    'content_selector'  => request_post('send_content_selector', '//body'),
    'template_selector' => request_post('send_template_selector', '//body'),
    'max_image_width'   => intval(request_post('send_max_image_width', '0')),
);

form_form(
    array(
        'method' => 'post',
        'id'     => 'send-test',
    ),
    function () use ($data) {
        ?><div class="row">
            <div class="col-sm-8"><?php
                form_textbox($data['source'], 'source', 'send_source');
            ?></div><div class="col-sm-4"><?php
                form_textbox($data['content_selector'], 'content_selector', 'send_content_selector');
            ?></div><div class="col-sm-8"><?php
                form_textbox($data['template'], 'template', 'send_template');
            ?></div><div class="col-sm-4"><?php
                form_textbox($data['template_selector'], 'template_selector', 'send_template_selector');
            ?></div><div class="col-sm-4"><?php
                form_textbox($data['max_image_width'], 'max_image_width', 'send_max_image_width', '', 'number');
            ?></div><div class="col-sm-4"><?php
                form_textbox($data['email_to'], 'email_to', 'email_to');
            ?></div><div class="col-sm-4 text-right"><br><?php
                form_submit();
            ?></div>
        </div><?php
    }, function () use ($data) {
        $err = function ($msg) {
            printf(
                '<p class="bg-danger">%s</p>',
                call_user_func_array('sprintf', func_get_args())
            );
        };
        $required = array('source', 'template', 'email_to', 'content_selector', 'template_selector');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $err('Cannot send without field "%s"', $field);
            }
        }
        $data['api_key'] = config('api_key', '');
        $builder_url = router_add_query(router_plugin_url('builder.php', true), $data);
        use_module('http');
        $response = http_get($builder_url);
        if ($response['error']) {
            return $err('Builder HTTP Error: %s', $response['error']);            
        }
        $response = json_decode($response['body'], true);
        if (!empty($response['error'])) {
            return $err('Builder Error: %s', $response['error']);            
        }
        $required = array('html', 'text', 'subject');
        foreach ($required as $field) {
            if (empty($response['data'][$field])) {
                return $err('Builder Response problem: missing data[%s]', $field);
            }
        }
        extract($response['data'], EXTR_SKIP);
        $sent = emailer_single($data['email_to'], $subject, $html, $text);
        if ($sent !== true) {
            return $err('Emailer send error: ' . $sent);
        }
        return form_flashbag('Test email sent');
    }
);





