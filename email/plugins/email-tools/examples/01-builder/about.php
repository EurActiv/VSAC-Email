<?php

namespace VSAC;


?>

<p>The HTML to Email method takes HTML input and transforms it so that it is save
    to send as an HTML body.  It will generate both a rich text body and an
    alternate text body.</p>
<?= backend_code(router_plugin_url('builder.php')) ?>
<?php if (auth_is_authenticated()) {

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
    ?><hr><p>See how it works on an example:
        <a href="<?= $raw_url ?>">Raw (Unprocessed) HTML</a> |
        <a href="<?= $json_url ?>">Full JSON response</a> |
        <a href="<?= $html_url ?>">Email HTML</a> |
        <a href="<?= $text_url ?>">Email Text</a>
    </p><hr><?php

} ?>
<table class="table table-bordered">
    <tr><th>Parameter</th><th>Type</th><th>Value</th></tr>
    <tr>
        <td><code>api_key</code></td>
        <td>string</td>
        <td>The server-to-server API key.</td>
    </tr><tr>
        <td><code>source</code></td>
        <td>URL</td>
        <td>The HTML to process.</td>
    </tr><tr>
        <td><code>max_image_width</code></td>
        <td>integer</td>
        <td>If set, will embed images in the email message instead of simply
            rebasing them to point to the original source. Images wider than this
            number will be resized down.</td>
    </tr><tr>
        <td><code>content_selector</code></td>
        <td>CSS or XPath selector</td>
        <td>Complex webpages often have a lot of stuff you don't want to send
            in the email. If this selector is set, it will extract only that
            content for building the email. Optional, default <code>//body</code></td>
    </tr><tr>
        <td><code>template</code></td>
        <td>URL</td>
        <td>The email template to inject content into. This setting is optional;
            by default it uses a variant of the popular
            <a href="https://github.com/seanpowell/Email-Boilerplate">Email
            Boilerplate</a> template, which should be OK for most applications.</td>
    </tr><tr>
        <td><code>template_selector</code></td>
        <td>CSS or XPath selector</td>
        <td>If using a custom template, the selector to inject the content
            into. Defaults to <code>//body</code>.</td>
    </tr><tr>
        <td><code>format</code></td>
        <td>html or text</td>
        <td>For debugging, return only the format requested as a webpage.</td>
    </tr>
</table>

<p><b>NOTE:</b> The API can result in very long query strings in your URLs.
    Therefore, you may experience failures if you are sending data as raw
    HTML.  For this reason, the endpoint will accept both <code>GET</code>
    requests with the data in the query or <code>POST</code> requests with
    the data in the request body.</p>

<p>The API will return a JSON object with two offsets:
<table class="table table-bordered">
    <tr><th>Key</td><th>Type</th><th>Value</th></tr>
    <tr>
        <td><code>html</code></td>
        <td>string</td>
        <td>The HTML body of the email</td>
    </tr><tr>
        <td><code>text</code></td>
        <td>string</td>
        <td>The alternate text body.</td>
    </tr><tr>
        <td><code>error</code></td>
        <td>string</td>
        <td>If there was a fatal processing error, it will be stored here. It
            will be an empty string if there was no error.</td>
    </tr><tr>
        <td><code>warnings</code></td>
        <td>array</td>
        <td>Non-fatal processing errors</td>
    </tr><tr>
        <td><code>transformations</code></td>
        <td>array</td>
        <td>A list of transformations made. Useful for debugging.</td>
    </tr>
</table>

<h4>Media queries and device targetting</h4>
<p>By default most styles except for styles inside media queries will be
    inlined. Media queries should only be used to target devices. For reference,
    some useful media queries are:</p>
Style tags with the "scoped" attribute will not be moved/inlined
[if gte mso 9] conditional comments
[if IEMobile 7]


preserve will be preserved in the document 

generic mobile
@media only screen and (max-device-width: 480px)
tablets
@media only screen and (min-device-width: 768px) and (max-device-width: 1024px) {



        @media only screen and (-webkit-min-device-pixel-ratio: 2) {
            /* Put your iPhone 4g styles in here */
        }
        /* Following Android targeting from:
        http://developer.android.com/guide/webapps/targeting.html
        http://pugetworks.com/2011/04/css-media-queries-for-targeting-different-mobile-devices/  */
        @media only screen and (-webkit-device-pixel-ratio:.75){
            /* Put CSS for low density (ldpi) Android layouts in here */
        }
        @media only screen and (-webkit-device-pixel-ratio:1){
            /* Put CSS for medium density (mdpi) Android layouts in here */
        }
        @media only screen and (-webkit-device-pixel-ratio:1.5){
            /* Put CSS for high density (hdpi) Android layouts in here */
        }
