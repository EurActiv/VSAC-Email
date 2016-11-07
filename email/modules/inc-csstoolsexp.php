<?php

/**
 * This module provides some extended functions for working with CSS. Advantages
 * over the Compass-based "csstool"s module:
 *
 *  - you can work with strings without having to save them to disk
 *  - it has facilites for parsing and manipulating css in PHP
 *
 * It is in the experimental repository because it:
 *
 *   - isn't very well tested and may have bugs
 *   - does some things that are wierd by design; need to think about whether
 *     they're good ideas
 *   - it's got some memory leaks. They're not that big a deal since they're
 *     cleared on request shut down, but it might still need fixing.
 *
 * The module uses three formats for processing CSS:
 *
 * 1. $css: normal, browser parsable css
 * 
 * 2. $css_pythonified: CSS with the {}; notation converted to newlines and tabs.
 * It's wierd, but it makes string manipulations a lot easer.  It looks something
 * like this:
 *
 *     all                   # the media queries are at indent level 0
 *         div               # the selectors are at indent level 1
 *             display:block # the declarations are at indent level 2
 *
 * A default media query of "all" is added to media queries that don't have
 * them.
 * 
 * The parser is smart enough to handle nested media queries; they float to the
 * top, for example:
 *
 *     @media screen {
 *         .my-class { display:block }
 *         @media (max-width: 600px) {
 *             .my-class { display : none }
 *         }  
 *     }
 *
 * Will be pythonified as:
 *
 *     screen
 *         .my-class
 *             display:block
 *     (screen) and (max-width: 600px)
 *         .my-class
 *             display:none
 *
 *
 * 3. $css_array: same layout as $css_pythonified, but in an array:
 *
 *     array(
 *         'all' => array(
 *             'div' => array(
 *                 'display' => 'block'
 *             )
 *         )
 *     )
 *
 * These functions will convert between the formats:
 *
 *     - csstoolsexp_css_to_pythonified()
 *     - csstoolsexp_css_to_array()
 *     - csstoolsexp_pythonifed_to_css()
 *     - csstoolsexp_pythonified_to_array()
 *     - csstoolsexp_array_to_pythonified()
 *     - csstoolsexp_array_to_css()
 */

namespace VSAC;

//---------------------------------------------------------------------------//
//-- Framework required functions                                          --//
//---------------------------------------------------------------------------//

/** @see example_module_dependencies() */
function csstoolsexp_depends()
{
    return array('filesystem', 'http', 'image');
}


/** @see example_module_sysconfig() */
function csstoolsexp_sysconfig()
{
    if (strpos(strtolower(PHP_OS), 'win') === 0) {
        return 'A *nix server is required';
    }
    if (!exec('which compass')) {
        return 'compass not installed (http://compass-style.org/)';
    }
    $version = extract_version('compass version');
    if (version_compare($version, '1.0', '<')) {
        return 'compass >= 1.0 required (http://compass-style.org/)';
    }
    return true;
}

/** @see example_module_config_options() */
function csstoolsexp_config_options()
{
    return array();
}

/** @see example_module_test() */
function csstoolsexp_test()
{
    $rmspace = function ($str) {
        return preg_replace('/\s+/', '', $str);
    };
    $in = '
        /** hello world! */
        @media screen {
            div { color: red; }
        }
        @media all {
            div {
                color: yellow;
                background: #000 url(images/bg.gif) no-repeat fixed top right;
                font: italic bold .8em/1.2 Arial, sans-serif;
                border: 1px solid green;
                border-left: 3px dotted red;
                border-right-width:0;
            }
            @media print {
                div { color: orange; }
            }
        }
        div,span {
            color: blue;
            text-decoration: underline
        }
    ';
    $expected = '
        @media screen {
            div {color : red}
        }
        div {
            font : italic bold .8em Arial, sans-serif;
            background : #000 url(images/bg.gif) no-repeat fixed top right;
            border-left : 3px dotted red;
            border : 1px solid green;
            color : blue;
            line-height : 1.2;
            border-right-width : 0;
            text-decoration : underline
        }
        span {
            color : blue;
            text-decoration : underline
        }            
        @media print and (all) {
            div { color : orange }
        }
    ';
    $parsed = csstoolsexp_parse_string($in);
    $out = csstoolsexp_build_string($parsed, true);
    if ($rmspace($expected) != $rmspace($out)) {
        $msg = "parse/build failed. Expected: \n<pre>%s</pre>\n, Got: \n<pre>%s</pre>\n";
        return sprintf($msg, $expected, $out);
    }
    return true;
}


//----------------------------------------------------------------------------//
//--  Public API                                                            --//
//----------------------------------------------------------------------------//

//-- Utilities ---------------------------------------------------------------//


/**
 * Minify an string of css; uses string manipulations so potentially less
 * reliable than csstoolsexp_minify();
 *
 * @param string $css the css to minify
 *
 * @return string
 */
function csstoolsexp_minify_string($css, $base_url = false, $max_image_width = 700)
{
    $css = csstoolsexp_inline($css, $base_url, $max_image_width);
    $pythonified = csstoolsexp_css_to_pythonified($css);
    $css = csstoolsexp_pythonified_to_css($pythonified);
    return $css;
}


