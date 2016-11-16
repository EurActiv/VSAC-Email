<?php

namespace VSAC;


?>

<h3>Test a page in development</h3>
<hr>
<p>To see what a page you are working on will look like when converted to EMail
    html, upload it online somewhere and enter the URL below. The app will
    download and format it, and show you how it displays.</p>

<?php

if (auth_is_authenticated()) {
    form_form(
        array(
            'method' => 'get',
            'action' => router_plugin_url('builder.php'),
            'target' => '_blank',
        ),
        function () {
            form_hidden(config('api_key', ''), 'api_key', 'api_key');
            ?><div class="row">
                <div class="col-sm-8"><?php
                    form_textbox('http://', 'url', 'source');
                ?></div><div class="col-sm-4"><?php
                    form_textbox('//body', 'content_selector', 'content_selector');
                ?></div><div class="col-sm-8"><?php
                    form_textbox('', 'template', 'template');
                ?></div><div class="col-sm-4"><?php
                    form_textbox('//body', 'template_selector', 'template_selector');
                ?></div><div class="col-sm-4"><?php
                    form_textbox('0', 'max_image_width', 'max_image_width', '', 'number');
                ?></div><div class="col-sm-4"><?php
                    $formats = array('json' => 'json', 'html' => 'html', 'text' => 'text');
                    form_selectbox($formats, 'json', 'format', 'format');
                ?></div><div class="col-sm-4 text-right"><br><?php
                    form_submit();
                ?></div>
            </div><?php
        }
    );
} else {
    ?><p>Log in to use the email template tester function.</p><?php
}


?>
<hr>
<h3>Limitations and quirks</h3>
<hr>


<h4>Inline vs Head styles</h4>
<p>Almost all CSS will be applied inline. There are two ways to force CSS rules
    into <code>style</code> tags in the document head:</p>
<ul>
    <li>Add a <code>scoped="scoped"</code> attribute to the style element. These
        will be preserved as-is, although the scoped attribute will be
        removed.</li>
    <li>Wrap the ruleset in a media query.  All media queries will be preserved
        in the document head.</li>
</ul>
<hr>


<h4>Padding.</h4>
<p>Padding does not work reliably in email. If this tool finds any padding
    declarations, it will be added to the element's margin. To implement
    pseudo-padding, you need to add a wrapper element and apply the padding to
    the inner element as margin.</p>
<hr>


<h4>CSS Specificity and <code>!important</code> do not work</h4>
<p>CSS rules will be appied in the order they are encountered, not by
    specificity. For example, consider this ruleset:</p>
<pre>.white {background-color: white;}
div {background-color: grey;}</pre>
<p>In a browser, an element <code>div.white</code> would have a white
    background. In this tool, it will have grey background. In practice, this
    limitation does not cause too many problems since CSS is usually written more
    or less in order of specificity, but it can be a source of unexpected
    results.</p>
<hr>


<h4>Table cell margin/padding.</h4>
<p>Padding and margin on table cells (td) does not work reliably in email.  Cell
    padding should be set with the <code>cellpadding</code> attribute on the
    parent table. As a convenience, the application understands a non-standard
    <code>border-padding</code> css property on tables that will be converted to
    cellpadding.</p>
<hr>


<h4>Media queries and device targetting</h4>
<p>Many devices can be targetted with media queries.  Some useful queries
    include:</p>
<pre>/* generic mobile */
@media only screen and (max-device-width: 480px) {}

/* tablets */
@media only screen and (min-device-width: 768px) and (max-device-width: 1024px) {}

/* High density (hdpi) iPhones with retina display */
@media only screen and (-webkit-min-device-pixel-ratio: 2) {}

/* High density (hdpi) android */
@media only screen and (-webkit-device-pixel-ratio:1.5){}

/* Medium density (mdpi) android */
@media only screen and (-webkit-device-pixel-ratio:1){}

/* Low density (ldpi) android */
@media only screen and (-webkit-device-pixel-ratio:.75){}</pre>
<p>Targetting Outlook and IE mobile can be done with conditional comments, which
    will be preserved as-is in the resulting email. A conditional comment
    would look like:
<pre>&lt;--[if {expression}]&gt;
    &lt;style type="text/css"&gt;
        /* MS-specific styles here */
    &lt;/style&gt;
&lt;![endif]--&gt;</pre>
<p>Some useful expressions:</p>
<ul>
    <li><code>[if gte mso 9]</code>: Outlook 2000 and above</li>
    <li><code>[if (gte mso 9)&(lte mso 11)]</code>: Outlook 2000-2003, using
        the IE6 rendering engine.</li>
    <li><code>[if (gte mso 12)&(lte mso 15)]</code>: Outlook 2007 and above,
        using the Microsoft Word rendering engine.</li>
    <li><code>[if mso 16]</code>: Outlook 2016 and the mail reader app on
        Windows Phone 8 and 10.</li>
    <li><code>[if IEMobile 7]</code>: Older windows mobile</li>
</ul>




