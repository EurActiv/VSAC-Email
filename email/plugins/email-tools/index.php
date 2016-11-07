<?php

namespace VSAC;

use_module('backend-all');

function email_tools_send_test()
{
    $err = function ($msg) {
        printf('<div class="well"><p>%s</p></div>', $msg);
    };
    $builder_url = router_add_query(
        router_plugin_url('builder.php', true),
        array(
            'api_key' => config('api_key', ''),
            'source'  => router_plugin_url(__DIR__ . '/examples/01-builder/email.html', true),
        )
    );
    $built = http_get($builder_url);
    if ($built['error']) {
        return $err('HTTP Error in builder');
    }
    $built = json_decode($built['body'], true);
    if ($built['error']) {
        return $err('Builder error: ' . $built['error']);
    }
    $data = $built['data'];
    foreach (['subject', 'text', 'html'] as $d) {
        if (empty($data[$d])) {
            return $err('Builder error: missing field ' . $d);
        }
    }
    $data['to'] = config('email_tools_test_address', '');
    $data['merge_vars'] = array(
        'name'         => 'Your Name',
        'merge_string' => 'This is a merged variable. Check that the link goes to example.com.',
        'merge_url'    => 'http://example.com',
    );
    $data['api_key'] = config('api_key', '');
    $response = http_post(router_plugin_url('push.php', true), $data);
    if ($response['error']) {
        return $err('HTTP error in push');
    }
    $response = json_decode($response['body'], true);
    if ($response['error']) {
        return $err('Error during push: ' . $response['error']);
    }
    echo '<div class="well"><p>The push service responded:</p>';
    printR($response);
    echo '<p>Now check that the email actually arrived.</p></div>';
}


backend_head('Email Tools');

if (auth_is_authenticated()) {
    if (isset($_GET['send_test'])) {
        email_tools_send_test();
    }
}


?>
<p>This plugin provides a set of tools for transforming working with email. The
    goal is to abstract the difficulties away from other web development
    techniques.</p>
<?php

docs_examples();
log_file_viewer();
backend_config_table();


backend_foot();
             