/**
 * Rebase relative URLs in a CSS string
 *
 * @param string $css the css string
 * @param string $base_url the new base url
 *
 * @return string
 */
function csstoolsexp_rebase($css, $base_url)
{
    $css = preg_replace_callback(
        '/\s+url\([ \'"]+([^\"\')]+)[ \'"]+\)/',
        function ($match) use ($base_url) {
            if ($_url = csstoolsexp_best_url($match[1], $base_url)) {
                return ' url(' . $url . ')';
            }
            return $match[0];
        },
        $css
    );
    return $css;
}

/**
 * Inline imports and images.
 *
 * @param string $css the CSS to treat
 * @param string $base_url 
 * @param bool $max_image_width downsize images to this width
 */
function csstoolsexp_inline($css, $base_url = false, $max_image_width = 0)
{
    $css = csstoolsexp_rebase($css, $base_url);
    $css = preg_replace_callback(
        '/\@import\s+url\(([^\)]*)\)(\s+[^;]+)?;/',
        function ($match) use ($base_url, $max_image_width) {
            $url = trim(trim($match[1]), '"\'');
            $response = http_get($url);
            if ($response['error']) {
                return $match[0];
            }
            $import = csstoolsexp_inline(
                $response['body'],
                dirname($url) . '/',
                $max_image_width
            );
            if (empty($match[2])) {
                return $import;
            } else {
                return sprintf(' @media %s { %s } ', $match[2], $import);
            }
        },
        $css
    );
    $css = preg_replace_callback(
        '/\s+url\([ \'"]+([^\)]+)[ \'"]+\)/',
        function ($match) use ($base_url, $max_image_width) {
            $url = trim(trim($match[1]), '"\'');
            $response = http_get($match[1]);
            if ($response['error']) {
                return $match[0];
            }
            $uri = image_data_uri($response['body'], $image_max_width);
            return ' url("' . $uri . '")';
        },
        $css
    );
    return $css;
}


/**
 * Parse a string of CSS into an array. Returns the $css_array format. Will
 * inline images and imports.
 *
 * @param string $css
 * @param string $base_url for fetching relative urls
 * @param string $max_img_width the maximum width of images
 *
 * @return array
 */
function csstoolsexp_parse_string($css, $base_url = false, $max_img_width = 700)
{
    // remove comments http://stackoverflow.com/a/3984887/1459873
    $css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!' , '' , $css);
    // normalize white space
    $css = preg_replace('/\s+/', ' ', $css);
    // process urls
    $css = csstoolsexp_inline($css, $base_url, $max_img_width);
    // now for the parsing...
    $css_array = csstoolsexp_css_to_array($css);
    return $css_array;
}

/**
 * The inverse of csstoolsexp_parse_string. Takes the array and returns a string.
 * Essentially an alias of csstoolsexp_array_to_css()
 *
 * @param array $css_array
 *
 * @return string the css
 */
function csstoolsexp_build_string($css_array, $pretty = false)
{
    return csstoolsexp_array_to_css($css_array, $pretty);
}

/**
 * Merge two css arrays
 *
 * @param array $css_array1
 * @param array[... $css_array2
 *
 * @return array
 */
function csstoolsexp_merge_css_arrays($css_array1, $css_array2)
{
    $arrays = func_get_args();
    $css_array = array_shift($arrays);
    while (null !== ($arr = array_shift($arrays))) {
        foreach ($arr as $media_query => $rulesets) {
            if (empty($css_array[$media_query])) {
                $css_array[$media_query] = array();
            }
            foreach ($rulesets as $selector => $declarations) {
                if (empty($css_array[$media_query][$selector])) {
                    $css_array[$media_query][$selector] = array();
                }
                $css_array[$media_query][$selector] = array_merge(
                    $css_array[$media_query][$selector],
                    $declarations
                );
            }
        }
    }
    return $css_array;
}

/**
 * Split a set of CSS declarations into an array of key:value declarations. For
 * example:
 *
 *     csstoolsexp_parse_declaration_string('
 *         color: red; margin: 0 4px; color:blue
 *     ');
 *
 *     array(
 *         'color'         => 'blue',
 *         'margin-top'    => '0',
 *         'margin-right'  => '4px',
 *         'margin-bottom' => '0',
 *         'margin-left'   => '4px',
 *     );
 *
 * @param string $rules
 *
 * @return array 
 */
function csstoolsexp_parse_declaration_string($declarations)
{
    $return = array();
    $declarations = csstoolsexp_tokenize_exec($declarations, ';', function ($str) {
        return array_map('trim', explode(';', $str));
    });
    foreach ($declarations as $declaration) {
        csstoolsexp_parse_declaration($declaration, $return);
    }
    return $return;
}





//-- Converting css to manipulation formats (string, pythonified, array ------//


/**
 * Convert CSS into a the "pythonified"
 *
 * @param string $css the css to transform
 *
 * @return string the pythonified css
 */
