<?php

namespace VSAC;

function email_tools_builder_style_collector($add = null)
{
    static $styles = array();
    if (is_array($add)) {
        $styles = csstoolsexp_merge_css_arrays($styles, $add);
    }
    return $styles;
}


function email_tools_builder_preprocess(&$html, $base_url, $max_img_width)
{
    domtools_load($html);
    domtools_remove_elements('//script');
    domtools_base_url($base_url);
    domtools_rebase();
    domtools_inline('//link[@rel="stylesheet" and @href]');
    if ($max_img_width) {
        domtools_inline('//img[@src]');
    } else {
        domtools_rebase('//img[@src]');
    }
    foreach (domtools_remove_elements('//style') as $s) {
        $style = $s['element']->nodeValue;
        $style = csstoolsexp_css_to_array($style, $base_url, $max_img_width);
        if ($s['element']->hasAttribute('scoped')) {
            $s['element']->nodeValue = csstoolsexp_array_to_css($style);
            $s['element']->removeAttribute('scoped');
            domtools_add_elements([$s]);
        } else {
            email_tools_builder_style_collector($style);
        }
    }
    $html = domtools_content();
}

function email_tools_builder_css_measures_to_px(&$declarations)
{
    $convert = array(
        'border-top-width', 'border-right-width', 'border-bottom-width',
        'border-left-width',
        'border-spacing', 'border-padding',
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'font-size', 'line-height',
    );
    $multipliers = array(
        'em' => 16, 'cm' => 38, 'ex' => 16, 'in' => 96, 'mm' => 3.8, 'pc' => 16,
        'pt' => 1.33, 'vh' => (16/100), 'vw' => (16/100), 'vmin' => (16/100)
    );
    foreach ($convert as $property) {
        if (isset($declarations[$property])) {
            $value = $declarations[$property];
            $num = floatval(preg_replace('/[^\d\.]/', '', $value));
            $unit = preg_replace('/[\d\.]/', '', $value);
            if (isset($multipliers[$unit])) {
                email_tools_warn('Unit "%s" is not email safe, converting to px', $unit);
                $num *= $multipliers[$unit];
                $declarations[$property] = $num ? round($num) . 'px' : '0';
            }
        }
    }
}

function email_tools_builder_css_merge_padding(&$declarations)
{
    $sides = array('-top', '-left', '-bottom', '-right');
    foreach ($sides as $s) {
        if (empty($declarations['padding' . $s])) {
            continue;
        }
        email_tools_warn('Padding is not email safe; merging to margin');
        $p = intval($declarations['padding' . $s]);
        $m = empty($declarations['margin' . $s]) ? 0 : intval($declarations['margin' . $s]);
        $declarations['margin' . $s] = ($p + $m) . 'px';
        $declarations['padding' . $s] = '0';
    }
}

function email_tools_builder_css_remove_unsafe(&$declarations)
{
    $safe = array(
        'font-', 'line-height', 'text-align', 'text-decoration', 'text-indent',
        'text-transform', 'vertical-align', 'background-color', 'border-', 'margin-',
        'width', 'z-index', 'list-style-type', 'color', 'height', 'display',
        // vendor prefixes are usually deliberate
        '-webkit-', '-moz-', '-ms-', '-o-', 'mso-',
        // specially treated
        'padding-', 'border-spacing', 'border-collapse', 'border-padding',
    );
    foreach (array_keys($declarations) as $property) {
        $is_safe = false;
        foreach ($safe as $s) {
            if (strpos($property, $s) === 0) {
                $is_safe = true;
                break;
            }
        }
        if (!$is_safe) {
            email_tools_warn('CSS property %s is not email safe; removing.', $property);
            unset($declarations[$property]);
        }
    }
}

//-- collect user input ------------------------------------------------------//


if (!apikey_is_valid()) {
    email_tools_fatal_error('invalid or missing api key');
}


