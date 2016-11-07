<?php

namespace VSAC;

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
<hr>
<p>The full HTML body:</p>
<?php echo $result['data']['html'] ?>

<p>The full text body:</p>
<pre><?php echo $result['data']['text'] ?></pre>

<?php