function csstoolsexp_css_to_pythonified($css)
{
    // remove comments http://stackoverflow.com/a/3984887/1459873
    $css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!' , '' , $css);
    // normalize white space
    $css = trim(preg_replace('/\s+/', ' ', $css));

    // replace @imports with placeholders
    $css = preg_replace_callback('/\@import [^;];/', function ($m) {
        return csstoolsexp_at_line_fix($m[0]) . ' { }';
    }, $css);
    $css = trim($css);
    // replace {} with tab indentation
    $_css = array();
    $indent = 0;
    while (preg_match('/^([^\{\}]*)([\{\};])/', $css, $m)) {
        $css = trim(substr($css, strlen($m[0])));
        $_css[] = str_repeat("\t", $indent) . $m[1];
        if ($m[2] == '}') {
            $indent -= 1;
        } else {
            $indent += 1;
        }
    }
    if (trim($css)) {
        $_css[] = str_repeat("\t", $indent) . $css;
    }
    // float nested media queries to the top
    $outdent = 0;
    $media = '';
    foreach($_css as &$line) {
        if (strpos($line, '@media ') === 0) {
            $media = trim(substr($line, 6));
            $outdent = 0;
        } elseif (strpos($line, "\t") !== 0) {
            $media = '';
            $outdent = 0;
        } else {
            if ($outdent) {
                $line = substr($line, $outdent);
            }
            if (strpos($line, "\t@media ") === 0) {
                $outdent += 1;
                $line = substr($line, 1);
                if ($media) {
                    $line .= 'and (' . $media . ')';
                }
            }
        }

    }
    unset($line);

    // apply a default "@media all" to things that are not in a media query
    $indent = false;
    foreach ($_css as &$line) {
        if (strpos($line, '@media ') === 0) {
            $indent = false;
        } elseif ($indent) {
            $line = "\t" . $line;
        } elseif (strpos($line, "\t") !== 0) {
            $indent = true;
            $line = "@media all\n\t" . $line;
        }
    }
    unset($line);
    $_css = explode("\n", implode("\n", $_css));

    // remove @media
    foreach ($_css as &$line) {
        if (strpos($line, '@media ') === 0) {
            $line = trim(substr($line, 6));
        }
    }
    unset($line);
    
    // break declarations onto their own line
    foreach ($_css as &$line) {
        if (!preg_match("/^\t\t[^\@]/", $line)) continue;
        $line = trim($line);
        $line = csstoolsexp_tokenize_exec($line, ';', function ($l) {
            return array_filter(array_map('trim', explode(';', $l)));
        });
        $line = "\t\t" .  implode("\n\t\t", $line);
    }
    unset($line);
    $_css = implode("\n", $_css);
    $_css = preg_replace("/\n\s*\n/", "\n", $_css);
    return $_css;

}

/**
 * Convert a css string to a css array
 *
 * @param string $css the css string
 *
 * @return array
 */
function csstoolsexp_css_to_array($css)
{
    $pythonified_css = csstoolsexp_css_to_pythonified($css);
    return csstoolsexp_pythonified_to_array($pythonified_css);
}

/**
 * Take "pythonified" css from csstoolsexp_css_to_pythonified and convert it to an
 * array.  
 *
 * @param string $pythonified_css
 *
 * @return array
 */
function csstoolsexp_pythonified_to_array($pythonified_css)
{
    $arr1 = array();
    $l1 = $l2 = '';
    foreach (explode("\n", $pythonified_css) as $line) {
        if (strpos($line, "\t\t") === 0) {
            $arr1[$l1][$l2][] = trim($line);
        } elseif (strpos($line, "\t") === 0) {
            $l2 = trim($line);
            $l2 = csstoolsexp_at_line_fix($l2);
            if (strpos($l2, "@") === 0) $l2 .= '-' . uniqid();
        } else {
            $l1 = trim($line);
        }
    }
    $arr2 = array();
    foreach (array_keys($arr1) as $l1) {
        $l1s = array_map('trim', explode(',', $l1));
        foreach (array_keys($arr1[$l1]) as $l2) {
            $l2s = array_map('trim', explode(',', $l2));
            $decl = $arr1[$l1][$l2];
            foreach ($l1s as $_l1) {
                foreach ($l2s as $_l2) {
                    if (empty($arr2[$_l1][$_l2])) {
                        $arr2[$_l1][$_l2] = array();
                    }
                    $arr2[$_l1][$_l2] = array_merge($arr2[$_l1][$_l2], $decl); 
                }
            }
        }
    }
    $css_array = array();
    foreach ($arr2 as $media => &$ruleset) {
        foreach ($ruleset as $selector => $declarations) {
            $css_array[$media][$selector] = array();
            foreach($declarations as $declaration) {
                csstoolsexp_parse_declaration(
                    $declaration,
                    $css_array[$media][$selector]
                );
            }
        }
    }
    return $css_array;
}

/**
 * Takes the pythonified css from csstoolsexp_css_to_pythonified and converts it back
 * to normal CSS
 *
 * @param string $pythonified_css
 *
 * @return string
 */
function csstoolsexp_pythonified_to_css($pythonified_css, $pretty = false)
{
    $css_array = csstoolsexp_pythonified_to_array($pythonified_css);
    return csstoolsexp_array_to_css($css_array, $pretty);
}

/**
 * Convert a css array to a css string
 *
 * @param array $css_array
 *
 * @return string
 */
