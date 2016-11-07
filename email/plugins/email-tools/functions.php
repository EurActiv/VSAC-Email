<?php

namespace VSAC;

//----------------------------------------------------------------------------//
//-- Framework functions                                                    --//
//----------------------------------------------------------------------------//

/** @see plugins/example-plugin/example_plugin_config_items() */
function email_tools_config_items()
{
    return array(
        ['email_tools_test_address', '', 'Send test emails here', true],
        ['email_tools_process_limit', 0, 'Number of seconds the processing service should run before shutting down'],
        ['email_tools_pull_urls', [], 'URLs to pull emails from', true],
    );
}

/** @see plugins/example-plugin/example_plugin_bootstrap() */
function email_tools_bootstrap()
{
    use_module('image');
    use_module('csstoolsexp');
    use_module('domtools');
    use_module('apikey');
    use_module('emailer');
    use_module('log');
}

//----------------------------------------------------------------------------//
//-- Most of the controllers in this applet will return a json response     --//
//-- with the format:                                                       --//
//--   [data]     (hash table) the data from the processing if successful   --//
//--   [error]    (string) if there was a fatal error, the message          --//
//--   [warnings] (array) non-fatal errors while processing the request     --//
//--   [messages] (array) debugging messages                                --//
//----------------------------------------------------------------------------//

/**
 * A collector for the response
 *
 * @return 
 */
function &email_tools_response()
{
    static $response = array(
        'data'            => array(),
        'error'           => '',
        'warnings'        => array(),
        'messages'        => array(),
    );
    return $response;
}

function email_tools_response_data($offset, $value)
{
    $response = &email_tools_response();
    $response['data'][$offset] = $value;
}

/**
 * A formatter formatting messages.
 *
 * @private
 *
 * @param array[string] $msg the message to format, first offset is the message,
 * followed by any arguments for sprintf
 *
 * @return 
 */
function email_tools_msg($msg)
{
    if (count($msg) == 0) {
        $msg = 'Unknown';
    } elseif (count($msg) == 1) {
        $msg = $msg[0];
    } else {
        $msg = call_user_func_array('sprintf', $msg);
    }
    return preg_replace('/\s+/', ' ', $msg);
}

/**
 * A fatal error was encountered.  Log it in the response and send it (stops
 * script execution)
 *
 * @param optional string $msg any message to log
 * @param optional string $args... any strings to interpolate in $msg via sprintf
 *
 * @return void
 */
function email_tools_fatal_error()
{
    $response = email_tools_response();
    $response['error'] = email_tools_msg(func_get_args());
    response_send_json($response);
}

/**
 * Log a warning in the json response
 *
 * @param optional string $msg any message to log
 * @param optional string $args... any strings to interpolate in $msg via sprintf
 *
 * @return void
 */
function email_tools_warn()
{
    $response = &email_tools_response();
    $msg = email_tools_msg(func_get_args());
    if (!in_array($msg, $response['warnings'])) {
        $response['warnings'][] = $msg;
    }
}

/**
 * Log a debug message in the json response
 *
 * @param optional string $msg any message to log
 * @param optional string $args... any strings to interpolate in $msg via sprintf
 *
 * @return void
 */
function email_tools_debug()
{
    $response = &email_tools_response();
    $msg = email_tools_msg(func_get_args());
    if (!in_array($msg, $response['messages'])) {
        $response['messages'][] = $msg;
    }
}

/**
 * When rebasing a URLs in a document
 *
 * @return 
 */
function email_tools_check_url($url, $base_url = false)
{
    $_url = router_rebase_url($url, $base_url);
    if ($url == $_url) {
        return false;
    }
    return filter_var($_url, FILTER_VALIDATE_URL) ? $_url : false;
}

/**
 *
 *
 * @return 
 */
function email_tools_get($var, $base_url = false)
{
    $response = http_get($var);
    if ($response['error']) {
        email_tools_warn(
            'Invalid response for %s: %s',
            $var,
            $response['error']
        );
    }
    return $response['body'];        
}


/**
 *
 *
 * @return 
 */
function email_tools_get_html($url, $what)
{
    $html = email_tools_get($url);
    if (empty($html)) {
        email_tools_fatal_error('getting %s failed', $what);
    }
    return $html;
}







