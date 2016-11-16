<?php

namespace VSAC;

if (auth_is_authenticated()) {

    $raw_url = router_plugin_url(__DIR__ . '/email.html', true);
    $json_url = router_add_query(
        router_plugin_url('builder.php'),
        array(
            'api_key' => config('api_key', ''),
            'source'  => $raw_url,
        )
    );
    $html_url = router_add_query($json_url, ['format' => 'html']);
    $text_url = router_add_query($json_url, ['format' => 'text']);
    ?><hr><p>Complete response links:
        <a href="<?= $raw_url  ?>" target="_blank">Raw (Unprocessed) HTML</a> |
        <a href="<?= $json_url ?>" target="_blank">Full JSON response</a> |
        <a href="<?= $html_url ?>" target="_blank">Email HTML</a> |
        <a href="<?= $text_url ?>" target="_blank">Email Text</a>
    </p><hr><?php

}

$format = function ($var) use (&$format) {
    if (is_array($var)) {
        return array_map($format, $var);
    }
    $var = preg_replace('/\s+/', ' ', $var);
    if (strlen($var) > 75) {
        $var = substr($var, 0, 72) . '...';
    }
    return htmlspecialchars($var);
};

$qp_public = $post_data = array(
    'api_key' => config('api_key', ''),
    'source'  => router_plugin_url(__DIR__ . '/email.html', true)
);

if (!auth_is_authenticated()) {
    $qp_public['api_key'] = '-private-';
}

$url_base = router_plugin_url('builder.php', true);

// note: using http_post is kind of a test
$result = http_post($url_base, $post_data);
$result = json_decode($result['body'], true);
$result_formatted = $format($result);

?><pre>
&lt;?php

$url = '<?= $url_base ?>?' . http_build_query(<?= var_export($qp_public, true) ?>);
$result = json_decode(file_get_contents($url), true);

</pre>
<p><code>$result</code> now contains:</p>
<pre><?php var_export($result_formatted) ?></pre>