function csstoolsexp_array_to_css($css_array, $pretty = false)
{
    $sel_sep    = $pretty  ? ",\n\t"   : ','       ;
    $decl_open  = $pretty ? " {\n\t\t" : " {"      ;
    $decl_close = $pretty ? "\n\t}\n\t": "} "      ;
    $mq_pre     = $pretty ? "\n@media ": ' @media ';
    $mq_sep     = $pretty ? ",\n"      : ','       ;
    $mq_open    = $pretty ? " {\n\t"   : " {"      ;
    $mq_close   = $pretty ? "\n}\n"    : "} "      ;
    $mq_none    = $pretty ? "\t"       : ""        ;

    $css = '';
    foreach ($css_array as $mq => $rulesets) {
        $is_all = $mq == 'all';
        $css .= $is_all ? $mq_none : $mq_pre . $mq . $mq_open;
        foreach ($rulesets as $selector => $declarations) {
            $selector = csstoolsexp_at_line_fix($selector, true);
            $declarations = csstoolsexp_declarations_join($declarations, $pretty);
            $css .= $selector . $decl_open . $declarations . $decl_close;
        }
        if (!$is_all) {
            $css .= $mq_close;
        }
    }
    return $css;
}

/**
 * Convert a css array to a pythonified syntax
 *
 * @param array $css_array
 *
 * @return string
 */
function csstoolsexp_array_to_pythonified($css_array)
{
    $css_pythonified = '';
    foreach ($css_array as $media_query => $ruleset) {
        $css_pythonified .= "\n" . $media_query;
        foreach ($ruleset as $selector => $declarations) {
            $css_pythonfied .= "\n\t" . $selector;
            foreach ($declarations as $property => $value) {
                $css_pythonified .= "\n\t\t" . $property . ': ' . $value;
            }        
        }
    }
    return trim($css_pythonified);
}


//---------------------------------------------------------------------------//
//-- Private methods                                                       --//
//---------------------------------------------------------------------------//

/**
 * @font-face and @import declarations are a bit hard to work with. This
 * function will remove them and replace them with placeholders, or put the
 * originals back in.
 *
 * @private
 *
 * @param string $line line in the css, will be treated if it starts with an @
 * that isn't @media
 * @param bool $unfix if false, will remove the string and return a placeholder
 * if true, will
 *
 * @return either the original line if it's not affected, or placeholder if it
 * is, or the original string if $unfix is true
 */
function csstoolsexp_at_line_fix($line, $unfix = false)
{
    static $placeholders = array();
    if (strpos($line, '@') !== 0 || strpos($line, '@media' === 0)) {
        return $line;
    }
    if ($unfix) {
        if (isset($placeholders[$line])) {
            $original = $placeholders[$line];
            unset($placeholders[$line]);
            return $original;
        }
        return $line;
    }
    // prevent double encode
    if (isset($placholders[$line])) {
        return $line;
    }
    $placeholder = '@' . uniqid();
    $placeholders[$placeholder] = $line;
    return $placeholder;
}

/**
 * Try to get the best URL in a css file, useful for rebasing or urls or
 * inlining imports/images
 *
 * @param string $url the potentially relative url
 * @param string $base_url the base URL for the css string
 *
 * @return string the url if it could be calculated, or false
 */
function csstoolsexp_best_url($url, $base_url)
{
    $_url = router_rebase_url($url, $base_url);
    return $_url == $url ? false : $_url;
}


/**
 * A lot of the tools in this module rely on text manipulations to
 * modify CSS.  This can be a problem for things that css interprets
 * as literal strings.  Run the function inside this to ensure that
 * the literals are  preserved
 *
 * @private
 *
 * @param string $string the string you'll be acting on
 * @param string $replace the character to replace
 * @param callable $callback the function to manipulate on the string,
 * will receive the "tokenized" text as input and should return either
 * a string or array of strings.
 *
 * @return mixed the result of $callback
 */
function csstoolsexp_tokenize_exec($str, $replace, callable $callback)
{
    $regexes = array('/(")([^"]*)(")/', "/(')([^']*)(')/", '/(\()([^\)]*)(\))/');
    foreach ($regexes as $regex) {
        $str = preg_replace_callback($regex, function ($m) use ($replace) {
            return $m[1] . str_replace($replace, '°', $m[2]) . $m[3];
        }, $str);
    }
    $out = call_user_func($callback, $str);
    $untokenize = function ($str) use ($replace) {
        return str_replace('°', $replace, $str);
    };
    return is_array($out) ? array_map($untokenize, $out) : $untokenize($out);
}


/**
 * Parse individual "property:value;" declarations in a css string. Things that
 * can be split into more precise declarations will be. For example, "padding"
 * will be split into "padding-top", "padding-right" ... while "list-style"
 * will be split into "list-style-type", "list-style-image"...
 *
 * @param string $declaration the individual declaration
 * @param array &$declarations the variable that the parsed declarations are
 * being aggregated in
 *
 * @return void
 */
