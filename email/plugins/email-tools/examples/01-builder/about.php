<?php

namespace VSAC;


?>

<p>The HTML to Email method takes HTML input and transforms it so that it is save
    to send as an HTML body.  It will generate both a rich text body and an
    alternate text body.</p>
<?= backend_code(router_plugin_url('builder.php')) ?>

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