$params = array(
    'source'            => request_request('source'             , ''        ),
    'max_image_width'   => request_request('max_image_width'    , 0         ),
    'body_selector'     => request_request('content_selector'   , '//body'  ),
    'template'          => request_request('template'           , ''        ),
    'template_selector' => request_request('template_selector'  , '//body'  ),
);
extract($params);


//-- build HTML, inline image and extract style ------------------------//

$source_base_url = dirname($source) . '/';
$source_html = email_tools_get_html($source, 'Content HTML');

if (empty($template)) {
    $template = router_plugin_url(__DIR__ . '/templates/email.html', true);
    $template_selector = '#content';
}

$template_base_url = dirname($template) . '/';
$template_html = email_tools_get_html($template, 'Template HTML');

email_tools_builder_preprocess($template_html, $template_base_url, $max_image_width);
email_tools_builder_preprocess($source_html, $source_base_url, $max_image_width);
$titles = domtools_query('title,h1,h2,h3,h4,h4,h6');
email_tools_response_data(
    'subject',
    empty($titles) ? '' : trim(preg_replace('/\s+/', ' ', $titles[0]->textContent))
);


$source_html = domtools_content($body_selector);

//-- Build the alternate text body -------------------------------------------//


domtools_load($source_html);
$links = array();
domtools_loop(
    'h1 a, h2 a, h3 a, h4 a, h5 a, h6 a, p a, li a',
    function ($el) use (&$links) {
        $url = $el->getAttribute('href');
        if (!filter_var($url, FILTER_VALIDATE_URL)
            && !preg_match('/^\*\|[a-z_]+\|\*$/i', $url)
            && !preg_match('/^\*\%7C[a-z_]+\%7C\*$/i', $url)
        ) {
            // return;
        }
        if (!isset($links[$url])) {
            $links[$url] = count($links) + 1;
        }
        $txt = $el->ownerDocument->createTextNode(' [' . $links[$url] . ']');
        $el->appendChild($txt);
    }
);

$olcounter = 1;
$lines = domtools_loop('h1,h2,h3,h4,h5,h6,p,li', function ($el) use (&$olcounter) {
    $width = 64;
    $indented_wrap = function ($prefix, $txt) use ($width) {
        $len = strlen($prefix);
        $txt = wordwrap($txt, $width - $len);
        $txt = str_replace("\n", "\n" . str_repeat(' ', $len), $txt);
        return $prefix . $txt;
    };
    $text = preg_replace("/\s+/", ' ', $el->textContent);
    $tag = strtolower($el->tagName);
    if ($tag == 'li') {
        $parent = strtolower($el->parentNode->tagName);
        $tag = $parent == 'ol' ? 'ol.li' : 'ul.li';
    }
    $olcounter = $tag == 'ol.li' ? $olcounter + 1 : 0;
    switch ($tag) {
        case 'h1':
            $under = str_repeat('=', min($width, strlen($text)));
            $text = wordwrap($text, $width);
            return $under . "\n" . $text . "\n" . $under;
            break;
        case 'h2':
            $under = str_repeat('-', min($width, strlen($text)));
            $text = wordwrap($text, $width);
            return $text . "\n" . $under;
            break;
        case 'h3':
            return wordwrap(strtoupper($text), $width);
        case 'h4':
            return $indented_wrap('  ', strtoupper($text));
        case 'h5':
            return $indented_wrap('    ', strtoupper($text));
        case 'h6':
            return $indented_wrap('    ', strtoupper($text));
        case 'ul.li':
            return $indented_wrap('   - ', $text); 
        case 'ol.li':
            $prefix = ' ' . str_pad($olcounter, 2, ' ', STR_PAD_LEFT) . '. '; 
            return $indented_wrap($prefix, $text);            
        default:
            return wordwrap($text, $width);
    }
});

$text = implode("\n\n", $lines);
if (!empty($links)) {
    $text .= "\n\n" . str_repeat('-', 15);
    foreach ($links as $url => $ref) {
        // merge variable fix
        if (preg_match('/\*\%7C([a-z_]+)\%7C\*$/i', $url, $m)) {
            $url = '*|' . $m[1] . '|*';
        }
        $text .= "\n [{$ref}]: {$url}";
    }
}
$text = str_replace("\n", "\r\n", $text);
// line for debugging
if (request_request('format') == 'text') {
    response_header('Content-Type', 'text/plain');
    echo $text;
    exit;
}
email_tools_response_data('text', $text);