function csstoolsexp_parse_declaration($declaration, &$declarations)
{
    if (!strpos($declaration, ':')) return;
    $declaration = preg_replace('/\!important$/', '', $declaration);
    list($property, $value) = array_map('trim', explode(':', $declaration, 2));
    switch ($property) {
        case 'border':
        case 'border-top':
        case 'border-left':
        case 'border-right':
        case 'outline':
            $declaration = csstoolsexp_parse_property_border($property, $value);
            break;
        case 'margin':
        case 'padding':
        case 'border-width':
        case 'border-style':
        case 'border-color':
            $declaration = csstoolsexp_parse_property_box_side($property, $value);
            break;
        case 'list-style':
            $declaration = csstoolsexp_parse_property_list_style($value);
            break;
        case 'background':
            $declaration = csstoolsexp_parse_property_background($value);
            break;
        case 'font':
            $declaration = csstoolsexp_parse_property_font($value);
            break;
        default:
            $declaration = array($property => $value);
    }
    $declarations = array_merge($declarations, $declaration);
}

/**
 * The opposite of csstoolsexp_parse_declaration
 *
 * @private
 * 
 * @param array
 *
 * @return string
 */
function csstoolsexp_declarations_join($declarations, $pretty = false)
{

    foreach (['border-width', 'border-style', 'border-color'] as $property) {
        csstoolsexp_join_property_box_side($property, $declarations);
    }
    csstoolsexp_join_property_border($declarations);
    csstoolsexp_join_property_list_style($declarations);
    csstoolsexp_join_property_background($declarations);
    csstoolsexp_join_property_font($declarations);
    foreach (['margin', 'padding', 'border'] as $property) {
        csstoolsexp_join_property_box_side($property, $declarations);
    }

    $decl_sep = $pretty ? ' : ' : ':';
    $decl_after = $pretty ? ";\n\t\t" : ';';

    $ret = array();
    foreach ($declarations as $property => $value) {
        $ret[] = $property . $decl_sep . $value;
    }
    $ret = implode($decl_after, $ret);
    return $ret;
}


//-- Processing shorthand declarations for box sides -------------------------//

/**
 * Parse a box side declaration value (eg, "padding: 3px 4px")
 *
 * @private
 *
 * @param string $value the shorthand value
 *
 * @param array $value like csstoolsexp_parse_shorthand_property
 */
function csstoolsexp_parse_property_box_side($property, $value)
{
    list($top, $right, $bottom, $left) = csstoolsexp_box_side_properties($property);
    $tokens = csstoolsexp_tokenize_property($value);
    $ret = array();
    $ret[$top]    = array_shift($tokens);
    $ret[$right]  = empty($tokens) ? $ret[$top]   : array_shift($tokens);
    $ret[$bottom] = empty($tokens) ? $ret[$top]   : array_shift($tokens);
    $ret[$left]   = empty($tokens) ? $ret[$right] : array_shift($tokens);
    return $ret;
}

/**
 * Search for *-top, *-right, *-bottom and *-left declarations and joint them in
 * a single shorthand
 *
 * @private
 *
 * @param string $property @see csstoolsexp_box_side_properties()
 * @param array &$declarations @see csstoolsexp_join_shorthand_property()
 */
function csstoolsexp_join_property_box_side($property, &$declarations)
{
    $sides = csstoolsexp_box_side_properties($property);
    foreach ($sides as $side) {
        if (!isset($declarations[$side])) {
            return;
        }
    }
    $value = $declarations[$sides[0]];
    //$push = array();
    foreach ($sides as $side) {
        // if ($declarations[$side] != $value) {
        //    $push[$side] = $declarations[$side];
        //}
        if ($declarations[$side] == $value) {
            unset($declarations[$side]);
        }
    }
    $declarations = [$property => $value] + $declarations;
}



//-- Processing border shorthand declarations --------------------------------//

/**
 * Parse a "border" shorthand declaration value
 *
 * @private
 *
 * @param string $value @see csstoolsexp_parse_shorthand_property
 *
 * @param array $value @see csstoolsexp_parse_shorthand_property
 */
function csstoolsexp_parse_property_border($property, $value)
{
    if ($property == 'border') {
        return array_merge(
            csstoolsexp_parse_property_border('border-top', $value),
            csstoolsexp_parse_property_border('border-left', $value),
            csstoolsexp_parse_property_border('border-bottom', $value),
            csstoolsexp_parse_property_border('border-right', $value)
        );
    }
    return csstoolsexp_parse_shorthand_property(
        $value,
        array(
            $property . '-width' => ['/length'],
            $property . '-style' => [
                'none', 'hidden', 'dotted', 'dashed', 'solid', 'double',
                'groove', 'ridge', 'inset', 'outset',
            ],
            $property . '-color' => ['/color'],
        )
    );
}

/**
 * Search for border-* declarations and joint them in a single shorthand
 *
 * @private
 *
 * @param array &$declarations @see csstoolsexp_join_shorthand_property(
 */
function csstoolsexp_join_property_border(&$declarations)
{
    $sides = csstoolsexp_box_side_properties('border');
    array_unshift($sides, 'border');
    foreach ($sides as $side) {
        $properties = array($side . '-width', $side . '-style', $side . '-color');
        csstoolsexp_join_shorthand_property($declarations, $side, $properties);
    }
}


//-- Processing list-style shorthand declarations ----------------------------//

