<?php

namespace VSAC;

if (!auth_is_authenticated()) {
    ?><p>You must log in to use this resource.</p><?php
}

?>

<p>Test a page that you are developing against this email.</p>

<?php

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