//-- Inject body into template -----------------------------------------------//

domtools_load($template_html);
domtools_set_content($template_selector, $source_html);
domtools_query('//title')[0]->nodeValue = email_tools_response()['data']['subject'];


//-- Normalize styles ---------------------------------------------------//
$styles = email_tools_builder_style_collector();
// apply inline styles
if (!isset($styles['all'])) {
    $styles['all'] = array();
}

foreach ($styles as $mq => &$ruleset) {
    foreach ($ruleset as $selector => &$declarations) {
        email_tools_builder_css_measures_to_px($declarations);
        email_tools_builder_css_merge_padding($declarations);
        if ($selector == 'all') {
            email_tools_builder_css_remove_unsafe($declarations);
        }
    }
    unset($declarations);
}
unset($ruleset);


//-- Apply styles that should be attributes ----------------------------------//

$style_attrs = array (
  'body.background-color'   => 'bgcolor',
  'div.text-align'          => 'align',
  'h1.text-align'           => 'align',
  'h2.text-align'           => 'align',
  'h3.text-align'           => 'align',
  'h4.text-align'           => 'align',
  'h5.text-align'           => 'align',
  'h6.text-align'           => 'align',
  'img.height'              => 'height',
  'img.width'               => 'width',
  'p.text-align'            => 'align',
  'table.background-color'  => 'bgcolor',
  'table.border-collapse'   => 'border',
  'table.border-padding'    => 'cellpadding',
  'table.border-spacing'    => 'cellspacing',
  'table.text-align'        => 'align',
  'table.width'             => 'width',
  'td.background-color'     => 'bgcolor',
  'td.height'               => 'height',
  'td.text-align'           => 'align',
  'td.vertical-align'       => 'valign',
  'td.width'                => 'width',
  'th.background-color'     => 'bgcolor',
  'th.height'               => 'height',
  'th.text-align'           => 'align',
  'th.vertical-align'       => 'valign',
  'th.width'                => 'width',
  'tr.background-color'     => 'bgcolor',
  'tr.text-align'           => 'align',

  'th.padding-top'          => 'td_error',
  'th.padding-right'        => 'td_error',
  'th.padding-bottom'       => 'td_error',
  'th.padding-left'         => 'td_error',
  'th.margin-top'           => 'td_error',
  'th.margin-right'         => 'td_error',
  'th.margin-bottom'        => 'td_error',
  'th.margin-left'          => 'td_error',
  'td.padding-top'          => 'td_error',
  'td.padding-right'        => 'td_error',
  'td.padding-bottom'       => 'td_error',
  'td.padding-left'         => 'td_error',
  'td.margin-top'           => 'td_error',
  'td.margin-right'         => 'td_error',
  'td.margin-bottom'        => 'td_error',
  'td.margin-left'          => 'td_error',

);