/**
 * Parse a "list-style" declaration value
 *
 * @private
 *
 * @param string $value @see csstoolsexp_parse_shorthand_property
 *
 * @param array $value @see csstoolsexp_parse_shorthand_property
 */
function csstoolsexp_parse_property_list_style($value)
{
    return csstoolsexp_parse_shorthand_property(
        $value,
        array(
            'list-style-type' => [
                'disc', 'armenian', 'circle', 'cjk-ideographic', 'decimal',
                'decimal-leading-zero', 'georgian', 'hebrew', 'hiragana',
                'hiragana-iroha', 'katakana', 'katakana-iroha', 'lower-alpha',
                'lower-greek', 'lower-latin', 'lower-roman', 'none', 'square',
                'upper-alpha', 'uppar-latin', 'upper-roman',
            ],
            'list-style-position' => ['inside', 'outside'],
            'list-style-url'      => ['url']
        )
    );
}

/**
 * Search for list-style-* declarations and joint them in a single shorthand
 *
 * @private
 *
 * @param array &$declarations @see csstoolsexp_join_shorthand_property(
 */
function csstoolsexp_join_property_list_style(&$declarations)
{
    $properties = array('list-style-type', 'list-style-position', 'list-style-image');
    csstoolsexp_join_shorthand_property($declarations, 'list-style', $properties);
}


//-- Processing background shorthand declarations ----------------------------//

/**
 * Parse a "background" shorthand declaration value
 *
 * @private
 *
 * @param string $value @see csstoolsexp_parse_shorthand_property
 *
 * @param array $value @see csstoolsexp_parse_shorthand_property
 */
function csstoolsexp_parse_property_background($value)
{
    return csstoolsexp_parse_shorthand_property(
        $value,
        array(
            'background-color'      => ['/color'],
            'background-image'      => ['/url'],
            'background-repeat'     => ['repeat','repeat-x','repeat-y','no-repeat'],
            'background-attachment' => ['fixed', 'scroll'],
            'background-position-y' => ['top', 'center', 'bottom', '/length'],
            'background-position-x' => ['left', 'center', 'right', '/length'],
        )
    );
}

/**
 * Search for background-* declarations and joint them in a single shorthand
 *
 * @private
 *
 * @param array &$declarations @see csstoolsexp_join_shorthand_property(
 */
function csstoolsexp_join_property_background(&$declarations)
{
    $properties = array(
        'background-color','background-image', 'background-repeat',
        'background-attachment', 'background-position-y',
        'background-position-x',
    );
    csstoolsexp_join_shorthand_property($declarations, 'background', $properties);
}


//-- Processing font shorthand declarations ----------------------------------//

/**
 * Parse a "font" declaration value
 *
 * @private
 *
 * @param string $value @see csstoolsexp_parse_shorthand_property
 *
 * @param array $value @see csstoolsexp_parse_shorthand_property
 */
function csstoolsexp_parse_property_font($value)
{
    $standards = array('caption', 'icon', 'menu', 'message-box', 'small-caption');
    if (in_array($value, $standards)) {
        return array('font' => $value);
    }

    return csstoolsexp_parse_shorthand_property(
        str_replace('/', ' ', $value), // for line-height
        array(
            'font-style'  => ['italic', 'oblique', 'normal'],

            'font-variant'=> ['small-caps'],

            'font-weight' => ['bold', 'lighter', 'bolder', 'normal', '100',
                              '200', '300', '400', '500', '600', '700', '800',
                              '900'],

            'font-size'   => ['xx-small', 'x-small', 'small', 'medium', 'large',
                              'x-large', 'xx-large', 'larger', 'smaller',
                              '/length'],

            'line-height' => ['/number'],

            'font-family' => ['/*'],
        )
    );
}

/**
 * Search for font-* declarations and joint them in a single shorthand
 *
 * @private
 *
 * @param array &$declarations @see csstoolsexp_join_shorthand_property(
 */
function csstoolsexp_join_property_font(&$declarations)
{
    $properties = array('font-style', 'font-weight', 'font-size', 'font-family');
    csstoolsexp_join_shorthand_property($declarations, 'font', $properties);
}


//-- Generics for parsing/joining shorthand property values ------------------//

/**
 * Parse a property value
 *
 * @private
 *
 * @param string $value the value to parse
 *
 * @return array the parsed properties
 */
function csstoolsexp_parse_shorthand_property($value, $searches)
{
    $tokens = csstoolsexp_tokenize_property($value);
    $return = array();
    foreach ($searches as $property => $search) {
        if ($value = csstoolsexp_extract_property_token($search, $tokens)) {
            $return[$property] = $value;
        }
    }
    return $return;
}

/**
 * Join a set of declarations together under a single property
 *
 * @private
 *
 * @param array $declarations all declarations for the ruleset
 * @param string $property the property to join into
 * @param array the properties to join, in order
 */
function csstoolsexp_join_shorthand_property(&$declarations, $property, $properties)
{
    if (isset($declarations[$property])) {
        return;
    }
    $declaration = array();
    foreach ($properties as $prop) {
        if (isset($declarations[$prop])) {
            $declaration[] = $declarations[$prop];
        }
    }
    if (count($declaration) < 2) {
        return;
    }
    foreach ($properties as $prop) {
        if (isset($declarations[$prop])) {
            unset($declarations[$prop]);
        }
    }
    if (!empty($declaration)) {
        $declarations = [$property => implode(' ', $declaration)] + $declarations;
    }
}


//-- Utilities ---------------------------------------------------------------//

/**
 * Tokenize a property value for parsing shorthand
 *
 * @private
 *
 * @param string $value
 *
 * @return array the tokens
 */
function csstoolsexp_tokenize_property($value)
{
    $value = preg_replace('/\s+/', ' ', $value);
    $preserve_spaces = array(
        '/"[^"]*"/',    // double quoted value, eg: "Nimbus Mono"
        "/'[^']*'/",    // single quoted value eg: 'Nimbus Mono'
        '/\([^\)]*\)/', // value in parantheses, eg: url(../my folder/my-file.jpg)
        '/,\s+/',       // comma-separated list, eg: Arial, sans-serif
    );
    foreach ($preserve_spaces as $ps) {
        $value = preg_replace_callback($ps, function ($m) {
            return str_replace(' ', "°", $m[0]);
        }, $value);
    }
    $value = array_map(function($p) {
        return str_replace('°', ' ', $p);
    }, explode(' ', $value));
    return $value;
}

/**
 * Check if the first token matches a pattern, and return it if so, shifting it
 * of the complete input chain
 *
 * @private
 *
 * @param array $search what to search for. Values are: '/*': anything;
 * '/number': a numeric value; '/length': a CSSUnit length (eg, 4px or 3%);
 * '/url' a url in format "url(path)"; '/color' a color, either hex, rgb, rgba,
 * or color name; (string) an exact match.
 *
 * @return null if no match, the token if it matches
 */
function csstoolsexp_extract_property_token($search, &$tokens)
{
    $check = function ($test, $tok) {
        if (in_array($tok, ['initial', 'inherit', $test])) {
            return true;
        }
        switch ($test) {
            case '/*':
                return true;
            case '/number':
                return is_numeric($tok);
            case '/length': 
                $regex = '/^0|([\d\.]+)(px|\%|cm|em|ex|in|mm|pc|pt|px|vh|vw|vmin)$/i';
                return preg_match($regex, $tok);
            case '/url':
                $regex = '/^url\(.*\)$/i';
                return preg_match($regex, $tok);
            case '/color':
                if (in_array(strtolower($tok), array_keys(csstoolsexp_color_names()))) {
                    return true;
                }
                $regex = '/^(#[A-F0-9]{3,6}|rgba?\([^\)]+\))$/i';
                return preg_match($regex, $tok);
        }
        return false;
    };
    if (empty($tokens)) {
        return null;
    }
    foreach ($search as $s) {
        if ($check($s, $tokens[0])) {
            return array_shift($tokens);
        }
    }
    return null;
}


/**
 * Takes the box-sidable property name to use:
 *
 *     list($t, $r, $b, $l) = csstoolsexp_box_side_properties('border-width');
 *     // $t = 'border-top-width';
 *     // $r = 'border-right-width';
 *     // $b = 'border-bottom-width';
 *     // $l = 'border-left-width';
 *
 * @private
 * 
 * @param string $property
 * 
 * @return array
 */
function csstoolsexp_box_side_properties($property)
{
    if (preg_match('/^(.*)\-(width|style|color)$/', $property, $m)) {
        $p = $m[1];
        $s = '-' . $m[2];
    } else {
        $p = $property;
        $s = '';
    }
    
    return array(
        $p . '-top'    . $s,
        $p . '-right'  . $s,
        $p . '-bottom' . $s,
        $p . '-left'   . $s,
    );
}


/**
 * Get the standard HTML color names, as a map where the key is the html color
 * name and the value is it's hex equivalent.  It doesn't get much uglier than
 * this, but it works.
 *
 * @private
 *
 * @return array
 */