$pxm = function ($v) {
    if (is_numeric($v) || substr($v, -2) == 'px') {
        return intval($v);
    }
};
$style_attr_transformers = array(
    'bgcolor'     => function ($v) { return $v; },
    'align'       => function ($v) { return $v; },
    'height'      => $pxm,
    'width'       => $pxm,
    'border'      => function ($v) { return $v == 'separate' ? '1' : '0'; },
    'cellspacing' => $pxm,
    'cellpadding' => $pxm,
    'valign'      => function ($v) { return $v; },
    'td_error'    => function ($v) {
                        email_tools_warn('Padding and margin on table cells is
                                not safe in email. Either set the cellpadding
                                property or use the custom ".border-padding" css
                                attribute on the table instead.');
                        return null;
                     } 
);

foreach ($styles['all'] as $selector => &$declarations) {
    $unset = array();
    domtools_loop(
        $selector,
        function ($el) use ($declarations, &$unset, $style_attrs, $style_attr_transformers) {
            $name = strtolower($el->tagName);
            foreach ($declarations as $prop => $val) {
                if (isset($style_attrs[$name . '.' . $prop])) {
                    $attr = $style_attrs[$name . '.' . $prop];
                    $transformer = $style_attr_transformers[$attr];
                    if (null !== ($val = $transformer($val))) {
                        $el->setAttribute($attr, $val);
                        $unset[] = $prop;
                    }
                }
            }
        }
    );
    foreach($unset as $prop) {
        unset($declarations[$prop]);
    }
}
unset($declarations);


//-- Insert styles that need to go into the "style" attribute ----------------//

// insert
foreach ($styles['all'] as $selector => $declarations) {
    $decls = '';
    foreach ($declarations as $property => $value) {
        $decls .= $property . ': ' . $value . ';';
    }
    domtools_loop($selector, function ($el) use ($decls) {
        $style = trim($el->getAttribute('style'));
        if ($style && substr($style, -1) != ';') {
            $style .= ';';
        }
        $el->setAttribute('style', $style . $decls);
    });
}
// and minify
domtools_loop('//*[@style]', function ($el) {
    $style = $el->getAttribute('style');
    $style = csstoolsexp_parse_declaration_string($style);
    $style = csstoolsexp_declarations_join($style);
    $el->setAttribute('style', $style);    
});

//-- Insert head style -------------------------------------------------------//

unset($styles['all']);

$head_styles = csstoolsexp_array_to_css($styles);
$head = domtools_query('//head')[0];
$style = $head->ownerDocument->createElement('style', $head_styles);
$head->appendChild($style);


//-- A couple of other fixes -------------------------------------------------//

// add class to telephone links to undo the reset in the default template
domtools_loop('a[href^="tel"],a[href^="sms"]', function ($el) {
    $classes = array_filter(explode(' ', $el->getAttribute('class')));
    if (!in_array('vsac-telephone', $classes)) {
        $classes[] = 'vsac-telephone';
    }
    $el->setAttribute('class', implode(' ', $classes));
});
// add !important to headline link colors, for hotmail (yahoo?)
domtools_loop('h1 a, h2 a, h3 a, h4 a, h5 a, h6 a', function ($el) {
    if ($style = $el->getAttribute('style')) {
        $style = preg_replace('/(color:\([^;]+\));/', '$1 !important;', $style);
        $el->setAttribute($style);
    }
});

//-- Format content for email delivery ---------------------------------------//
domtools_remove_elements('//comment()');
domtools_remove_elements('//script');

// this is a bug in domtools, where merge variables in urls get rebased and
// urlencoded fix it here.
domtools_loop('a', function ($el) {
    $href = $el->getAttribute('href');
    if (preg_match('/\*\%7C[a-z_]+\%7C\*$/i', $href, $m)) {
        $el->setAttribute('href', $m[0]);
    }
});
// another bug in domtools that causes data urls to be rebased
domtools_loop('img', function ($el) {
    $src = $el->getAttribute('src');
    if (preg_match('/data\:[a-z\/\-]+;base64,[.\n]*$/i', $src, $m)) {
        $el->setAttribute('src', $m[0]);
    }
});


$content = domtools_content();

// part two of the merge variable fix
$content = preg_replace('/\*\%7C([a-z_]+)\%7C\*/i', '*|$1|*', $content);

$content = preg_replace('/\s+/', ' ', $content);
$content = preg_replace_callback('/<[^>]+>/', function ($m) {
    return str_replace(' ', '#°#°#', $m[0]);
}, $content);

$content = wordwrap($content, 85);
$content = str_replace("\n", "\r\n", $content);
$content = str_replace('#°#°#', ' ', $content);

// line for debugging
if (request_request('format') == 'html') {
    response_header('Content-Type', 'text/html');
    echo $content;
    exit;
}

email_tools_response_data('html', $content);



response_send_json(email_tools_response());