function csstoolsexp_color_names()
{
    return array(
    'indianred'             => '#CD5C5C', 'lightcoral'            => '#F08080',
    'salmon'                => '#FA8072', 'darksalmon'            => '#E9967A',
    'lightsalmon'           => '#FFA07A', 'crimson'               => '#DC143C',
    'red'                   => '#FF0000', 'firebrick'             => '#B22222',
    'darkred'               => '#8B0000', 'pink'                  => '#FFC0CB',
    'lightpink'             => '#FFB6C1', 'hotpink'               => '#FF69B4',
    'deeppink'              => '#FF1493', 'mediumvioletred'       => '#C71585',
    'palevioletred'         => '#DB7093', 'lightsalmon'           => '#FFA07A',
    'coral'                 => '#FF7F50', 'tomato'                => '#FF6347',
    'orangered'             => '#FF4500', 'darkorange'            => '#FF8C00',
    'orange'                => '#FFA500', 'gold'                  => '#FFD700',
    'yellow'                => '#FFFF00', 'lightyellow'           => '#FFFFE0',
    'lemonchiffon'          => '#FFFACD', 'lightgoldenrodyellow'  => '#FAFAD2',
    'papayawhip'            => '#FFEFD5', 'moccasin'              => '#FFE4B5',
    'peachpuff'             => '#FFDAB9', 'palegoldenrod'         => '#EEE8AA',
    'khaki'                 => '#F0E68C', 'darkkhaki'             => '#BDB76B',
    'lavender'              => '#E6E6FA', 'thistle'               => '#D8BFD8',
    'plum'                  => '#DDA0DD', 'violet'                => '#EE82EE',
    'orchid'                => '#DA70D6', 'fuchsia'               => '#FF00FF',
    'magenta'               => '#FF00FF', 'mediumorchid'          => '#BA55D3',
    'mediumpurple'          => '#9370DB', 'blueviolet'            => '#8A2BE2',
    'darkviolet'            => '#9400D3', 'darkorchid'            => '#9932CC',
    'darkmagenta'           => '#8B008B', 'purple'                => '#800080',
    'indigo'                => '#4B0082', 'slateblue'             => '#6A5ACD',
    'darkslateblue'         => '#483D8B', 'mediumslateblue'       => '#7B68EE',
    'greenyellow'           => '#ADFF2F', 'chartreuse'            => '#7FFF00',
    'lawngreen'             => '#7CFC00', 'lime'                  => '#00FF00',
    'limegreen'             => '#32CD32', 'palegreen'             => '#98FB98',
    'lightgreen'            => '#90EE90', 'mediumspringgreen'     => '#00FA9A',
    'springgreen'           => '#00FF7F', 'mediumseagreen'        => '#3CB371',
    'seagreen'              => '#2E8B57', 'forestgreen'           => '#228B22',
    'green'                 => '#008000', 'darkgreen'             => '#006400',
    'yellowgreen'           => '#9ACD32', 'olivedrab'             => '#6B8E23',
    'olive'                 => '#808000', 'darkolivegreen'        => '#556B2F',
    'mediumaquamarine'      => '#66CDAA', 'darkseagreen'          => '#8FBC8F',
    'lightseagreen'         => '#20B2AA', 'darkcyan'              => '#008B8B',
    'teal'                  => '#008080', 'aqua'                  => '#00FFFF',
    'cyan'                  => '#00FFFF', 'lightcyan'             => '#E0FFFF',
    'paleturquoise'         => '#AFEEEE', 'aquamarine'            => '#7FFFD4',
    'turquoise'             => '#40E0D0', 'mediumturquoise'       => '#48D1CC',
    'darkturquoise'         => '#00CED1', 'cadetblue'             => '#5F9EA0',
    'steelblue'             => '#4682B4', 'lightsteelblue'        => '#B0C4DE',
    'powderblue'            => '#B0E0E6', 'lightblue'             => '#ADD8E6',
    'skyblue'               => '#87CEEB', 'lightskyblue'          => '#87CEFA',
    'deepskyblue'           => '#00BFFF', 'dodgerblue'            => '#1E90FF',
    'cornflowerblue'        => '#6495ED', 'mediumslateblue'       => '#7B68EE',
    'royalblue'             => '#4169E1', 'blue'                  => '#0000FF',
    'mediumblue'            => '#0000CD', 'darkblue'              => '#00008B',
    'navy'                  => '#000080', 'midnightblue'          => '#191970',
    'cornsilk'              => '#FFF8DC', 'blanchedalmond'        => '#FFEBCD',
    'bisque'                => '#FFE4C4', 'navajowhite'           => '#FFDEAD',
    'wheat'                 => '#F5DEB3', 'burlywood'             => '#DEB887',
    'tan'                   => '#D2B48C', 'rosybrown'             => '#BC8F8F',
    'sandybrown'            => '#F4A460', 'goldenrod'             => '#DAA520',
    'darkgoldenrod'         => '#B8860B', 'peru'                  => '#CD853F',
    'chocolate'             => '#D2691E', 'saddlebrown'           => '#8B4513',
    'sienna'                => '#A0522D', 'brown'                 => '#A52A2A',
    'maroon'                => '#800000', 'white'                 => '#FFFFFF',
    'snow'                  => '#FFFAFA', 'honeydew'              => '#F0FFF0',
    'mintcream'             => '#F5FFFA', 'azure'                 => '#F0FFFF',
    'aliceblue'             => '#F0F8FF', 'ghostwhite'            => '#F8F8FF',
    'whitesmoke'            => '#F5F5F5', 'seashell'              => '#FFF5EE',
    'beige'                 => '#F5F5DC', 'oldlace'               => '#FDF5E6',
    'floralwhite'           => '#FFFAF0', 'ivory'                 => '#FFFFF0',
    'antiquewhite'          => '#FAEBD7', 'linen'                 => '#FAF0E6',
    'lavenderblush'         => '#FFF0F5', 'mistyrose'             => '#FFE4E1',
    'gainsboro'             => '#DCDCDC', 'lightgrey'             => '#D3D3D3',
    'silver'                => '#C0C0C0', 'darkgray'              => '#A9A9A9',
    'gray'                  => '#808080', 'dimgray'               => '#696969',
    'lightslategray'        => '#778899', 'slategray'             => '#708090',
    'darkslategray'         => '#2F4F4F', 'black'                 => '#000000',
    );
}

